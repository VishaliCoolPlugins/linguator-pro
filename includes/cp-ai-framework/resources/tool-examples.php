<?php
/**
 * Example prompts and result summaries shown for each tool on the Tools page.
 *
 * Keyed by ability name. "try" is a sentence a user could say to their AI app;
 * "returns" is up to three short bullets on what the tool gives back. A tool
 * with no entry simply shows no example boxes.
 *
 * Each entry is written against the tool's real behaviour (its description and
 * input schema): a prompt names things the way a person would (page titles, a
 * language), and the AI looks up any IDs through the other tools. When a tool's
 * behaviour changes, update its entry here.
 *
 * @package CoolPluginsAiFramework
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	// AutoMLP (WPML).
	'automlp/get-translation-status'        => array(
		'try'     => 'How much of my site is translated into French?',
		'returns' => array( 'For each content type: a count, a missing count and a percentage per language', 'A starting point for deciding what to translate next' ),
	),
	'automlp/get-translation-plan'          => array(
		'try'     => 'Translate my homepage, header, footer and menu pages to French',
		'returns' => array( 'Pages in a safe order, each marked missing, draft or published', 'Blockers to fix first, and steps only an admin can do' ),
	),
	'automlp/verify-translations'           => array(
		'try'     => 'Check that the French pages we just translated are live and correct',
		'returns' => array( 'Whether each translation exists, is published and loads in the right language', 'Problems found, and how to fix the first one. It does not judge wording' ),
	),
	'automlp/audit-translation-integrity'   => array(
		'try'     => 'Audit the French version of the About page',
		'returns' => array( 'Text identical to the original, which usually means it was never translated', 'Links still pointing at the original language, and templates still wired to it' ),
	),
	'automlp/get-untranslated-content'      => array(
		'try'     => 'Which pages still need a Spanish translation?',
		'returns' => array( 'Content with no usable Spanish translation', 'Content whose original was edited after it was translated' ),
	),
	'automlp/list-posts'                    => array(
		'try'     => 'List the pages that exist in English, newest first',
		'returns' => array( 'Posts or pages in one language, newest first', 'Each item\'s ID, title and page builder' ),
	),
	'automlp/get-content'                   => array(
		'try'     => 'Read the text of the Contact page so it can be translated',
		'returns' => array( 'The post\'s translatable text, split the way WPML sees it', 'Text kept in a page builder such as Elementor' ),
	),
	'automlp/get-post-translations'         => array(
		'try'     => 'Which languages does the Pricing page exist in?',
		'returns' => array( 'Every language version, with its ID, title, status and edit link', 'The active languages that have no version yet' ),
	),
	'automlp/get-element-language-details'  => array(
		'try'     => 'Check which language the Contact page is assigned to and what it was translated from',
		'returns' => array( 'The page\'s language and the language it was translated from', 'The group ID shared by all its language versions' ),
	),
	'automlp/list-terms'                    => array(
		'try'     => 'Show my blog categories that exist in Spanish',
		'returns' => array( 'The terms of one taxonomy in one language, including empty ones', 'Each term\'s ID and name' ),
	),
	'automlp/get-term-translations'         => array(
		'try'     => 'Which languages is the News category translated into?',
		'returns' => array( 'Every language version with its ID, name, slug and post count', 'The active languages that have no version yet' ),
	),
	'automlp/get-string-groups'             => array(
		'try'     => 'Which theme and plugin texts can I translate?',
		'returns' => array( 'Text groups such as a theme, a plugin or the site title', 'How many strings in each are translated, outdated or untranslated' ),
	),
	'automlp/get-strings'                   => array(
		'try'     => 'Show the untranslated French strings in my theme',
		'returns' => array( 'Theme, plugin and admin text still needing a translation', 'Strings whose original changed after they were translated, when you ask for those' ),
	),
	'automlp/get-site-info'                 => array(
		'try'     => 'Is my site ready for AI translation?',
		'returns' => array( 'Whether WPML, String Translation and an AI provider are set up', 'The first missing requirement, if any' ),
	),
	'automlp/get-translation-settings'      => array(
		'try'     => 'Why was my French page created with a copied slug?',
		'returns' => array( 'How translated URLs and slugs are built, and how custom fields are handled', 'Which AI providers have a key, and the status new translations get' ),
	),
	'automlp/list-languages'                => array(
		'try'     => 'Which languages are active on this site?',
		'returns' => array( 'The active WPML languages and their codes', 'Which one is the site\'s default language' ),
	),
	'automlp/list-content-types'            => array(
		'try'     => 'Which content types can be translated, and which are switched off?',
		'returns' => array( 'Post types and taxonomies WPML translates, with item counts', 'Types that are switched off, with how to turn them on' ),
	),
	'automlp/get-editor-type'               => array(
		'try'     => 'Which page builder does the Services page use?',
		'returns' => array( 'The page builder holding the page\'s content', 'Whether AutoMLP supports it' ),
	),
	'automlp/translate-post'                => array(
		'try'     => 'Translate the About page to French and publish it',
		'returns' => array( 'The saved translation, linked to the original', 'Updates the existing translation instead of making a duplicate' ),
	),
	'automlp/translate-term'                => array(
		'try'     => 'Translate the News category to German',
		'returns' => array( 'The new category or tag translation, linked to the original', 'An error, if that language already has one' ),
	),
	'automlp/translate-strings'             => array(
		'try'     => 'Translate my theme\'s texts to Italian',
		'returns' => array( 'Up to 100 strings saved per call, shown on the site straight away', 'Any string that failed, listed separately' ),
	),
	'automlp/fix-translated-post-url'       => array(
		'try'     => 'Change the URL slug of the French Contact page to "contactez-nous"',
		'returns' => array( 'The translated post with its new slug and, for posts, category' ),
	),
	'automlp/repair-elementor-media'        => array(
		'try'     => 'Check the images and galleries on the French homepage',
		'returns' => array( 'Each image with its caption, language and whether its size file exists', 'On request: missing sizes regenerated, or captions updated' ),
	),
	'automlp/rebuild-elementor-data'        => array(
		'try'     => 'The French Elementor page I just translated looks broken. Can you fix it?',
		'returns' => array( 'The page re-saved so Elementor recognises its content', 'No text or elements are changed' ),
	),
	'automlp/ensure-translation'            => array(
		'try'     => 'Create a Spanish version of the Pricing page that I can translate later',
		'returns' => array( 'A linked Spanish copy, still in the original language', 'The existing version unchanged, if there already is one' ),
	),
	'automlp/manage-translation-links'      => array(
		'try'     => 'Link the English About page and the French "À propos" page as translations of each other',
		'returns' => array( 'A preview of exactly what would change, before anything is saved', 'The result once you agree to apply it' ),
	),

	// AutoPoly (Polylang).
	'autopoly/get-site-info'                => array(
		'try'     => 'Is my site ready for AI translation?',
		'returns' => array( 'WordPress and Polylang setup', 'A profile of the site, and the workflow to follow for translation jobs' ),
	),
	'autopoly/get-translation-status'       => array(
		'try'     => 'How much of my site is translated into French?',
		'returns' => array( 'Translated, untranslated and total counts with percentages per content type', 'Items that have no language assigned' ),
	),
	'autopoly/get-translation-plan'         => array(
		'try'     => 'Translate my homepage, header, footer and menu pages to French',
		'returns' => array( 'Pages in a safe order, each marked missing, unpublished or published', 'Blockers to fix first, and the steps to follow' ),
	),
	'autopoly/verify-translations'          => array(
		'try'     => 'Check that the French pages we just translated are live',
		'returns' => array( 'Whether each translation exists, is published and loads in the right language', 'Problems found, and how to fix the first one. It does not judge wording' ),
	),
	'autopoly/get-translation-settings'     => array(
		'try'     => 'How is Polylang set up to translate my site?',
		'returns' => array( 'Which post types and taxonomies can be translated, and how language appears in URLs', 'The menus and the language each is assigned to' ),
	),
	'autopoly/preflight-template'           => array(
		'try'     => 'Can the Elementor header be translated and shown in French?',
		'returns' => array( 'Whether the template is translatable, has a language, and Connect Polylang for Elementor is active', 'What to fix or do next, in order' ),
	),
	'autopoly/get-content'                  => array(
		'try'     => 'Read the About page so it can be translated to French',
		'returns' => array( 'Title, body, excerpt and translatable fields, with existing translations', 'Page builder text, and glossary terms that must be used' ),
	),
	'autopoly/get-outdated-translations'    => array(
		'try'     => 'Which French pages are out of date because the English was edited?',
		'returns' => array( 'Each outdated translation and its source', 'Which parts changed: title, content, excerpt or custom fields' ),
	),
	'autopoly/get-term'                     => array(
		'try'     => 'Show me the News category and its translations',
		'returns' => array( 'The term\'s name, slug, description and parent', 'Its language and existing translations' ),
	),
	'autopoly/get-untranslated-content'     => array(
		'try'     => 'Which pages still need a Spanish translation?',
		'returns' => array( 'Content with no Spanish translation, with its ID, title, type and status', 'Header and footer templates, marked as such' ),
	),
	'autopoly/list-content-types'           => array(
		'try'     => 'Which content types can be translated, and which are switched off?',
		'returns' => array( 'Post types and taxonomies Polylang translates, with counts', 'Types that are switched off, with how to turn them on' ),
	),
	'autopoly/get-glossary'                 => array(
		'try'     => 'Show my translation glossary for English to French',
		'returns' => array( 'Terms that must be translated a specific way', 'Brand names that should stay unchanged' ),
	),
	'autopoly/manage-glossary'              => array(
		'try'     => 'Add "Add to cart" to the glossary, always translated as "Ajouter au panier" in French',
		'returns' => array( 'The glossary entry that was added, updated or deleted', 'It also applies to AutoPoly\'s own translations' ),
	),
	'autopoly/create-language'              => array(
		'try'     => 'Add Italian to my site',
		'returns' => array( 'The new language, with name, slug, direction and flag filled in from Polylang\'s list' ),
	),
	'autopoly/update-language'              => array(
		'try'     => 'Rename the Spanish language to "Español"',
		'returns' => array( 'The language with only the fields you changed updated' ),
	),
	'autopoly/delete-language'              => array(
		'try'     => 'Remove Italian from my site',
		'returns' => array( 'The language removed and unassigned from all content', 'The content itself is not deleted' ),
	),
	'autopoly/list-languages'               => array(
		'try'     => 'Which languages are active on this site?',
		'returns' => array( 'Each language with its slug, name, locale, flag and text direction', 'Which one is the default' ),
	),
	'autopoly/set-template-menu'            => array(
		'try'     => 'Make the French header show the French menu',
		'returns' => array( 'The translated header or footer now showing the chosen menu', 'Elementor\'s cache cleared so it shows straight away' ),
	),
	'autopoly/sync-menu'                    => array(
		'try'     => 'Copy my main menu into French',
		'returns' => array( 'A preview first: what would be created or replaced, and which items would be skipped', 'Once applied, a menu whose items point at the French pages' ),
	),
	'autopoly/get-string-groups'            => array(
		'try'     => 'Which site texts like the title and widgets can I translate?',
		'returns' => array( 'Registered string groups with their strings', 'Translation status per language' ),
	),
	'autopoly/manage-translation-links'     => array(
		'try'     => 'Link the English About page and the French "À propos" page as translations of each other',
		'returns' => array( 'A preview of the group before and after, with any conflicts', 'The change applied once you agree' ),
	),
	'autopoly/translate-post'               => array(
		'try'     => 'Translate the About page to French and publish it',
		'returns' => array( 'The saved translation, linked to the original', 'Any link it could not point at a translation' ),
	),
	'autopoly/translate-string'             => array(
		'try'     => 'Translate my site tagline to French',
		'returns' => array( 'The translation saved for that site title, tagline or widget title', 'Also works for navigation menu labels' ),
	),
	'autopoly/translate-term'               => array(
		'try'     => 'Translate the News category to German',
		'returns' => array( 'The category or tag translation, created or updated and linked to the original' ),
	),

	// LocoAI (Loco Translate).
	'locoai/get-site-info'                  => array(
		'try'     => 'Is my site ready to translate plugin and theme text with Loco?',
		'returns' => array( 'The site language, installed languages and Loco Translate version', 'Whether translation files can be written, and your own translation instructions' ),
	),
	'locoai/list-projects'                  => array(
		'try'     => 'Which plugins and themes can I translate with Loco?',
		'returns' => array( 'Plugins, themes and core with their text domains', 'The language files that already exist for each' ),
	),
	'locoai/get-project-status'             => array(
		'try'     => 'How complete is the French translation of my theme?',
		'returns' => array( 'Each language file with translated, fuzzy and untranslated counts', 'Where it is stored and whether the server can write to it' ),
	),
	'locoai/get-strings'                    => array(
		'try'     => 'Show the untranslated French strings in my theme',
		'returns' => array( 'Untranslated strings, 50 per page, with their context', 'Placeholders and plural forms to keep intact' ),
	),
	'locoai/save-translations'              => array(
		'try'     => 'Translate the missing French strings in my theme',
		'returns' => array( 'Up to 200 translations saved per call, shown on the site straight away', 'Any rejected entries, with the reason' ),
	),
	'locoai/create-language-file'           => array(
		'try'     => 'Start a German translation for my plugin',
		'returns' => array( 'A new empty language file, kept safe from plugin updates', 'An error, rather than overwriting, if one already exists' ),
	),
	'locoai/create-template'                => array(
		'try'     => 'Create a translation template for my plugin',
		'returns' => array( 'A template (.pot) listing the plugin\'s translatable strings', 'An error, rather than overwriting, if one already exists' ),
	),
	'locoai/sync-language-file'             => array(
		'try'     => 'Update the French file after the plugin update',
		'returns' => array( 'Counts of strings added, changed (marked for review) and removed', 'Existing translations are kept. A preview is available' ),
	),
	'locoai/get-glossary'                   => array(
		'try'     => 'Show my translation glossary',
		'returns' => array( 'Terms that must be translated a specific way', 'Brand names that stay unchanged' ),
	),
	'locoai/manage-glossary'                => array(
		'try'     => 'Add "Cart" to the glossary with its translation in each language of my site',
		'returns' => array( 'The glossary entry that was added, updated or deleted' ),
	),
);
