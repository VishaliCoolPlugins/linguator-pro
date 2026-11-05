<?php
/**
 * Frontend Untranslated Inspector: admin bar toggle and side panel.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows an administrator which text on a translated page is still in the original language.
 *
 * The panel opens on a reload with ?lmat-untranslated=1. The rows are built on that request and printed into the
 * page for administrators only. The inspector is read-only; translations are edited in their editor.
 *
 * @since 2.3.0
 */
class Frontend_Inspector {

	const QUERY_ARG = 'lmat-untranslated';
	const HANDLE    = 'lmat-frontend-inspector';

	/**
	 * Elementor edit-link argument naming the widget to select in the editor.
	 */
	const FOCUS_ARG    = 'lmat_element';
	const FOCUS_HANDLE = 'lmat-elementor-focus';

	/**
	 * Block editor link argument holding the start of the text to select.
	 */
	const FOCUS_TEXT_ARG     = 'lmat_text';
	const BLOCK_FOCUS_HANDLE = 'lmat-block-editor-focus';

	/**
	 * Collector result for the current request.
	 *
	 * @var array|null
	 */
	private static $result = null;

	/**
	 * Elementor documents rendered on this request (header, footer, templates).
	 *
	 * @var int[]
	 */
	private static $documents = array();

	/**
	 * Hooks the inspector.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_item' ), 100 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_boot' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'enqueue_editor_focus' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_focus' ) );
	}

	/**
	 * In the block editor, selects the text named by the edit link.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function enqueue_block_editor_focus(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI hint; only loaded for administrators.
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET[ self::FOCUS_TEXT_ARG ] ) ) {
			return;
		}

		wp_enqueue_script(
			self::BLOCK_FOCUS_HANDLE,
			plugins_url( 'admin/assets/js/lmat-block-editor-focus.js', LINGUATOR_PRO_ROOT_FILE ),
			array( 'wp-data', 'wp-dom-ready', 'wp-block-editor' ),
			self::version( 'admin/assets/js/lmat-block-editor-focus.js' ),
			true
		);
	}

	/**
	 * In the Elementor editor, selects the widget named by the edit link.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function enqueue_editor_focus(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI hint; only loaded for administrators.
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET[ self::FOCUS_ARG ] ) ) {
			return;
		}

		wp_enqueue_script(
			self::FOCUS_HANDLE,
			plugins_url( 'admin/assets/js/lmat-elementor-focus.js', LINGUATOR_PRO_ROOT_FILE ),
			array( 'elementor-editor' ),
			self::version( 'admin/assets/js/lmat-elementor-focus.js' ),
			true
		);
	}

	/**
	 * Whether the current user may use the inspector on this request.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	private static function can_inspect(): bool {
		return ! is_admin() && is_singular() && current_user_can( 'manage_options' ) && function_exists( 'LMAT' );
	}

	/**
	 * Whether the panel was asked for on this request.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	private static function is_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view toggle, gated by manage_options.
		return isset( $_GET[ self::QUERY_ARG ] ) && '1' === sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) );
	}

	/**
	 * Admin bar item that opens or closes the panel.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar.
	 * @return void
	 */
	public static function add_admin_bar_item( $admin_bar ): void {
		if ( ! $admin_bar instanceof \WP_Admin_Bar || ! self::can_inspect() ) {
			return;
		}

		$open = self::is_requested();

		$admin_bar->add_node(
			array(
				'id'    => self::HANDLE,
				'title' => esc_html__( 'Untranslated Inspector', 'linguator-pro' ),
				'href'  => $open ? remove_query_arg( self::QUERY_ARG ) : add_query_arg( self::QUERY_ARG, '1' ),
				'meta'  => array(
					'title' => $open
						? esc_attr__( 'Close the Untranslated Inspector', 'linguator-pro' )
						: esc_attr__( 'Show text on this page that is still in the original language', 'linguator-pro' ),
				),
			)
		);
	}

	/**
	 * Builds the rows and queues the panel when it was asked for.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function maybe_boot(): void {
		if ( ! self::is_requested() || ! self::can_inspect() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		nocache_headers();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		self::$result = Untranslated_Collector::for_post( (int) get_queried_object_id() );

		add_action( 'elementor/frontend/get_builder_content', array( __CLASS__, 'record_document' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// Before wp_print_footer_scripts (20), after the header and footer have rendered.
		add_action( 'wp_footer', array( __CLASS__, 'localize_result' ), 5 );
	}

	/**
	 * Remembers an Elementor document rendered on this page.
	 *
	 * @since 2.3.0
	 *
	 * @param object $document Elementor document.
	 * @return void
	 */
	public static function record_document( $document ): void {
		if ( self::can_inspect() && is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
			self::$documents[] = (int) $document->get_main_id();
		}
	}

