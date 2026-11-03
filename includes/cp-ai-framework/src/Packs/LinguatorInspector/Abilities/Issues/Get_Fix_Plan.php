<?php
/**
 * Get Fix Plan ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Issues;

use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Fix Plan.
 *
 * @since 1.2.0
 */
final class Get_Fix_Plan extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-fix-plan';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Fix Plan', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator Site Scan: get a prioritised plan for fixing the site, in the order the Site Scan recommends (critical issues first, then content, Elementor, URLs and site setup), with the effort each step takes (one_click, guided or manual). Each step has the issue_key and names how to fix it: linguator-inspector/fix-issue for problems the Inspector fixes itself (content without a language, draft translations, WooCommerce product sync), a linguator/* tool when the Linguator translation abilities are active (translate-post or translate-term for missing, copied or outdated translations, translate-string for strings), or otherwise a manual fix: the admin screen URL and instructions so you can guide the user. After each fix, call recheck-issue to confirm it.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'section' => array(
					'type'        => 'string',
					'enum'        => self::SECTIONS,
					'description' => __( 'Only plan steps for this Site Scan section.', 'linguator-pro' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => __( 'Maximum number of steps to return.', 'linguator-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		$error = Inspector_Guard::require_saved_scan();
		if ( null !== $error ) {
			return $error;
		}

		$section = isset( $input['section'] ) ? sanitize_key( (string) $input['section'] ) : '';
		$limit   = min( 50, max( 1, (int) ( $input['limit'] ?? 10 ) ) );

		$issues = array_values(
			array_filter(
				Issue_Service::all(),
				static function ( $issue ) use ( $section ) {
					return 'pass' !== $issue['severity'] && ( '' === $section || $section === $issue['section'] );
				}
			)
		);

		$steps = array();
		foreach ( array_slice( $issues, 0, $limit ) as $index => $issue ) {
			$summary = Issue_Service::summarize( $issue, 0 );
			$steps[] = array(
				'step'               => $index + 1,
				'issue_key'          => $summary['issue_key'],
				'issue_type'         => $summary['issue_type'],
				'title'              => $summary['title'],
				'section'            => $summary['section'],
				'severity'           => $summary['severity'],
				'fix_first'          => $summary['fix_first'],
				'count'              => $summary['count'],
				'affected_languages' => $summary['affected_languages'],
				'effort'             => $summary['effort'],
				'why'                => '' !== $summary['why'] ? $summary['why'] : $summary['message'],
				'how_to_fix'         => $summary['how_to_fix'],
				'fix'                => $summary['fix'],
			);
		}

		$health = (array) Report_Service::context()['health'];

		return array(
			'health_score'   => (int) ( $health['score'] ?? 0 ),
			'health_pillars' => array(
				'coverage' => (int) ( $health['coverage'] ?? 0 ),
				'setup'    => (int) ( $health['setup'] ?? 0 ),
				'builder'  => isset( $health['builder'] ) ? (int) $health['builder'] : null,
			),
			'total_issues'   => count( $issues ),
			'steps'          => $steps,
			'more_steps'     => max( 0, count( $issues ) - count( $steps ) ),
			'next_step'      => empty( $steps )
				? __( 'Nothing to fix in the saved report. Suggest linguator-inspector/run-scan if it is old, or scope "urls" to test the live URLs.', 'linguator-pro' )
				: __( 'Work through the steps in order. For each, call linguator-inspector/get-issues with its issue_key to get the rows and their fix input, apply the fix, then confirm with linguator-inspector/recheck-issue.', 'linguator-pro' ),
		);
	}
}
