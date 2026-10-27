<?php
/**
 * @package Linguator
 */

namespace Linguator\Includes\Switcher\Element;

use WP_Post;
use Linguator\Includes\Services\Links\Linguator_Links;
use Linguator\Includes\Other\Linguator_Language;
use Linguator\Frontend\Services\Linguator_Frontend_Links;
use Linguator\Includes\Models\Translated\Linguator_Translated_Post;
use WP_HTML_Tag_Processor;
use Linguator\Includes\Switcher\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data representing an item.
 */
abstract class Abstract_Element {
	/**
	 *
	 * @var int
	 */
	public $id;

	/**
	 *
	 * @var string
	 *
	 * @phpstan-var non-empty-string
	 */
	public $slug;

	/**
	 *
	 * @var string
	 *
	 * @phpstan-var non-empty-string
	 */
	public $locale;

	/**
	 *
	 * @var string
	 */
	public $url = '';

	/**
	 *
	 * @var string
	 */
	public $label = '';

	/**
	 *
	 * @var string
	 */
	public $flag = '';

	/**
	 *
	 * @var string
	 *
	 * @phpstan-var 'ltr'|'rtl'
	 */
	public $direction;

	/**
	 *
	 * @var int
	 */
	public $order;

	/**
	 *
	 * @var bool
	 */
	public $has_translations;

	/**
	 *
	 * @var bool
	 */
	public $is_empty;

	/**
	 *
	 * @var bool
	 */
	public $is_current;

	/**
	 *
	 * @var string[]
	 */
	public $item_classes;

	/**
	 *
	 * @var string[]
	 */
	public $link_classes;

	/**
	 * @var Linguator_Language
	 */
	private $language;

	/**
	 * @var Settings
	 */
	protected $settings;

	/**
	 * @var Linguator_Links
	 */
	private $links;

	/**
	 * @var Linguator_Translated_Post
	 */
	private $post_model;

	/**
	 * Constructor.
	 *
	 * @param Linguator_Language $language Instance of `Linguator_Language`.
	 * @param Settings     $settings Instance of `Settings`.
	 * @param Linguator_Links    $links    Instance of `Linguator_Links`.
	 */
	public function __construct( Linguator_Language $language, Settings $settings, Linguator_Links $links ) {
		$this->language   = $language;
		$this->settings   = $settings;
		$this->links      = $links;
		$this->post_model = $links->model->post;

		$this->id               = (int) $language->term_id;
		$this->slug             = $language->slug;
		$this->locale           = $language->get_locale( 'display' );
		$this->direction        = $language->is_rtl ? 'rtl' : 'ltr';
		$this->order            = (int) $language->term_group;
		$this->has_translations = true;
		$this->is_empty         = $language->get_tax_prop( 'lmat_language', 'count' ) <= 0;
		$this->is_current       = $this->get_current_language_slug() === $language->slug;
		$this->item_classes     = array_merge(
			array( 'linguator-item', 'lang-item', "lang-item-{$language->term_id}", "lang-item-{$language->slug}" ),
			$settings->item_classes
		);
		$this->link_classes     = $settings->link_classes;

		if ( $this->is_current ) {
			$this->item_classes[] = 'current-lang';
		}
		if ( $this->settings->show_labels ) {
			$this->label = 'codes' === $this->settings->show_labels ? strtoupper( $this->language->slug ) : $this->language->name;
		}

		$this->set_flag();
		$this->set_url();
	}

	/**
	 * Returns the markup of a row.
	 *
	 * @return string
	 */
	abstract public function get(): string;

	/**
	 * Returns the markup of the label of a row.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return esc_html( $this->label );
	}

	/**
	 * Returns the current language code.
	 *
	 * @return string
	 */
	private function get_current_language_slug(): string {
		if ( ! empty( $this->links->curlang ) ) {
			return $this->links->curlang->slug;
		}

		return $this->links->options['default_lang'];
	}

	/**
	 * Sets the flag of the element.
	 *
	 * @return void
	 */
	private function set_flag(): void {
		if ( ! $this->settings->show_flags ) {
			return;
		}

		$this->flag = $this->language->get_display_flag( $this->settings->show_labels ? 'no-alt' : 'alt' );

		if ( empty( $this->flag ) ) {
			return;
		}

		$flag = new WP_HTML_Tag_Processor( $this->flag );

		if ( ! $flag->next_tag( array( 'tag_name' => 'img' ) ) ) {
			return;
		}

		// Replaces the inline size of the flag image.
		if ( ! $flag->set_attribute( 'style', 'display:block;object-fit:cover;object-position:center;width:100%;height:100%' ) ) {
			return;
		}

		$this->flag = $flag->get_updated_html();
	}

	/**
	 * Sets the URL of the element.
	 *
	 * @return void
	 */
	private function set_url(): void {
		$url = $this->get_original_url();

		if ( empty( $url ) ) {
			$this->has_translations = false;
			$this->item_classes[]   = 'no-translation';
		}

		/**
		 * Filter the link in the language switcher.
		 *
		 * @param string $url    The link URL, an empty string if no translation was found.
		 * @param string $slug   The language code.
		 * @param string $locale The language locale.
		 */
		$url = apply_filters( 'lmat_the_language_link', $url, $this->language->slug, $this->language->locale );

		if ( empty( $url ) || ! is_string( $url ) || $this->settings->force_home ) {
			$this->url = $this->links->get_home_url( $this->language );
		} else {
			$this->url = $url;
		}
	}

	/**
	 * Returns the original URL of the element.
	 *
	 * @return string
	 */
	private function get_original_url(): string {
		global $post;

		// Priority to the post passed in parameters.
		if ( $this->settings->post_id ) {
			$tr_id = $this->post_model->get( $this->settings->post_id, $this->language );

			if ( $tr_id && $this->post_model->current_user_can_read( $tr_id ) ) {
				return (string) get_permalink( $tr_id );
			}
		}

		// If we are on frontend.
		if ( $this->links instanceof Linguator_Frontend_Links ) {
			return $this->links->get_translation_url( $this->language );
		}

		// For blocks in posts in REST requests.
		if ( $post instanceof WP_Post ) {
			$tr_id = $this->post_model->get( $post->ID, $this->language );

			if ( $tr_id && $this->post_model->current_user_can_read( $tr_id ) ) {
				return (string) get_permalink( $tr_id );
			}
		}

		return '';
	}
}
