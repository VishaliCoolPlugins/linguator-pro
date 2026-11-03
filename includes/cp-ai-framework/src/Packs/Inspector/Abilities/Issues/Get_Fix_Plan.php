<?php
/**
 * Get Fix Plan ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Abilities\Issues;

use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the saved report into an ordered plan, each step naming the tool or
 * admin screen that fixes it.
 *
 * @since 1.1.0
 */
final class Get_Fix_Plan extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-fix-plan';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Fix Plan', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Translation Inspector: get a prioritised plan for fixing the site, in the order the dashboard recommends (Fix These First, then content, Elementor, URLs and site setup), with the effort each step takes (one_click, guided or manual). Each step has the issue_key and names how to fix it: translation-inspector/fix-issue for problems the Inspector fixes itself, an AutoPoly tool when AutoPoly is active (autopoly/translate-post or translate-term for missing, copied or outdated translations and Elementor layouts, autopoly/sync-menu for menus, autopoly/translate-string for strings), or otherwise the admin screen URL and instructions so you can guide the user. After each fix, call recheck-issue to confirm it.', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'section' => array(
					'type'        => 'string',
					'enum'        => self::SECTIONS,
					'description' => __( 'Only plan steps for this dashboard section.', 'duplicate-content-addon-for-polylang' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => __( 'Maximum number of steps to return.', 'duplicate-content-addon-for-polylang' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Issue_Service already returns issues in the dashboard's order; each step
	 * adds the fix from Fix_Router.
	 *
	 * @since 1.1.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|\WP_Error Plan.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		$error = Inspector_Guard::require_saved_scan();
		if ( null !== $error ) {
			return $error;
		}

		$section = isset( $input['section'] ) ? (string) $input['section'] : '';
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

		$context = Report_Service::context();

		return array(
			'health_score'   => (int) ( $context['health_score'] ?? 0 ),
			'health_pillars' => (array) ( $context['health_pillars'] ?? array() ),
			'total_issues'   => count( $issues ),
			'steps'          => $steps,
			'more_steps'     => max( 0, count( $issues ) - count( $steps ) ),
			'next_step'      => empty( $steps )
				? __( 'Nothing to fix in the saved report. Suggest translation-inspector/run-scan if it is old, or scope "urls" to test the live URLs.', 'duplicate-content-addon-for-polylang' )
				: __( 'Work through the steps in order. For each, call translation-inspector/get-issues with its issue_key to get the rows and their fix input, apply the fix, then confirm with translation-inspector/recheck-issue.', 'duplicate-content-addon-for-polylang' ),
		);
	}
}
