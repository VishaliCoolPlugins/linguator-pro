<?php

namespace Linguator\Modules\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @package Linguator
 */

/**
 * Abstract class for language switcher block.
 *
 */
abstract class Linguator_Abstract_Language_Switcher_Block {
	/**
	 * Returns the allowed HTML tags/attributes for switcher output.
	 *
	 * This is used to sanitize server-rendered block output and any injected
	 * flag markup (e.g. <img>) before returning it to WordPress for rendering.
	 *
	 * @return array
	 */
	protected function get_allowed_switcher_html() {
		return array(
			'nav'    => array( 'aria-label' => true, 'class' => true, 'role' => true ),
			'div'    => array( 'class' => true ),
			'ul'     => array( 'class' => true ),
			'li'     => array( 'class' => true ),
			'a'      => array( 'href' => true, 'class' => true, 'title' => true, 'hreflang' => true, 'lang' => true, 'rel' => true, 'target' => true ),
			'span'   => array( 'class' => true, 'style' => true ),
			'img'    => array( 'src' => true, 'alt' => true, 'class' => true, 'width' => true, 'height' => true, 'style' => true, 'loading' => true, 'decoding' => true ),
			'label'  => array( 'for' => true, 'class' => true ),
			'select' => array( 'id' => true, 'name' => true, 'class' => true, 'aria-label' => true ),
			'option' => array( 'value' => true, 'selected' => true ),
		);
	}

	/**
	 * @var Linguator_Links
	 */
	protected $links;

	/**
	 * @var Linguator_Model
	 */
	protected $model;

	/**
	 * Current lang to render the language switcher block in an admin context.
	 *
	 *
	 * @var string|null
	 */
	protected $admin_current_lang;

	/**
	 * Is it the edit context?
	 *
	 * @var bool
	 */
	protected $is_edit_context = false;

	/**
	 * Constructor
	 *
	 *
	 * @param Linguator_Base $linguator Linguator object.
	 */
	public function __construct( &$linguator ) {
		$this->model = &$linguator->model;
		$this->links = &$linguator->links;
	}

	/**
	 * Adds the required hooks.
	 *
	 *
	 * @return self
	 */
	public function init() {
		// Use rest_pre_dispatch_filter to get additional parameters for language switcher block.
		add_filter( 'rest_pre_dispatch', array( $this, 'linguator_get_rest_query_params' ), 10, 3 );

		// Register language switcher block.
		add_action( 'init', array( $this, 'register' ) );

		return $this;
	}

	/**
	 * Returns the block name with the Linguator's namespace.
	 *
	 *
	 * @return string The block name.
	 */
	abstract protected function get_block_name();

	/**
	 * Renders the Linguator's block on server.
	 *
	 *  Accepts two new parameters, $content and $block.
	 *
	 * @param array    $attributes The block attributes.
	 * @param string   $content    The saved content.
	 * @param \WP_Block $block      The parsed block.
	 * @return string The HTML string output to serve.
	 */
	abstract public function render( $attributes, $content, $block );

	/**
	 * Returns the supported pieces of inherited context for the block, by default none are supported..
	 *
	 * @return array An array with context subject, default to empty.
	 */
	protected function get_context() {
		return array();
	}

