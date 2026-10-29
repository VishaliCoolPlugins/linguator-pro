<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Modules\REST\V1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Includes\Other\Linguator_Model;
use Linguator\Modules\REST\Abstract_Controller;
use LinguatorPro\Modules\Scan\Freshness;
use LinguatorPro\Modules\Scan\Issue_Copy;
use LinguatorPro\Modules\Scan\Scanner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for the site scan.
 *
 * @since 2.3.0
 */
class Scan extends Abstract_Controller {

	/**
	 * Linguator model.
	 *
	 * @var Linguator_Model
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Linguator_Model $model Linguator model.
	 */
	public function __construct( Linguator_Model $model ) {
		$this->model     = $model;
		$this->namespace = 'lmat/v1';
		$this->rest_base = 'scan';
	}

	/**
	 * Registers the routes.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_scan' ),
				'permission_callback' => array( $this, 'check_scan_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/ignore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ignore' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'key'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== $value && strlen( $value ) <= 200;
						},
					),
                    'keys' => array(
                        'type' => 'array',
                        'maxItems' => 10000,
                        'items' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
                    ),
					'ignore' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/section',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_section' ),
				'permission_callback' => array( $this, 'check_scan_permission' ),
				'args'                => array(
					'section' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Scanner::SECTIONS,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/recheck',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'recheck' ),
				'permission_callback' => array( $this, 'check_scan_permission' ),
				'args'                => array(
					'id'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'keys' => array(
						'type' => 'array', 'required' => false, 'minItems' => 1, 'maxItems' => 100,
						'items' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
					),
					'section' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Scanner::SECTIONS,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/diff',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diff' ),
				'permission_callback' => array( $this, 'check_translation_permission' ),
				'args'                => array(
					'translation_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'split'          => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/mark-synced',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_synced' ),
				'permission_callback' => array( $this, 'check_translation_permission' ),
				'args'                => array(
					'translation_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// Editor sidebar: open to anyone who may edit the post, not only administrators.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/editor/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'editor_status' ),
				'permission_callback' => array( $this, 'check_editor_permission' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/editor/diff',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diff' ),
				'permission_callback' => array( $this, 'check_editor_permission' ),
				'args'                => array(
					'translation_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'split'          => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/editor/mark-synced',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'editor_mark_synced' ),
				'permission_callback' => array( $this, 'check_editor_permission' ),
				'args'                => array(
					'translation_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/assign-default-language',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'assign_default_language' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Running a scan needs an administrator and an active license.
	 *
	 * @since 2.3.0
	 *
	 * @return true|WP_Error
	 */
	public function check_scan_permission() {
		$allowed = $this->check_permission();
		if ( true !== $allowed ) {
			return $allowed;
		}

		if ( class_exists( \LinguatorPro\Admin\Register\Linguator_Pro_Base::class ) && \LinguatorPro\Admin\Register\Linguator_Pro_Base::is_license_active() ) {
			return true;
		}

		return new WP_Error(
			'lmat_license_required',
			__( 'Add your Linguator Pro license key to run scans.', 'linguator-pro' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Only administrators may read or run the scan.
	 *
	 * @since 2.3.0
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not authorized to perform this action.', 'linguator-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Administrators who may also edit the translation being inspected.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_translation_permission( WP_REST_Request $request ) {
		$allowed = $this->check_permission();
		if ( true !== $allowed ) {
			return $allowed;
		}

		if ( current_user_can( 'edit_post', (int) $request->get_param( 'translation_id' ) ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to edit this translation.', 'linguator-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Anyone who may edit the post being inspected (post_id or translation_id).
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_editor_permission( WP_REST_Request $request ) {
		$post_id = (int) ( $request->get_param( 'post_id' ) ?: $request->get_param( 'translation_id' ) );
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to edit this post.', 'linguator-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Translation status of a post for the editor sidebar.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function editor_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( ( new Freshness( $this->model ) )->editor_status( (int) $request->get_param( 'post_id' ) ) );
	}

	/**
	 * Marks a translation as up to date from the editor and returns its new status.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function editor_mark_synced( WP_REST_Request $request ) {
		$fresh          = new Freshness( $this->model );
		$translation_id = (int) $request->get_param( 'translation_id' );

		if ( ! $fresh->mark_synced( $translation_id ) ) {
			return new WP_Error( 'lmat_scan_mark_synced', __( 'Could not update the translation. Check that it is linked to a post in the default language.', 'linguator-pro' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $fresh->editor_status( $translation_id ) );
	}

	/**
	 * What changed in the default language since a translation was last up to date.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function diff( WP_REST_Request $request ) {
		$diff = ( new Freshness( $this->model ) )->diff( (int) $request->get_param( 'translation_id' ), (bool) $request->get_param( 'split' ) );

		return is_wp_error( $diff ) ? $diff : new WP_REST_Response( $diff );
	}

	/**
	 * Marks a translation as up to date and drops its row from the report.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_synced( WP_REST_Request $request ) {
		$synced = ( new Freshness( $this->model ) )->mark_synced( (int) $request->get_param( 'translation_id' ) );
		if ( ! $synced ) {
			return new WP_Error( 'lmat_scan_mark_synced', __( 'Could not update the translation. Check that it is linked to a post in the default language.', 'linguator-pro' ), array( 'status' => 400 ) );
		}

		$key    = 'content_updated:' . $synced['source_id'] . ':' . $synced['lang'];
		$report = ( new Scanner( $this->model ) )->recheck_rows( 'content', array( $key ) );

		return new WP_REST_Response( $this->prepare_report( $report ?? Scanner::get_saved_report() ) );
	}

	/**
	 * Returns the saved report.
	 *
	 * @since 2.3.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_report(): WP_REST_Response {
		return new WP_REST_Response( $this->prepare_report( Scanner::get_saved_report() ) );
	}

	/**
	 * Runs a fresh scan.
	 *
	 * @since 2.3.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_scan() {
		if ( $blocked = $this->scan_blocked() ) {
			return $blocked;
		}
		if ( ! $this->model->has_languages() ) {
			return $this->add_status_to_error(
				new WP_Error( 'lmat_scan_no_languages', __( 'Add at least one language before scanning.', 'linguator-pro' ) )
			);
		}

		// A scan reads many posts; give shared hosts room without lifting the limit entirely.
		wp_raise_memory_limit( 'admin' );

		$scanner = new Scanner( $this->model );
		return new WP_REST_Response( $this->prepare_report( $scanner->scan() ) );
	}

	/**
	 * Rescans one section.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function scan_section( WP_REST_Request $request ) {
		if ( $blocked = $this->scan_blocked() ) {
			return $blocked;
		}
		wp_raise_memory_limit( 'admin' );

		$report = ( new Scanner( $this->model ) )->scan_section( (string) $request->get_param( 'section' ) );

		return $this->section_response( $report );
	}

	/**
	 * Rechecks a single issue.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function recheck( WP_REST_Request $request ) {
		if ( $blocked = $this->scan_blocked() ) {
			return $blocked;
		}
		wp_raise_memory_limit( 'admin' );
		$keys = $request->get_param( 'keys' );
		// A row is rechecked on its own when it names a post, and otherwise its whole issue is rechecked.
		if ( is_array( $keys ) && $keys ) {
			$report = ( new Scanner( $this->model ) )->recheck_rows( (string) $request->get_param( 'section' ), $keys );
			if ( null === $report ) {
				return new WP_Error( 'lmat_scan_row_not_found', __( 'This row is no longer in the saved report. Refresh the results and try again.', 'linguator-pro' ), array( 'status' => 400 ) );
			}
			return new WP_REST_Response( $this->prepare_report( $report ) );
		}

		$report = ( new Scanner( $this->model ) )->scan_section( (string) $request->get_param( 'section' ), (string) $request->get_param( 'id' ) );

		return $this->section_response( $report );
	}

	/**
	 * Gives the default language to every post and term that has none, then refreshes Content.
	 *
	 * @since 2.3.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function assign_default_language() {
		if ( ! $this->model->get_default_language() ) {
			return $this->add_status_to_error(
				new WP_Error( 'lmat_scan_no_default', __( 'No default language is configured. Set one first.', 'linguator-pro' ) )
			);
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$this->model->set_language_in_mass();

		return new WP_REST_Response( $this->prepare_report( Scanner::get_saved_report() ) );
	}

	/**
	 * An error that stops a scan while content still has no language, or null when a scan may run.
	 *
	 * The button is disabled in the page, but a request can still arrive from a tab left open before the content
	 * was assigned.
	 *
	 * @since 2.3.0
	 *
	 * @return WP_Error|null
	 */
	private function scan_blocked(): ?WP_Error {
		if ( ! Scanner::has_unassigned_content( $this->model ) ) {
			return null;
		}

		return $this->add_status_to_error(
			new WP_Error( 'lmat_scan_unassigned', __( 'Some content has no language. Set it to the default language, then scan.', 'linguator-pro' ) ),
			409
		);
	}

	/**
	 * Response for the section-level scans.
	 *
	 * @since 2.3.0
	 *
	 * @param array|null $report Updated report, or null when no scan exists yet.
	 * @return WP_REST_Response|WP_Error
	 */
	private function section_response( ?array $report ) {
		if ( null === $report ) {
			return $this->add_status_to_error(
				new WP_Error( 'lmat_scan_no_report', __( 'Run Scan site once first. Section scans update an existing report.', 'linguator-pro' ) )
			);
		}

		return new WP_REST_Response( $this->prepare_report( $report ) );
	}

	/**
	 * Dismisses or restores an issue or a single row of it.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function ignore( WP_REST_Request $request ): WP_REST_Response {
		$keys = (array) ( $request->get_param( 'keys' ) ?? array() );
        if ( $request->get_param( 'key' ) ) $keys[] = (string) $request->get_param( 'key' );
        if ( ! $keys ) return new WP_REST_Response( array( 'message' => __( 'Choose at least one issue to ignore or restore.', 'linguator-pro' ) ), 400 );
        Scanner::set_ignored_many( array_map( 'sanitize_text_field', $keys ), (bool) $request->get_param( 'ignore' ) );

		return new WP_REST_Response( $this->prepare_report( Scanner::get_saved_report() ) );
	}

	/**
	 * Returns the report as Markdown for sharing with a developer or support.
	 *
	 * @since 2.3.0
	 *
	 * @return WP_REST_Response
	 */
	public function export(): WP_REST_Response {
		$report = $this->prepare_report( Scanner::get_saved_report() );

		$lines   = array();
		$lines[] = '# ' . __( 'Linguator site scan', 'linguator-pro' );
		$lines[] = '';

		if ( empty( $report['results'] ) ) {
			$lines[] = __( 'No scan has been run yet.', 'linguator-pro' );
			return new WP_REST_Response( array( 'markdown' => implode( "\n", $lines ) ) );
		}

		$lines[] = sprintf(
			/* translators: 1: score, 2: scan date */
			__( 'Health score: %1$d/100 — scanned %2$s', 'linguator-pro' ),
			$report['health']['score'],
			$report['time_formatted']
		);
		$lines[] = '';

		foreach ( $report['results'] as $result ) {
			if ( 'pass' === $result['status'] ) {
				continue;
			}

			$lines[] = sprintf( '## [%s] %s', strtoupper( $result['status'] ), $result['label'] );
			$lines[] = $result['message'];
			foreach ( $result['details'] as $row ) {
				$lines[] = sprintf( '- %s — %s', $row['label'], $row['message'] );
			}
			if ( ! empty( $result['details_truncated'] ) ) {
				$lines[] = '- …';
			}
			$lines[] = '';
		}

		return new WP_REST_Response( array( 'markdown' => implode( "\n", $lines ) ) );
	}

	/**
	 * Builds what the UI renders: ignore list applied, health, environment and copy.
	 *
	 * @since 2.3.0
	 *
	 * @param array|null $report Saved report.
	 * @return array
	 */
	private function prepare_report( ?array $report ): array {
        // Settings notices stay current even before the first scan or after changing settings.
        $type_check = new \LinguatorPro\Modules\Scan\Checks\Untranslated_Types( $this->model );
        $type_results = array_map( static function ( $result ) { return $result->to_array(); }, $type_check->run() );
        $type_report = Scanner::apply_ignored( array( 'results' => $type_results ) );
        $type_notice = null;
        foreach ( $type_report['results'] as $result ) {
            if ( 'warning' === $result['status'] ) { $type_notice = $result; break; }
        }

		$base = array(
			'untranslated_types_notice' => $type_notice,
            'unassigned'          => Scanner::has_unassigned_content( $this->model ),
			'elementor_available' => \LinguatorPro\Modules\Scan\Checks\Elementor::available(),
			'scan_icon_url' => plugins_url( 'assets/logo/site-scan.svg', LINGUATOR_PRO_ROOT_FILE ),
			'environment' => $this->environment(),
			'ignored'     => Scanner::get_ignored(),
			'languages'   => array_map(
				static function ( $language ) {
					return array(
						'slug' => $language->slug,
						'name' => $language->name,
						'flag_url' => esc_url_raw( $language->custom_flag_url ?: $language->flag_url ),
					);
				},
				array_values( $this->model->get_languages_list() )
			),
		);

		if ( null === $report ) {
			return $base + array(
				'has_scan'      => false,
				'results'       => array(),
				'ignored_items' => $type_report['ignored_items'],
				'coverage'      => array(),
				'summary'       => Scanner::summarize( array() ),
				'health'        => array(
					'score'    => 100,
					'coverage' => 100,
					'setup'    => 100,
				),
				'partial_scans' => new \stdClass(),
			);
		}

		$report   = Scanner::apply_ignored( $report );

		// Reports saved before content without a language became a notice may still hold it as an issue.
		$report['results'] = array_values(
			array_filter(
				(array) $report['results'],
				static function ( $result ) {
					return ! in_array( $result['id'] ?? '', array( 'content_no_language', 'term_no_language', 'lang_untranslated_types' ), true );
				}
			)
		);
        $report['ignored_items'] = array_values( array_filter( $report['ignored_items'], static function ( $item ) { return 'lang_untranslated_types' !== $item['key']; } ) );
        $report['ignored_items'] = array_merge( $report['ignored_items'], $type_report['ignored_items'] );
		$report['summary'] = Scanner::summarize( $report['results'] );
		$coverage = isset( $report['coverage'] ) && is_array( $report['coverage'] ) ? $report['coverage'] : array();
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		foreach ( $report['results'] as $i => $result ) {
			if ( 'pass' !== $result['status'] ) {
				$report['results'][ $i ] += Issue_Copy::for_id( $result['id'] );
			}
			foreach ( $result['details'] ?? array() as $j => $detail ) {
				$key = $detail['ignore_key'] ?? '';
				if ( preg_match( '/^menu:([^:]+):([^:]+)$/', $key, $parts ) ) {
					$locations = get_registered_nav_menus();
					$report['results'][ $i ]['details'][ $j ]['location_label'] = $locations[ $parts[1] ] ?? $parts[1];
					$report['results'][ $i ]['details'][ $j ]['language'] = $parts[2];
				} elseif ( preg_match( '/^content:(\d+):([^:]+)$/', $key, $parts ) ) {
					$report['results'][ $i ]['details'][ $j ]['edit_url'] = (string) get_edit_post_link( (int) $parts[1], 'url' );
				}
			}
		}

        $page_groups = array();
        $default_language = $this->model->get_default_language();
        foreach ( $report['ignored_items'] as &$item ) {
            // Use the same language-independent heading as the active issue accordion.
            $copy = Issue_Copy::for_id( (string)($item['issue_id'] ?? '') );
            if ( !empty($copy['display_label']) ) $item['issue'] = $copy['display_label'];
            $key = $item['key'];
            $post_id = 0;
            if ( preg_match('/^(?:content(?:_updated|_identical|_placeholder|_link|_acf)?|elementor_loop):(\d+):/', $key, $matches) ) {
                $post_id = (int)$matches[1];
            } elseif ( preg_match('/^elementor:[^:]+:(\d+):/', $key, $matches) ) {
                $post_id = (int)$matches[1];
            }
            if ( $post_id > 0 ) {
                if ( !isset($page_groups[$post_id]) ) {
                    $translations = $this->model->post->get_translations($post_id);
                    $source_id = $default_language ? (int)($translations[$default_language->slug] ?? $post_id) : $post_id;
                    $page_groups[$post_id] = array('source_post_id'=>$source_id, 'source_title'=>get_the_title($source_id));
                }
                $item += $page_groups[$post_id];
            }
        }
        unset($item);

		$partial = array();
		foreach ( (array) ( isset( $report['partial_scans'] ) ? $report['partial_scans'] : array() ) as $section => $time ) {
			$partial[ $section ] = wp_date( $format, (int) $time );
		}

		return $base + array(
			'has_scan'       => true,
			'results'        => $report['results'],
			'ignored_items'  => $report['ignored_items'],
			'summary'        => $report['summary'],
			'coverage'       => $coverage,
			'elementor_summary' => isset( $report['elementor'] ) && is_array( $report['elementor'] ) ? $report['elementor'] : null,
			'health'         => Scanner::health( $report['results'], $coverage, $report['elementor'] ?? array() ),
			'time_formatted' => wp_date( $format, (int) $report['time'] ),
			'partial_scans'  => (object) $partial,
		);
	}

	/**
	 * Facts about the site that explain scan results.
	 *
	 * @since 2.3.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function environment(): array {
		$default = $this->model->get_default_language();
		$theme   = wp_get_theme();
		$builder = __( 'Gutenberg (Block Editor)', 'linguator-pro' );
		if ( class_exists( '\\Elementor\\Plugin' ) || defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' ) ) {
			$builder = __( 'Elementor', 'linguator-pro' );
		} elseif ( defined( 'ET_BUILDER_VERSION' ) || class_exists( 'ET_Builder_Plugin' ) || function_exists( 'et_setup_theme' ) ) {
			$builder = __( 'Divi', 'linguator-pro' );
		}
		$rows = array(
			array( 'label' => __( 'Page Builder', 'linguator-pro' ), 'icon' => 'layout', 'value' => $builder ),
			array( 'label' => __( 'Theme', 'linguator-pro' ), 'icon' => 'edit', 'value' => $theme->get( 'Name' ) ),
			array( 'label' => __( 'Active Languages', 'linguator-pro' ), 'icon' => 'translation', 'kind' => 'languages', 'value' => array_values( wp_list_pluck( $this->model->get_languages_list(), 'slug' ) ) ),
			array( 'label' => __( 'Default Language', 'linguator-pro' ), 'icon' => 'admin-site-alt3', 'kind' => 'language', 'value' => $default ? $default->slug : '' ),
			array( 'label' => __( 'PHP Version', 'linguator-pro' ), 'icon' => 'editor-code', 'value' => PHP_VERSION ),
			array( 'label' => __( 'WordPress Version', 'linguator-pro' ), 'icon' => 'wordpress', 'value' => get_bloginfo( 'version' ) ),
		);
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$addons = array();
		foreach ( get_plugins() as $basename => $plugin ) {
			if ( plugin_basename( LINGUATOR_PRO_ROOT_FILE ) === $basename || ! is_plugin_active( $basename ) ) {
				continue;
			}
			if ( false !== strpos( dirname( $basename ), 'linguator' ) || false !== strpos( dirname( $basename ), 'lmat-' ) ) {
				$addons[] = wp_strip_all_tags( $plugin['Name'] );
			}
		}
		if ( $addons ) {
			array_unshift( $rows, array( 'label' => __( 'Linguator Addons', 'linguator-pro' ), 'icon' => 'admin-plugins', 'kind' => 'list', 'value' => array_values( array_unique( $addons ) ) ) );
		}
		if ( current_user_can( 'view_site_health_checks' ) ) {
			$rows[] = array( 'label' => __( 'Site Health', 'linguator-pro' ), 'icon' => 'heart', 'kind' => 'link', 'value' => __( 'Open Site Health', 'linguator-pro' ), 'url' => admin_url( 'site-health.php' ) );
		}
		return $rows;
	}
}