	/**
	 * Adds rows for rendered templates and hands everything to the script.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function localize_result(): void {
		if ( null === self::$result || ! self::can_inspect() ) {
			return;
		}

		if ( Untranslated_Collector::STATUS_OK === self::$result['status'] ) {
			$page_id = (int) get_queried_object_id();
			foreach ( array_unique( self::$documents ) as $document_id ) {
				if ( $document_id === $page_id ) {
					continue;
				}
				$template              = Untranslated_Collector::for_template( $document_id, self::$result['current_lang'], self::$result['source_lang'] );
				self::$result['rows']  = array_merge( self::$result['rows'], $template['rows'] );
				self::$result['total'] += $template['total'];
			}
		}

		wp_localize_script(
			self::HANDLE,
			'lmatFrontendInspector',
			array(
				'result'   => self::$result,
				'closeUrl' => esc_url_raw( remove_query_arg( self::QUERY_ARG ) ),
				'i18n'     => self::strings(),
			)
		);
	}

	/**
	 * Enqueues the panel assets.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( null === self::$result || ! self::can_inspect() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'admin/assets/css/lmat-frontend-inspector.css', LINGUATOR_PRO_ROOT_FILE ),
			array(),
			self::version( 'admin/assets/css/lmat-frontend-inspector.css' )
		);
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'admin/assets/js/lmat-frontend-inspector.js', LINGUATOR_PRO_ROOT_FILE ),
			array(),
			self::version( 'admin/assets/js/lmat-frontend-inspector.js' ),
			true
		);
	}

	/**
	 * Cache-busting version of an asset.
	 *
	 * @since 2.3.0
	 *
	 * @param string $relative Path inside the plugin.
	 * @return string
	 */
	private static function version( string $relative ): string {
		$file = LINGUATOR_PRO_DIR . '/' . $relative;

		return file_exists( $file ) ? (string) filemtime( $file ) : (string) LINGUATOR_PRO_VERSION;
	}

	/**
	 * Panel copy.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string,string>
	 */
	private static function strings(): array {
		return array(
			'title'             => __( 'Untranslated Inspector', 'linguator-pro' ),
			'brand'             => __( 'Linguator', 'linguator-pro' ),
			'close'             => __( 'Close', 'linguator-pro' ),
			'themeDark'         => __( 'Switch to dark mode', 'linguator-pro' ),
			'themeLight'        => __( 'Switch to light mode', 'linguator-pro' ),
			/* translators: 1: current language code, 2: source language code */
			'comparing'         => __( 'Comparing %1$s with the original (%2$s).', 'linguator-pro' ),
			'progressTitle'     => __( 'Translation progress', 'linguator-pro' ),
			/* translators: %d: number of untranslated strings */
			'remaining'         => __( '%d remaining', 'linguator-pro' ),
			/* translators: 1: translated strings, 2: compared strings, 3: percentage, e.g. 25% */
			'progressOf'        => __( '%1$d of %2$d translated (%3$s)', 'linguator-pro' ),
			'none'              => __( 'No untranslated text found on this page.', 'linguator-pro' ),
			'search'            => __( 'Search untranslated strings…', 'linguator-pro' ),
			'noMatches'         => __( 'No strings match your search.', 'linguator-pro' ),
			'groupElementor'    => __( 'Elementor widgets', 'linguator-pro' ),
			'groupTitle'        => __( 'Title', 'linguator-pro' ),
			'groupText'         => __( 'Content', 'linguator-pro' ),
			'edit'              => __( 'Edit translation', 'linguator-pro' ),
			'copy'              => __( 'Copy', 'linguator-pro' ),
			'copied'            => __( 'Copied', 'linguator-pro' ),
			'showHighlights'    => __( 'Show highlights on page', 'linguator-pro' ),
			'sameContent'       => __( 'This text matches the original-language content. Review it and translate it if it should be different in this language.', 'linguator-pro' ),
			'notFound'          => __( 'This text could not be located in the displayed page. Use Edit translation to review it in the page editor.', 'linguator-pro' ),
			'notFoundElementor' => __( 'This widget text could not be located in the displayed page. It may be in a closed popup or hidden by a display condition. Use Edit translation to review it in Elementor.', 'linguator-pro' ),
			'hiddenPopup'       => __( 'Inside a popup that is closed right now.', 'linguator-pro' ),
			'hiddenSlider'      => __( 'On a slide that is not showing right now.', 'linguator-pro' ),
			'hiddenTab'         => __( 'Inside a tab or accordion that is closed right now.', 'linguator-pro' ),
			'hiddenDevice'      => __( 'Hidden on this screen size. Use responsive mode or resize the window to see it.', 'linguator-pro' ),
			'hiddenOther'       => __( 'Hidden on the page right now, for example by CSS or a display condition.', 'linguator-pro' ),
			'reveal'            => __( 'Show it on the page', 'linguator-pro' ),
			'sourceLanguage'    => __( 'This page is in the original language, so there is nothing to compare.', 'linguator-pro' ),
			'noSource'          => __( 'This page has no original-language version to compare with.', 'linguator-pro' ),
			'sourceUnpublished' => __( 'The original-language version of this page is not published.', 'linguator-pro' ),
			'notTranslatable'   => __( 'This page is not managed by Linguator.', 'linguator-pro' ),
		);
	}
}