	/**
	 * Returns the block attributes.
	 *
	 * @return array
	 */
	protected function get_attributes() {
		return array(
			'layout'                 => array(
				'type'    => 'string',
				'enum'    => array( 'vertical', 'horizontal', 'dropdown', 'select' ),
				'default' => 'vertical',
			),
			'show_labels'            => array(
				'type'    => 'string',
				'enum'    => array( 'names', 'codes', '' ),
				'default' => 'names',
			),
			'show_flags'             => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'force_home'             => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'hide_current'           => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'hide_if_no_translation' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'flag_aspect_ratio'      => array(
				'type'    => 'string',
				'enum'    => array( '3:2', '1:1' ),
				'default' => '3:2',
			),
			'flag_border_radius'     => array(
				'type'    => 'number',
				'default' => 0,
			),
			'flag_width'             => array(
				'type'    => 'string',
				'default' => '18px',
			),
			'flag_label_spacing'     => array(
				'type'    => 'string',
				'default' => '0.3em',
			),
			'className'              => array(
				'type'    => 'string',
				'default' => '',
			),
			'lmatLang'               => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Converts the attributes of blocks saved before the layout settings, when not migrated in the editor yet.
	 *
	 * @param array  $attributes      The block attributes.
	 * @param string $default_layout  Layout used for legacy blocks not displayed as dropdown.
	 * @param string $dropdown_layout Layout used for legacy blocks displayed as dropdown.
	 * @return array
	 */
	protected function migrate_legacy_attributes( array $attributes, string $default_layout = 'vertical', string $dropdown_layout = 'select' ): array {
		if ( ! array_key_exists( 'dropdown', $attributes ) && ! array_key_exists( 'show_names', $attributes ) ) {
			return $attributes;
		}

		$show_names = ! isset( $attributes['show_names'] ) || ! empty( $attributes['show_names'] );

		$attributes['layout']      = empty( $attributes['dropdown'] ) ? $default_layout : $dropdown_layout;
		$attributes['show_labels'] = $show_names ? 'names' : '';
		$attributes['show_flags']  = $show_names ? ! empty( $attributes['show_flags'] ) : true;

		unset( $attributes['dropdown'], $attributes['show_names'] );

		return $attributes;
	}

	/**
	 * Sets the language chosen in the editor as current language while rendering a preview.
	 *
	 * @return mixed Previous current language, false if unchanged.
	 */
	protected function switch_to_edit_language() {
		if ( ! $this->is_edit_context || empty( $this->admin_current_lang ) || empty( $this->links ) ) {
			return false;
		}

		$language = $this->model->get_language( $this->admin_current_lang );

		if ( empty( $language ) ) {
			return false;
		}

		$previous             = $this->links->curlang;
		$this->links->curlang = $language;

		return $previous;
	}

	/**
	 * Restores the current language changed by {@see self::switch_to_edit_language()}.
	 *
	 * @param mixed $previous Previous current language, false if unchanged.
	 * @return void
	 */
	protected function restore_language( $previous ) {
		if ( false !== $previous ) {
			$this->links->curlang = $previous;
		}
	}

	/**
	 * Returns additional block type arguments such as supports and styles.
	 *
	 * @return array
	 */
	protected function get_block_args() {
		return array();
	}

	/**
	 * Adds the flag size, shape and label spacing CSS variables to the switcher markup.
	 *
	 * @param string $html       Switcher HTML.
	 * @param array  $attributes Block attributes.
	 * @return string
	 */
	protected function apply_flag_styles_to_markup( string $html, array $attributes ): string {
		if ( empty( $attributes['show_flags'] ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$border_radius = max( 0, min( 100, (int) ( $attributes['flag_border_radius'] ?? 0 ) ) );
		$flag_width    = $attributes['flag_width'] ?? '18px';
		$flag_style    = safecss_filter_attr(
			sprintf(
				'--lmat-flag-border-radius:%1$d;--lmat-flag-width:%2$s',
				$border_radius,
				$flag_width
			)
		);

		$label_style = '';
		if ( ! empty( $attributes['show_labels'] ) ) {
			$label_style = safecss_filter_attr(
				sprintf(
					'--lmat-flag-label-spacing:%1$s',
					$attributes['flag_label_spacing'] ?? '0.3em'
				)
			);
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( array( 'tag_name' => 'SPAN', 'class_name' => 'lmat-switcher-flag' ) ) ) {
			$style = $processor->get_attribute( 'style' ) ?? '';
			$processor->set_attribute( 'style', '' === $style ? $flag_style : rtrim( $style, ';' ) . ';' . $flag_style );

			if ( '' !== $label_style && $processor->next_tag( array( 'tag_name' => 'SPAN', 'class_name' => 'lmat-switcher-label' ) ) ) {
				$style = $processor->get_attribute( 'style' ) ?? '';
				$processor->set_attribute( 'style', '' === $style ? $label_style : rtrim( $style, ';' ) . ';' . $label_style );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Registers the Linguator's block.
	 *
	 *  Renamed and now handle any type of block registration based on a dynamic name.
	 *
	 * @return void
	 */
	public function register() {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $this->get_block_name() ) ) {
		
			// Don't register a block more than once or WordPress send an error. See https://github.com/WordPress/wordpress-develop/blob/5.9/src/wp-includes/class-wp-block-type-registry.php#L82-L90
			return;
		}

		$script_handle = 'lmat_blocks';
		linguator_register_script(
			'blocks',
			array(
				'wp-block-editor',
				'wp-blocks',
				'wp-components',
				'wp-element',
				'wp-i18n',
				'wp-data',
				'wp-notices',
				'wp-hooks',
				'wp-server-side-render',
				'lodash',
			),
			array( 'in_footer' => true ),
			'lmat_'
		);

		// Ensure the block editor script is enqueued in the editor context
		add_action( 'enqueue_block_editor_assets', function() use ( $script_handle ) {
			
			if ( ! wp_script_is( $script_handle, 'enqueued' ) ) {
				wp_enqueue_script( $script_handle );
			}
		}, 20 );

		$block_registration = register_block_type(
			$this->get_block_name(),
			array_merge(
				array(
					'editor_script'   => $script_handle,
					'attributes'      => $this->get_attributes(),
					'render_callback' => array( $this, 'render' ),
					'uses_context'    => $this->get_context(),
				),
				$this->get_block_args()
			)
		);

		// Translated strings used in JS code
		wp_set_script_translations( $script_handle, 'linguator-pro' );
	}

	/**
	 * Returns the REST parameters for language switcher block.
	 * Used to store the request's language and context locally.
	 * Previously was in the `Linguator_Block_Editor_Switcher_Block` class.
	 *
	 * @see WP_REST_Server::dispatch()
	 *
	 *
	 * @param mixed           $result  Response to replace the requested version with. Can be anything
	 *                                 a normal endpoint can return, or null to not hijack the request.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 * @template T of WP_REST_Request
	 * @phpstan-param T $request
	 */
	public function linguator_get_rest_query_params( $result, $server, $request ) {
		if ( linguator_is_edit_rest_request( $request ) ) {
			$this->is_edit_context = true;

			$lang = $request->get_param( 'lang' );
			if ( is_string( $lang ) && ! empty( $lang ) ) {
				$this->admin_current_lang = $lang;
			}
		}
		return $result;
	}

	/**
	 * Adds the attributes to render the block correctly.
	 * Also specifies not to echo the switcher in any case.
	 *
	 *
	 * @param array $attributes The attributes of the currently rendered block.
	 * @return array The modified attributes.
	 */
	protected function set_attributes_for_block( $attributes ) {
		$attributes['echo'] = 0;
		if ( $this->is_edit_context ) {
			$attributes['admin_render']           = 1;
			$attributes['admin_current_lang']     = $this->admin_current_lang;
			$attributes['hide_if_empty']          = 0;
			$attributes['hide_if_no_translation'] = 0; // Force not to hide the language for the block preview even if the option is checked.
		}
		return $attributes;
	}
}

