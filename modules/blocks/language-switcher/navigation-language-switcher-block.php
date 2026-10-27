<?php

namespace LinguatorPro\Modules\Blocks;

use LinguatorPro\Includes\Switcher\Switcher;
use LinguatorPro\Includes\Switcher\Element\Abstract_Element;
use LinguatorPro\Includes\Switcher\Element\Nav as Element;
use LinguatorPro\Includes\Switcher\Settings\Menu as Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @package Linguator
 */

/**
 * Language switcher block for navigation.
 *
 */
class Linguator_Navigation_Language_Switcher_Block extends Linguator_Abstract_Language_Switcher_Block {
	/**
	 * Placeholder used to add language name or flag after WordPress renders the link labels.
	 *
	 * @var string
	 */
	const PLACEHOLDER = '%lmat%';

	/**
	 * Trusted labels and locales for navigation blocks created by this switcher.
	 * Saved blocks are different instances and cannot use these values.
	 *
	 * @var \SplObjectStorage<\WP_Block, array{locale: string, label: string}>
	 */
	private $internal_blocks;

	/**
	 * @param object $linguator Linguator instance.
	 */
	public function __construct( &$linguator ) {
		parent::__construct( $linguator );
		$this->internal_blocks = new \SplObjectStorage();
	}

	/**
	 * Adds the required hooks specific to the navigation language switcher.
	 *
	 *
	 * @return self
	 */
	public function init() {
		parent::init();

		add_action( 'rest_api_init', array( $this, 'linguator_register_switcher_menu_item_options_meta_rest_field' ) );
		add_filter( 'render_block_core/navigation-link', array( $this, 'linguator_render_custom_attributes' ), 10, 3 );
		add_filter( 'render_block_core/navigation-submenu', array( $this, 'linguator_render_custom_attributes' ), 10, 3 );

		return $this;
	}

	/**
	 * Returns the navigation language switcher block name with the Linguator's namespace.
	 *
	 *
	 * @return string The block name.
	 */
	protected function get_block_name() {
		return 'linguator/navigation-language-switcher';
	}

	/**
	 * Returns the attributes of the navigation language switcher block.
	 *
	 * @return array
	 */
	protected function get_attributes() {
		$attributes = parent::get_attributes();

		$attributes['layout'] = array(
			'type'    => 'string',
			'enum'    => array( 'horizontal', 'dropdown' ),
			'default' => 'horizontal',
		);

		return $attributes;
	}

	/**
	 * Returns the supported pieces of context for the 'linguator/navigation-language-switcher' block.
	 * This context will be inherited from the 'core/navigation' block.
	 *
	 *
	 * @return string[]
	 */
	protected function get_context() {
		return array(
			'textColor',
			'customTextColor',
			'backgroundColor',
			'customBackgroundColor',
			'overlayTextColor',
			'customOverlayTextColor',
			'overlayBackgroundColor',
			'customOverlayBackgroundColor',
			'fontSize',
			'customFontSize',
			'showSubmenuIcon',
			'maxNestingLevel',
			'openSubmenusOnClick',
			'style',
			'isResponsive', // Backward compatibility.
			'submenuVisibility',
		);
	}

	/**
	 * Renders the `linguator/navigation-language-switcher` block on server.
	 *

	 *
	 * @param array    $attributes The block attributes.
	 * @param string   $content The saved content. Unused.
	 * @param \WP_Block $block The parsed block.
	 * @return string The HTML string output to serve.
	 */
	public function render( $attributes, $content, $block ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( empty( $this->links ) ) {
			return '';
		}

		$attributes = $this->migrate_legacy_attributes( (array) $attributes, 'horizontal', 'dropdown' );
		$attributes = $this->set_attributes_for_block( $attributes );

		$attributes['className'] = isset( $attributes['className'] ) ? (string) $attributes['className'] : '';

		$previous_lang = $this->switch_to_edit_language();
		$output        = $this->render_switcher( $attributes, $block );
		$this->restore_language( $previous_lang );

		if ( '' === $output ) {
			return '';
		}

		// Final sanitization to ensure flag/name/output content is safe.
		$output = wp_kses( $output, $this->get_allowed_switcher_html() );

		if ( version_compare( $GLOBALS['wp_version'], '6.5-alpha', '<' ) ) {
			/*
			 * Backward compatibility with WordPress < 6.5.
			 * Since WordPress 6.5, our block is rendered automatically inside the auto-generated `<ul>` wrapper.
			 */
			return sprintf( '<ul class="wp-block-navigation__container wp-block-navigation">%s</ul>', $output );
		}

		return $output;
	}

	/**
	 * Renders the switcher as core navigation blocks.
	 *
	 * @param array     $attributes The block attributes.
	 * @param \WP_Block $block      The parsed block.
	 * @return string
	 */
	private function render_switcher( array $attributes, $block ): string {
		$settings = new Settings( $attributes );
		$elements = ( new Switcher( $settings, $this->links ) )->get_elements();

		if ( empty( $elements ) ) {
			return '';
		}

		$generated_class = wp_apply_generated_classname_support( $block->block_type );
		if ( ! empty( $generated_class['class'] ) ) {
			$attributes['className'] = trim( $attributes['className'] . ' ' . $generated_class['class'] );
		}

		if ( 'dropdown' === $settings->layout ) {
			$top_level_element = $this->get_top_level_element( $settings );

			if ( empty( $top_level_element ) ) {
				return '';
			}

			$inner_nav_link_blocks   = array();
			$attributes_wo_classname = array_merge( $attributes, array( 'className' => '' ) );

			foreach ( $elements as $element ) {
				$inner_nav_link_blocks[] = $this->create_inner_block( 'core/navigation-link', $attributes_wo_classname, $element, $block->context );
			}

			return (string) $this->create_inner_block( 'core/navigation-submenu', $attributes, $top_level_element, $block->context, $inner_nav_link_blocks )->render();
		}

		$output = '';

		foreach ( $elements as $element ) {
			$output .= $this->create_inner_block( 'core/navigation-link', $attributes, $element, $block->context )->render();
		}

		return $output;
	}

