<?php
/**
 * Translation status panel in the block editor sidebar.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the "Translation Status" sidebar panel for translated post types.
 *
 * @since 2.3.0
 */
class Editor_Panel {

	/**
	 * Hooks the panel.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_classic' ) );

		// Elementor saves post data in its own request: keep a copy of the source first and show the status in its panel.
		add_action( 'elementor/document/before_save', array( __CLASS__, 'capture_before_elementor_save' ), 10, 1 );
		add_action( 'elementor/documents/register_controls', array( __CLASS__, 'add_elementor_controls' ), 20, 1 );
	}

	/**
	 * Translation model, or null when Linguator is not ready.
	 *
	 * @since 2.3.0
	 *
	 * @return \LinguatorPro\Includes\Other\Linguator_Model|null
	 */
	private static function model() {
		return function_exists( 'LMATP' ) ? ( LMATP()->model ?? null ) : null;
	}

	/**
	 * Whether the panel applies to a post type.
	 *
	 * @since 2.3.0
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private static function tracks( string $post_type ): bool {
		$model = self::model();
		return $model && $model->has_languages() && in_array( $post_type, (array) $model->get_translated_post_types(), true );
	}

	/**
	 * Registers the Translation Status box for the classic editor.
	 *
	 * @since 2.3.0
	 *
	 * @param string       $post_type Post type.
	 * @param \WP_Post|null $post      Post.
	 * @return void
	 */
	public static function register_metabox( $post_type, $post = null ): void {
		if ( ! self::tracks( (string) $post_type ) ) {
			return;
		}

		// The block editor has its own sidebar panel.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		add_meta_box(
			'lmat_scan_translation_status',
			__( 'Translation Status', 'linguator-pro' ),
			array( __CLASS__, 'render_metabox' ),
			$post_type,
			'side',
			'high',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Container the classic script fills in.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function render_metabox(): void {
		echo '<div class="lmat-scan-metabox"><p class="description">' . esc_html__( 'Checking translation status…', 'linguator-pro' ) . '</p></div>';
	}

	/**
	 * Enqueues the classic editor script and styles.
	 *
	 * @since 2.3.0
	 *
	 * @param string $hook Admin screen hook.
	 * @return void
	 */
	public static function enqueue_classic( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( ! $screen || ! $post || ! self::tracks( (string) $screen->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		wp_enqueue_style( 'revisions' );
		wp_enqueue_style(
			'lmat-scan-editor-panel',
			plugins_url( 'admin/assets/css/scan-editor-panel.css', LINGUATOR_PRO_ROOT_FILE ),
			array( 'revisions' ),
			LINGUATOR_PRO_VERSION
		);

		wp_enqueue_script(
			'lmat-scan-classic-panel',
			plugins_url( 'admin/assets/js/scan-classic-panel.js', LINGUATOR_PRO_ROOT_FILE ),
			array( 'wp-api-fetch', 'wp-a11y' ),
			LINGUATOR_PRO_VERSION,
			true
		);

		wp_localize_script(
			'lmat-scan-classic-panel',
			'lmatScanEditor',
			array(
				'postId' => (int) $post->ID,
				'i18n'   => self::strings(),
			)
		);
	}

	/**
	 * Keeps a copy of the source before Elementor saves it.
	 *
	 * @since 2.3.0
	 *
	 * @param object $document Elementor document about to be saved.
	 * @return void
	 */
	public static function capture_before_elementor_save( $document ): void {
		if ( is_object( $document ) && is_callable( array( $document, 'get_main_id' ) ) ) {
			Freshness::capture_before_update( (int) $document->get_main_id() );
		}
	}

	/**
	 * Shows the translation status in the Elementor editor's page settings.
	 *
	 * @since 2.3.0
	 *
	 * @param object $document Elementor document.
	 * @return void
	 */
	public static function add_elementor_controls( $document ): void {
		$model = self::model();
		if ( ! $model || ! is_callable( array( $document, 'get_main_id' ) ) || ! class_exists( '\\Elementor\\Controls_Manager' ) ) {
			return;
		}

		$post_id = (int) $document->get_main_id();
		if ( $post_id <= 0 || ! self::tracks( (string) get_post_type( $post_id ) ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$status = ( new Freshness( $model ) )->editor_status( $post_id );
		if ( 'translation' !== $status['role'] && 'source' !== $status['role'] ) {
			return;
		}

		$rows = array();
		if ( 'source' === $status['role'] ) {
			$rows[] = '<p>' . esc_html__( 'Source (default language). When you change this page, its translations are marked as outdated.', 'linguator-pro' ) . '</p>';
			foreach ( (array) $status['translations'] as $t ) {
				$rows[] = '<p><strong>' . esc_html( $t['language_name'] ) . '</strong>: ' . esc_html( $t['status_label'] ) . '</p>';
			}
		} else {
			$rows[] = '<p><strong>' . esc_html( $status['status_label'] ) . '</strong></p>';
			if ( $status['notice'] ) {
				$rows[] = '<p>' . esc_html( $status['notice'] ) . '</p>';
			}
			foreach ( array_slice( (array) $status['changes'], 0, 5 ) as $change ) {
				$rows[] = '<p>• ' . esc_html( $change['label'] ) . '</p>';
			}
			if ( 'outdated' === $status['status'] ) {
				$rows[] = '<p><a href="' . esc_url( (string) get_edit_post_link( $post_id, 'url' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See what changed and mark as up to date', 'linguator-pro' ) . '</a></p>';
			}
		}

		$document->start_controls_section(
			'lmat_scan_translation_status',
			array(
				'label' => esc_html__( 'Translation Status', 'linguator-pro' ),
				'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
			)
		);
		$document->add_control(
			'lmat_scan_translation_status_info',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => implode( '', $rows ),
				'content_classes' => 'elementor-control-field-description',
			)
		);
		$document->end_controls_section();
	}

	/**
	 * Enqueues the panel script and styles on translated post types.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();

		if ( ! $screen || 'post' !== $screen->base || ! $post || ! function_exists( 'LMATP' ) ) {
			return;
		}

		$model = LMATP()->model ?? null;
		if ( ! $model || ! $model->has_languages() || ! in_array( $screen->post_type, (array) $model->get_translated_post_types(), true ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		// Core revisions styles give the diff table its red and green lines.
		wp_enqueue_style( 'revisions' );
		wp_enqueue_style(
			'lmat-scan-editor-panel',
			plugins_url( 'admin/assets/css/scan-editor-panel.css', LINGUATOR_PRO_ROOT_FILE ),
			array( 'revisions' ),
			LINGUATOR_PRO_VERSION
		);

		wp_enqueue_script(
			'lmat-scan-editor-panel',
			plugins_url( 'admin/assets/js/scan-editor-panel.js', LINGUATOR_PRO_ROOT_FILE ),
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-a11y', 'wp-api-fetch' ),
			LINGUATOR_PRO_VERSION,
			true
		);

		wp_localize_script(
			'lmat-scan-editor-panel',
			'lmatScanEditor',
			array(
				'postId' => (int) $post->ID,
				'i18n'   => self::strings(),
			)
		);
	}

	/**
	 * Strings used by the panel script.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string,string>
	 */
	public static function strings(): array {
		return array(
			'panelTitle'        => __( 'Translation Status', 'linguator-pro' ),
			'loading'           => __( 'Checking translation status…', 'linguator-pro' ),
			'sourceRole'        => __( 'Source (default language)', 'linguator-pro' ),
			'sourceRoleDesc'    => __( 'When you change this post, its translations are marked as outdated.', 'linguator-pro' ),
			'translationsLabel' => __( 'Translations:', 'linguator-pro' ),
			'editTranslation'   => __( 'Edit', 'linguator-pro' ),
			'addTranslation'    => __( '+ Add', 'linguator-pro' ),
			'sourceLabel'       => __( 'Source:', 'linguator-pro' ),
			'changedLabel'      => __( 'What changed in the source:', 'linguator-pro' ),
			'stillSameLabel'    => __( 'Still the same as the source:', 'linguator-pro' ),
			'seeChanges'        => __( 'See what changed', 'linguator-pro' ),
			'markSynced'        => __( 'Mark as up to date', 'linguator-pro' ),
			'markSyncedHint'    => __( 'Use this after you have reviewed the changes and updated the translation.', 'linguator-pro' ),
			'markingSynced'     => __( 'Updating…', 'linguator-pro' ),
			'markedSynced'      => __( 'Translation marked as up to date.', 'linguator-pro' ),
			'markError'         => __( 'The translation could not be updated.', 'linguator-pro' ),
			'loadError'         => __( 'Translation status could not be loaded.', 'linguator-pro' ),
			'diffModalTitle'    => __( 'What changed in the source', 'linguator-pro' ),
			'diffLoading'       => __( 'Loading changes…', 'linguator-pro' ),
			'diffError'         => __( 'The changes could not be loaded.', 'linguator-pro' ),
			'diffEmpty'         => __( 'No text differences were found.', 'linguator-pro' ),
			'partialNote'       => __( 'Only the title and body can be compared. There is no saved earlier copy of the page-builder text.', 'linguator-pro' ),
			'truncatedNote'     => __( 'The saved earlier version was long and has been cut short.', 'linguator-pro' ),
			'retry'             => __( 'Try again', 'linguator-pro' ),
			'sideBySide'        => __( 'Side by side', 'linguator-pro' ),
			'inline'            => __( 'Inline', 'linguator-pro' ),
			'openSource'        => __( 'Open source', 'linguator-pro' ),
			'close'             => __( 'Close', 'linguator-pro' ),
			'noLanguage'        => __( 'Set a language for this post to enable translation status tracking.', 'linguator-pro' ),
			'typeChanged'       => __( 'Changed', 'linguator-pro' ),
			'typeAdded'         => __( 'New', 'linguator-pro' ),
			'typeRemoved'       => __( 'Removed', 'linguator-pro' ),
			/* translators: %s: how long ago, e.g. "2 hours". */
			'sourceChanged'     => __( 'Source changed %s ago', 'linguator-pro' ),
			/* translators: %s: how long ago, e.g. "2 hours". */
			'comparedWith'      => __( 'Compared with the source as it was %s ago.', 'linguator-pro' ),
			/* translators: %d: number of further items that are not listed. */
			'moreItems'         => __( '+ %d more', 'linguator-pro' ),
		);
	}
}
