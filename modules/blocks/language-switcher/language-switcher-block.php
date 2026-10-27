<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Modules\Blocks;

use LinguatorPro\Includes\Switcher\Assets;
use LinguatorPro\Includes\Switcher\Settings\Settings;
use LinguatorPro\Includes\Switcher\Switcher;

/**
 * Language switcher block.
 */
class Linguator_Language_Switcher_Block extends Linguator_Abstract_Language_Switcher_Block {
	public const EDITOR_STYLE_HANDLE = 'lmat-language-switcher-editor';

	/**
	 * Adds the required hooks.
	 *
	 * @return self
	 */
	public function init() {
		parent::init();

		add_action( 'init', array( $this, 'register_styles' ), 5 );
		add_action( 'init', array( Assets::class, 'register_scripts' ), 5 );

		return $this;
	}

	/**
	 * Registers the frontend and editor styles of the block.
	 *
	 * @return void
	 */
	public function register_styles(): void {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		Assets::register_styles();
		wp_register_style(
			self::EDITOR_STYLE_HANDLE,
			plugins_url( "admin/assets/css/build/language-switcher-editor{$suffix}.css", LINGUATOR_PRO_ROOT_FILE ),
			array( Assets::FRONTEND_ASSET_HANDLE ),
			LINGUATOR_PRO_VERSION
		);
	}

	/**
	 * Returns the language switcher block name with the Linguator's namespace.
	 *
	 * @return string The block name.
	 */
	protected function get_block_name() {
		return 'linguator/language-switcher';
	}

	/**
	 * Returns the block supports and styles.
	 *
	 * @return array
	 */
	protected function get_block_args() {
		return array(
			'supports'      => array(
				'spacing'    => array(
					'margin'   => true,
					'padding'  => true,
					'blockGap' => true,
				),
				'align'      => true,
				'color'      => array(
					'background' => true,
				),
				'typography' => array(
					'fontSize'   => true,
					'lineHeight' => true,
					'textAlign'  => true,
				),
			),
			'style'         => Assets::FRONTEND_ASSET_HANDLE,
			'editor_style'  => self::EDITOR_STYLE_HANDLE,
			'view_script'   => Assets::FRONTEND_ASSET_HANDLE,
		);
	}

	/**
	 * Renders the `linguator/language-switcher` block on server.
	 *
	 * @param array     $attributes The block attributes.
	 * @param string    $content The saved content. Unused.
	 * @param \WP_Block $block The parsed block. Unused.
	 * @return string Returns the language switcher.
	 */
	public function render( $attributes, $content, $block ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		static $block_id = 0;
		++$block_id;

		$attributes = $this->migrate_legacy_attributes( (array) $attributes );
		$attributes = $this->set_attributes_for_block( $attributes );

		$attributes['show_wrapper'] = true;
		$attributes['unique_id']    = 'select' === $attributes['layout'] ? 'lang_choice_' . $block_id : 'lmat-switcher-block-' . $block_id;

		if ( ! empty( $attributes['style']['typography']['textAlign'] ) ) {
			$attributes['alignment'] = $attributes['style']['typography']['textAlign'];
		}

		if ( empty( $this->links ) ) {
			return '';
		}

		$settings        = new Settings( $attributes );
		$previous_lang   = $this->switch_to_edit_language();
		$switcher_output = ( new Switcher( $settings, $this->links ) )->get();
		$this->restore_language( $previous_lang );

		if ( empty( $switcher_output ) ) {
			return '';
		}

		Assets::enqueue_frontend_styles();

		$switcher_output = $this->apply_flag_styles_to_markup( $switcher_output, $attributes );

		return $this->apply_block_wrapper_attributes( $switcher_output, get_block_wrapper_attributes() );
	}

	/**
	 * Merges block wrapper attributes onto the switcher root element.
	 *
	 * @param string $html               Switcher HTML.
	 * @param string $wrapper_attributes Attributes from `get_block_wrapper_attributes()`.
	 * @return string
	 */
	private function apply_block_wrapper_attributes( string $html, string $wrapper_attributes ): string {
		if ( '' === trim( $wrapper_attributes ) ) {
			return $html;
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return sprintf( '<div %1$s>%2$s</div>', $wrapper_attributes, $html );
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag() ) {
			return sprintf( '<div %1$s>%2$s</div>', $wrapper_attributes, $html );
		}

		foreach ( $this->parse_html_attributes( $wrapper_attributes ) as $name => $value ) {
			if ( 'class' === $name ) {
				$existing = $processor->get_attribute( 'class' ) ?? '';
				$value    = trim( $existing . ' ' . $value );
			} elseif ( 'style' === $name ) {
				$existing = $processor->get_attribute( 'style' ) ?? '';
				$value    = '' === $existing ? $value : rtrim( $existing, ';' ) . ';' . $value;
			}

			$processor->set_attribute( $name, $value );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Parses an HTML attributes string.
	 *
	 * @param string $attributes_string HTML attributes.
	 * @return array
	 */
	private function parse_html_attributes( string $attributes_string ): array {
		$attributes = array();
		$processor  = new \WP_HTML_Tag_Processor( '<div ' . $attributes_string . '></div>' );

		if ( ! $processor->next_tag() ) {
			return $attributes;
		}

		foreach ( array( 'class', 'style', 'id' ) as $name ) {
			$value = $processor->get_attribute( $name );

			if ( null !== $value && '' !== $value ) {
				$attributes[ $name ] = $value;
			}
		}

		return $attributes;
	}
}