	/**
	 * Returns the element displayed at the top of the dropdown, matching the current language.
	 *
	 * @param Settings $settings Switcher settings.
	 * @return Element|null
	 */
	private function get_top_level_element( Settings $settings ) {
		$curlang = ! empty( $this->links->curlang ) ? $this->links->curlang : $this->model->get_default_language();

		if ( empty( $curlang ) ) {
			return null;
		}

		return new Element( $curlang, $settings, $this->links );
	}

	/**
	 * Register switcher menu item meta options as a REST API field.
	 *
	 *
	 * @return void
	 */
	public function linguator_register_switcher_menu_item_options_meta_rest_field() {
		register_post_meta(
			'nav_menu_item',
			'_lmat_menu_item',
			array(
				'object_subtype' => 'nav_menu_item',
				'description'    => __( 'Language switcher settings', 'linguator-pro' ),
				'single'         => true,
				'auth_callback'  => function( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_theme_options' );
				},
				'show_in_rest'   => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => array(
							'type' => array( 'boolean', 'integer', 'number', 'string' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Renders a core/naviagation-link or core/naviagation-submenu block by adding hreflang and lang attributes to the <a> tag
	 * and also the language flag if required.
	 *
	 *
	 * @param string   $block_content The block content.
	 * @param array    $block         The full block, including name and attributes.
	 * @param \WP_Block $instance      The block instance.
	 *
	 * @return string A formatted HTML string representing the core/navigation-link or core/navigation-submenu block.
	 */
	public function linguator_render_custom_attributes( $block_content, $block, $instance ) {
		if ( ! $this->internal_blocks->offsetExists( $instance ) ) {
			return $block_content;
		}

		$snapshot = $this->internal_blocks->offsetGet( $instance );
		$this->internal_blocks->offsetUnset( $instance );

		$content_tags = new \WP_HTML_Tag_Processor( $block_content );

		if ( 'core/navigation-submenu' === $instance->name ) {
			// If `openSubmenusOnClick`, the submenu is rendered as a button, so there are no `<a>` to process.
			if ( empty( $instance->context['openSubmenusOnClick'] ) && $content_tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
				$content_tags->set_attribute( 'hreflang', $snapshot['locale'] );
				$content_tags->set_attribute( 'lang', $snapshot['locale'] );
			}
			if ( $content_tags->next_tag( array( 'tag_name' => 'button' ) ) ) {
				$content_tags->set_attribute(
					'aria-label',
					str_replace(
						static::PLACEHOLDER,
						__( 'Languages', 'linguator-pro' ),
						(string) $content_tags->get_attribute( 'aria-label' )
					)
				);
			}
		} elseif ( $content_tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$content_tags->set_attribute( 'hreflang', $snapshot['locale'] );
			$content_tags->set_attribute( 'lang', $snapshot['locale'] );
		}

		$overridden_block_content = $content_tags->get_updated_html();

		return wp_kses(
			str_replace(
			static::PLACEHOLDER,
				$snapshot['label'],
			$overridden_block_content
			),
			$this->get_allowed_switcher_html()
		);
	}

	/**
	 * Creates a navigation block and keeps its generated label outside block attributes.
	 *
	 * @param string           $block_name   Core block name.
	 * @param array            $attributes   Language switcher block attributes.
	 * @param Abstract_Element $element      Switcher element of one language.
	 * @param array            $context      Parent block context.
	 * @param \WP_Block[]      $inner_blocks Optional child blocks.
	 * @return \WP_Block
	 */
	private function create_inner_block( string $block_name, array $attributes, Abstract_Element $element, array $context, array $inner_blocks = array() ) {
		$block = new \WP_Block(
			array(
				'blockName'   => $block_name,
				'attrs'       => $this->linguator_get_core_block_attributes( $attributes, $element ),
				'innerBlocks' => $inner_blocks,
			),
			$context
		);

		$this->internal_blocks->offsetSet(
			$block,
			array(
				'locale' => sanitize_text_field( (string) $element->locale ),
				'label'  => $this->apply_flag_styles_to_markup( $element->get_label(), $attributes ),
			)
		);

		return $block;
	}

	/**
	 * Returns attributes for a core navigation block created by the switcher.
	 *
	 * @param array            $attributes Language switcher block attributes.
	 * @param Abstract_Element $element    Switcher element of one language.
	 * @return array Attributes to be rendered by core.
	 */
	private function linguator_get_core_block_attributes( array $attributes, Abstract_Element $element ): array {
		$class_name = trim( implode( ' ', array_map( 'sanitize_html_class', (array) $element->item_classes ) ) );

		if ( ! empty( $attributes['show_flags'] ) ) {
			$aspect_ratio = ! empty( $attributes['flag_aspect_ratio'] ) ? (string) $attributes['flag_aspect_ratio'] : '3:2';
			$class_name  .= ' lmat-aspect-ratio-' . sanitize_html_class( str_replace( ':', '', $aspect_ratio ) );
		}

		if ( ! empty( $attributes['className'] ) ) {
			$class_name .= ' ' . $attributes['className'];
		}

		return array(
			'label'     => static::PLACEHOLDER,
			'url'       => esc_url_raw( (string) $element->url ),
			'className' => trim( $class_name ),
		);
	}
}
