<?php
/**
 * Plain-English "why it matters" and "how to fix" text for scan issues.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy shown in "Fix These First".
 *
 * @since 2.3.0
 */
class Issue_Copy {

	/**
	 * Copy for a result id.
	 *
	 * @since 2.3.0
	 *
	 * @param string $id Result id.
	 * @return array{why:string,how:string}
	 */
	public static function for_id( string $id ): array {
        $key = array( 'lang_homepage' => 'homepage_translations', 'translated_permalink_review' => 'translated_permalinks' )[ $id ] ?? $id;
        $families = array(
            'content_unpublished_' => 'content_draft', 'content_draft_' => 'content_draft',
            'content_identical_' => 'content_identical', 'content_link_' => 'content_links',
            'content_placeholder_' => 'content_placeholder', 'content_acf_' => 'content_acf',
            'term_missing_' => 'term_missing', 'string_' => 'strings_missing',
            'url_config_' => 'url_configuration', 'url_route_' => 'routing_health', 'nav_' => 'navigation',
        );
        foreach ( $families as $prefix => $family ) {
            if ( 0 === strpos( $id, $prefix ) ) { $key = $family; break; }
        }
        if ( 0 === strpos( $id, 'menu_' ) && 'menu_dead_links' !== $id && 'menu_none' !== $id ) $key = 'menu_assignments';
        if ( preg_match( '/^content_(?:page|post)(?:_|$)/', $id ) ) $key = 'content_missing';
        $shared = Toolkit_Copy::for_key( $key );
        if ( null !== $shared ) {
            return $shared;
        }

		if ( 0 === strpos( $id, 'elementor_' ) ) {
			return array(
				'why' => __( 'Elementor templates need valid layouts and published, linked translations to display correctly in each language.', 'linguator-pro' ),
				'how' => __( 'Use the language flags to create missing translations, or Edit in Elementor to review an existing template. Linguator provides the template integration; no extra language-switcher plugin is required.', 'linguator-pro' ),
			);
		}

		if ( 'menu_none' === $id ) {
			return array(
				'why' => __( 'Visitors may see missing or incomplete navigation when they change language.', 'linguator-pro' ),
				'how' => __( 'Open Manage menu locations and assign a menu for each language in the locations your theme uses.', 'linguator-pro' ),
			);
		}

		$map = array(
			'lang_switcher' => array(
				__( 'Visitors need a visible language switcher to find your translated content.', 'linguator-pro' ),
				__( 'Add a Linguator language switcher to a menu, widget area or block template used by your site.', 'linguator-pro' ),
			),

			'url_domains'         => array(
				__( 'In domain mode a language without a domain cannot be reached by visitors.', 'linguator-pro' ),
				__( 'Edit each listed language and enter its domain, or switch the URL modification mode in General Settings.', 'linguator-pro' ),
			),

			'lang_untranslated_types' => array(
				__( 'Content in a post type or taxonomy that is not translatable stays in one language, whatever language the visitor picks.', 'linguator-pro' ),
				__( 'Open Linguator settings and switch on the post types and taxonomies you want to translate.', 'linguator-pro' ),
			),

			'url_dupe_slugs' => array(
				__( 'When posts in different languages share a slug, visitors and search engines can land on the wrong language or a 404.', 'linguator-pro' ),
				__( 'Open each post listed in the row and give one of them a different slug.', 'linguator-pro' ),
			),
			'url_numbered_copies' => array(
				__( 'WordPress can append -2 or another number when a slug is already taken. Matching titles or content in the same language can indicate an accidental copy.', 'linguator-pro' ),
				__( 'Compare the linked items and their URLs. Keep both if intentional and ignore this warning; otherwise review links before removing an unwanted copy.', 'linguator-pro' ),
			),

		);

		if ( isset( $map[ $id ] ) ) {
			return array(
				'why' => $map[ $id ][0],
				'how' => $map[ $id ][1],
			);
		}

		return array(
			'why' => __( 'This check highlights a part of your multilingual setup that may need attention.', 'linguator-pro' ),
			'how' => __( 'Review the issue description and affected items, then use the available settings or edit link. Recheck after making changes.', 'linguator-pro' ),
		);
	}
}
