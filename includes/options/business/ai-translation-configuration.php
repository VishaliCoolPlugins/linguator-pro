<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Includes\Options\Business;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use LinguatorPro\Includes\Options\Abstract_Option;
use LinguatorPro\Includes\Options\Options;

/**
 * Class defining post types list option.
 *
 *  
 */
class Ai_Translation_Configuration extends Abstract_Option {
	/**
	 * Returns option key.
	 *
	 *  
	 *
	 * @return string
	 *
	 * @phpstan-return 'post_types'
	 */
	public static function key(): string {
		return 'ai_translation_configuration';
	}

    /**
     * Returns the default value.
     *
     *  
     *
     * @return array
     */
    protected function get_default() {
        $data= array(
            'provider' => array(
                'chrome_local_ai' => false,
                'edge_local_ai' => false,
                'google' => true,
                'gemini' => false,
            ),
			'default_provider'             => 'google',
            'bulk_translation_post_status' => 'draft',
            'slug_translation_option' => 'title_translate',
        );

        return $data;
    }

    /**
     * Returns the JSON schema part specific to this option.
     *
     *  
     *
     * @return array Partial schema.
     */
    protected function get_data_structure(): array {
        return array(
            'type' => 'object',
            'properties' => array(
                'provider' => array(
                    'type' => 'object',
                    'properties' => array(
                        'chrome_local_ai' => array('type' => 'boolean'),
                        'edge_local_ai' => array('type' => 'boolean'),
                        'google' => array('type' => 'boolean'),
                        'gemini' => array('type' => 'boolean'),
                    )
                ),
				'default_provider'             => array(
					'type' => 'string',
					'enum' => array( '', 'google', 'chrome_local_ai', 'edge_local_ai', 'gemini' ),
				),
                'bulk_translation_post_status' => array('type' => 'string', 'enum' => array('draft', 'publish')),
                'slug_translation_option' => array('type' => 'string', 'enum' => array('title_translate', 'slug_translate', 'slug_keep')),
            ),
        );
    }

    
	/**
	 * Sanitizes option's value.
	 * Can populate the `$errors` property with blocking and non-blocking errors: in case of non-blocking errors,
	 * the value is sanitized and can be stored.
	 *
	 *  
	 *
	 * @param array   $value   Value to sanitize.
	 * @param Options $options All options.
	 * @return array|WP_Error The sanitized value. An instance of `WP_Error` in case of blocking error.
	 *
	 * @phpstan-return DomainsValue|WP_Error
	 */
	protected function sanitize( $value, Options $options ) {
        $filtered_value = array();
        $data_structure=self::get_data_structure();
        $provider_data=array_keys($data_structure['properties']['provider']['properties']);
		$allowed_providers = function_exists( 'linguator_pro_get_allowed_ai_providers' )
			? linguator_pro_get_allowed_ai_providers()
			: array( 'chrome_local_ai', 'google' );

        if(isset($value['provider'])){
            $filtered_value['provider'] = array();

            foreach($value['provider'] as $key => $provider_value){
                if ( ! in_array( $key, $provider_data, true ) || ! in_array( $key, $allowed_providers, true ) ) {
                    continue;
                }
                $filtered_value['provider'][$key] = filter_var($provider_value, FILTER_VALIDATE_BOOLEAN);
            }
        }

		// Callers such as the setup wizard only send the provider switches, so keep the saved default instead of dropping it.
		if ( isset( $value['default_provider'] ) ) {
			$default_provider = (string) $value['default_provider'];

			if ( '' === $default_provider || ( in_array( $default_provider, $provider_data, true ) && in_array( $default_provider, $allowed_providers, true ) ) ) {
				$filtered_value['default_provider'] = $default_provider;
			}
		}

		if ( ! isset( $filtered_value['default_provider'] ) ) {
			$saved_value = $options->get( self::key() );

			if ( is_array( $saved_value ) && isset( $saved_value['default_provider'] ) ) {
				$filtered_value['default_provider'] = $saved_value['default_provider'];
			}
		}

        if(isset($value['bulk_translation_post_status']) && in_array($value['bulk_translation_post_status'], array('draft', 'publish'))){
            $filtered_value['bulk_translation_post_status'] = sanitize_text_field($value['bulk_translation_post_status']);
        }

        if(isset($value['slug_translation_option']) && in_array($value['slug_translation_option'], array('title_translate', 'slug_translate', 'slug_keep'))){
            $filtered_value['slug_translation_option'] = sanitize_text_field($value['slug_translation_option']);
        }

        return $filtered_value;
    }

	/**
	 * Returns the description used in the JSON schema.
	 *
	 *  
	 *
	 * @return string
	 */
	protected function get_description(): string {
		return __( 'List of post types to translate.', 'linguator-pro' );
	}
}
