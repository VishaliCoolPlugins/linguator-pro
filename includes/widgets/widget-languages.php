<?php
/**
 * @package Linguator
 */

namespace Linguator\Includes\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Includes\Switcher\Assets;
use Linguator\Includes\Switcher\Fields\Widget as Fields;
use Linguator\Includes\Switcher\Settings\Settings;
use Linguator\Includes\Switcher\Switcher;
use WP_Widget;

/**
 * The language switcher widget.
 */
class Linguator_Widget_Languages extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'linguator_widget',
			__( 'Language switcher', 'linguator-pro' ),
			array(
				'description'                 => __( 'Displays a language switcher', 'linguator-pro' ),
				'customize_selective_refresh' => true,
			)
		);

		if ( is_active_widget( false, false, $this->id_base ) || is_customize_preview() ) {
			add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue_frontend_styles' ) );
		}
	}

	/**
	 * Displays the widget.
	 *
	 * @param array $args     Display arguments including before_title, after_title, before_widget, and after_widget.
	 * @param array $instance The settings for the particular instance of the widget.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$instance = Fields::remove_legacy_settings( (array) $instance );

		$instance['unique_id']    = "lmat-switcher-widget-{$this->number}";
		$instance['show_wrapper'] = true;

		// Legacy instances without `layout` are converted from their `dropdown` and `show_names` keys.
		if ( ! isset( $instance['layout'] ) ) {
			$instance['layout'] = empty( $instance['dropdown'] ) ? 'vertical' : 'select';
		}

		$linguator = function_exists( 'LMAT' ) ? LMAT() : null;

		if ( empty( $linguator->links ) ) {
			return;
		}

		$settings = new Settings( $instance );
		$list     = ( new Switcher( $settings, $linguator->links ) )->get();

		if ( empty( $list ) ) {
			return;
		}

		/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
		$title = apply_filters( 'widget_title', $instance['title'] ?? '', $instance, $this->id_base );

		echo wp_kses_post( $args['before_widget'] );

		if ( $title ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );
		}

		echo $list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the switcher elements.

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Updates the widget options, storing new settings alongside legacy keys.
	 *
	 * @param array $new_instance New settings for this instance as input by the user via form().
	 * @param array $old_instance Old settings for this instance.
	 * @return array Settings to save.
	 */
	public function update( $new_instance, $old_instance ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$new_instance = (array) $new_instance;

		// Unchecked checkboxes are not posted.
		foreach ( Fields::get() as $key => $field ) {
			if ( empty( $field['choices'] ) && ! isset( $new_instance[ $key ] ) ) {
				$new_instance[ $key ] = 0;
			}
		}

		$validated = Fields::filter( new Settings( Fields::remove_legacy_settings( $new_instance ) ) );
		$validated = Fields::add_legacy_settings( $validated );

		$validated['title'] = ! empty( $new_instance['title'] ) && is_string( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';

		return $validated;
	}

	/**
	 * Displays the widget form.
	 *
	 * @param array $instance Current settings.
	 * @return string
	 */
	public function form( $instance ) {
		$instance = (array) $instance;

		// Legacy instances without `layout` are converted from their `dropdown` and `show_names` keys.
		if ( ! empty( $instance ) && ! isset( $instance['layout'] ) ) {
			$instance['layout'] = empty( $instance['dropdown'] ) ? 'vertical' : 'select';
		}

		$settings = new Settings( Fields::remove_legacy_settings( $instance ) );
		$title    = ! empty( $instance['title'] ) && is_string( $instance['title'] ) ? $instance['title'] : '';

		printf(
			'<p><label for="%1$s">%2$s</label><input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s" /></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Title:', 'linguator-pro' ),
			esc_attr( $this->get_field_name( 'title' ) ),
			esc_attr( $title )
		);

		echo '<table role="presentation" class="lmat-language-switcher-widget-content"><tbody>';

		foreach ( Fields::get() as $key => $field ) {
			if ( ! empty( $field['section'] ) ) {
				$this->print_section_heading( $field, $settings );
			}
			if ( ! empty( $field['choices'] ) ) {
				$this->print_select( $key, $field, $settings );
			} else {
				$this->print_checkbox( $key, $field, $settings );
			}
		}

		echo '</tbody></table>';

		return '';
	}

	/**
	 * Prints a section heading row.
	 *
	 * @param array    $field    Field data.
	 * @param Settings $settings Switcher settings.
	 * @return void
	 */
	private function print_section_heading( array $field, Settings $settings ): void {
		printf( '<tr%s>', $this->get_wrapper_class_attr( $field, $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<td colspan="2"><h4>%s</h4></td></tr>', esc_html( $field['section'] ) );
	}

	/**
	 * Prints a select field row.
	 *
	 * @param string   $key      Field key.
	 * @param array    $field    Field data.
	 * @param Settings $settings Switcher settings.
	 * @return void
	 */
	private function print_select( string $key, array $field, Settings $settings ): void {
		printf( '<tr%s>', $this->get_wrapper_class_attr( $field, $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<th><label for="%s">%s</label></th>',
			esc_attr( $this->get_field_id( $key ) ),
			esc_html( $field['label'] )
		);
		printf(
			'<td><select data-key="%1$s" id="%2$s" name="%3$s">',
			esc_attr( $key ),
			esc_attr( $this->get_field_id( $key ) ),
			esc_attr( $this->get_field_name( $key ) )
		);
		foreach ( $field['choices'] as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $settings->$key, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';
	}

	/**
	 * Prints a checkbox field row.
	 *
	 * @param string   $key      Field key.
	 * @param array    $field    Field data.
	 * @param Settings $settings Switcher settings.
	 * @return void
	 */
	private function print_checkbox( string $key, array $field, Settings $settings ): void {
		printf( '<tr%s>', $this->get_wrapper_class_attr( $field, $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<td colspan="2"><input type="checkbox" data-key="%1$s" class="checkbox" id="%2$s" name="%3$s" value="1"%4$s/><label for="%2$s">%5$s</label></td>',
			esc_attr( $key ),
			esc_attr( $this->get_field_id( $key ) ),
			esc_attr( $this->get_field_name( $key ) ),
			checked( $settings->$key, true, false ),
			esc_html( $field['label'] )
		);
		echo '</tr>';
	}

	/**
	 * Returns the class attribute used to conditionally hide a row.
	 *
	 * @param array    $field    Field data.
	 * @param Settings $settings Switcher settings.
	 * @return string
	 */
	private function get_wrapper_class_attr( array $field, Settings $settings ): string {
		if ( empty( $field['hide_if'] ) ) {
			return '';
		}

		$classes = array();

		foreach ( $field['hide_if'] as $k => $value ) {
			if ( $settings->$k === $value ) {
				$classes[] = "lmat-hidden-by-{$k}";
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$classes[] = "lmat-hidden-if-{$k}-{$value}";
		}

		return sprintf( ' class="%s"', esc_attr( implode( ' ', $classes ) ) );
	}
}
