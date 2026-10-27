<?php
namespace Linguator\Supported_Blocks;

use Linguator\Settings\Header\Header;
use WP_Block_Type_Registry;

/**
 * Do not access the page directly
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Supported_Blocks' ) ) {
	/**
	 * Class Supported_Blocks
	 *
	 * This class handles the supported blocks for the Linguator plugin.
	 *
	 * @package Linguator
	 */
	class Supported_Blocks {
		/**
		 * Singleton instance.
		 *
		 * @var Supported_Blocks
		 */
		private static $instance = null;


		/**
		 * Stores custom block data for processing and retrieval.
		 *
		 * @var array
		 */

		/**
		 * Linguator plugin category.
		 *
		 * @var array
		 */
		private $linguator_plugin_category = array();

		/**
		 * Get the singleton instance of the class.
		 *
		 * @return Supported_Blocks
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 *
		 * @return void
		 */
		public function __construct(){
			add_filter('lmat_frontend_settings_assets', array($this, 'stop_frontend_setting_assets'), 10, 3);
			add_filter('lmat_admin_settings_assets', array($this, 'linguator_supported_blocks_assets'), 10, 3);
			add_filter('lmat_render_languages_page', array($this, 'linguator_render_supported_blocks_page'), 10, 3);
			add_action( 'upgrader_process_complete', array( $this, 'linguator_clear_wpml_blocks_cache' ) );
			add_action( 'activated_plugin', array( $this, 'linguator_clear_wpml_blocks_cache' ) );
			add_action( 'deactivated_plugin', array( $this, 'linguator_clear_wpml_blocks_cache' ) );
			add_action( 'switch_theme', array( $this, 'linguator_clear_wpml_blocks_cache' ) );
		}

		/*
		Filter to enqueue the admin supported blocks assets
		@param bool $status
		@param string $tab
		@param bool $is_settings_tab
		@return bool
		*/
		public function linguator_supported_blocks_assets($status, $tab, $is_settings_tab){
			if($is_settings_tab && $tab === 'supported-blocks' && function_exists('LMAT')){

				$header = Header::get_instance('supported-blocks', LMAT()->model);
				$header->header_assets();

				linguator_enqueue_datatable_assets();
				
				return false;
			}

			return $status;
		}

		/*
		Filter to stop the admin assets on frontend settings page
		@param bool $status
		@param string $tab
		@param bool $is_settings_tab
		@return bool
		*/
		public function stop_frontend_setting_assets($status, $tab, $is_settings_tab){
			if($is_settings_tab && $tab === 'supported-blocks'){
				return false;
			}

			return $status;
		}

		/*
		Filter to render the supported blocks page
		@param bool $status
		@param string $selected_tab
		@param string $active_tab
		@return bool
		*/
		public function linguator_render_supported_blocks_page($status, $selected_tab, $active_tab) {
			if($selected_tab === 'supported-blocks' && $active_tab === 'settings'){

				$header = Header::get_instance('supported-blocks', LMAT()->model);

				$header->header();

				$this->render_supported_blocks_page();
				return false;
			}

			return $status;
		}

		/**
		 * Render the support blocks page.
		 */
		public function render_supported_blocks_page() {
			?>
		<div class="lmat-custom-data-table-wrapper">
			<h3><?php echo esc_html__('Supported Blocks Translation Settings', 'linguator-pro'); ?>
			<br><p>
			<?php 
				// translators: %s: Linguator.
				printf( esc_html__( 'Manage Gutenberg blocks to make them translation-ready with %s.', 'linguator-pro' ), 'Linguator' ); 
			?></p>
			</h3>
			<div class="lmat-custom-data-table-filters">
				<div class="lmat-filter-tab" data-column="1" data-default="all">
					<label for="lmat-blocks-category"><?php esc_html_e( 'Block Type Category:', 'linguator-pro' ); ?></label>
					<select id="lmat-blocks-category" name="lmat_blocks_category">
						<option value="all"><?php esc_html_e( 'All', 'linguator-pro' ); ?></option>
						<option value="core">Core</option>
						<?php $this->linguator_get_blocks_category(); ?>
					</select>
				</div>
				<div class="lmat-filter-tab" data-column="3" data-default="all">
					<label for="lmat-blocks-filter"><?php esc_html_e( 'Show Blocks:', 'linguator-pro' ); ?></label>
					<select id="lmat-blocks-filter" name="lmat_blocks_filter">
						<option value="all"><?php esc_html_e( 'All', 'linguator-pro' ); ?></option>
						<option value="supported"><?php esc_html_e( 'Supported Blocks', 'linguator-pro' ); ?></option>
						<option value="unsupported"><?php esc_html_e( 'Unsupported Blocks', 'linguator-pro' ); ?></option>
					</select>
				</div>
			</div>
			<div class="lmat-custom-table-section">
				<div class="lmat-custom-table-lists">
					<table class="lmat-custom-data-table-table" id="lmat-custom-datatable">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Sr.No', 'linguator-pro' ); ?></th>
								<th><?php esc_html_e( 'Block Name', 'linguator-pro' ); ?></th>
								<th><?php esc_html_e( 'Block Title', 'linguator-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'linguator-pro' ); ?></th>
								<th><?php esc_html_e( 'Modify', 'linguator-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php 
								$this->linguator_get_supported_blocks_table()
							?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
			<?php
		}

		/**
		 * Get the blocks category.
		 */
		public function linguator_get_blocks_category() {
			$blocks_data                 = WP_Block_Type_Registry::get_instance()->get_all_registered();

			$filter_blocks_data = array_filter( $blocks_data, function( $block ) {
				return !in_array($block->category, array( 'media', 'reusable' ));
			} );
			foreach ( $filter_blocks_data as $block ) {
				$plugin_name = explode('/', $block->name);
				$plugin_name = isset($plugin_name[0]) ? $plugin_name[0] : '';

				if(!empty($plugin_name)){
					$filter_plugin_name = $this->linguator_supported_block_name($plugin_name);
					$filter_plugin_name=str_replace('-',' ',$filter_plugin_name);
					$filter_plugin_name=ucwords($filter_plugin_name);

					if(in_array($plugin_name, $this->linguator_plugin_category) || $plugin_name === 'core'){
						continue;
					}

					$this->linguator_plugin_category[] = $plugin_name;
					echo '<option value="' . esc_attr( $plugin_name ) . '">' . esc_html( $filter_plugin_name ) . '</option>';
				}
			}
		}

		/**
		 * Get the supported blocks.
		 */
		public function linguator_get_supported_blocks_table() {
			if ( class_exists( WP_Block_Type_Registry::class ) && method_exists( WP_Block_Type_Registry::class, 'get_all_registered' ) ) {
				$linguator_block_parse_rules       = $this->block_parsing_rules();

				$blocks_data                 = WP_Block_Type_Registry::get_instance()->get_all_registered();

				$linguator_supported_blocks       = isset($linguator_block_parse_rules['LmatBlockParseRules']) ? $linguator_block_parse_rules['LmatBlockParseRules'] : array();
				$linguator_supported_blocks_names = array_keys( $linguator_supported_blocks );
				$s_no                        = 1;
				$linguator_post_id                = self::linguator_get_custom_block_post_id();

				$filter_blocks_data=$blocks_data;

				foreach ( $filter_blocks_data as $block ) {

					$block_name  = esc_html( $block->name );
					$block_title = esc_html( $block->title );

					$status      = ! in_array( $block_name, $linguator_supported_blocks_names ) ? 'Unsupported' : 'Supported'; // You can modify this logic based on your requirements
					$modify_text = ! in_array( $block_name, $linguator_supported_blocks_names ) ? esc_html__( 'Add', 'linguator-pro' ) : esc_html__( 'Edit', 'linguator-pro' );
					$modify_link = '<a href="' . esc_url( admin_url( 'post.php?post=' . esc_attr( $linguator_post_id ) . '&action=edit&lmat_new_block=' ) . esc_attr( $block_name ) ) . '">' . $modify_text . '</a>'; // Modify link

					echo '<tr data-block-name="' . esc_attr( strtolower( $block_name ) ) . '" data-block-status="' . esc_attr( strtolower( $status ) ) . '" >';
					echo '<td>' . esc_html($s_no++) . '</td>';
					echo '<td>' . esc_html($block_name) . '</td>';
					echo '<td>' . esc_html($block_title) . '</td>';
					echo '<td>' . esc_html($status) . '</td>';
					echo '<td>' . wp_kses($modify_link, array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))) . '</td>';
					echo '</tr>';
				}
			}

		}

		private function linguator_supported_block_name($block_name){
			$predfined_blocks = array(
				'ub' => 'Ultimate Blocks',
				'uagb' => 'Spectra',
				'themeisle-blocks' => 'Otter Blocks'
			);
			
			if(array_key_exists($block_name, $predfined_blocks)){
				return $predfined_blocks[$block_name];
			}

			return $block_name;
		}

		private static function linguator_get_custom_block_post_id()
		{
			$first_post_id = null;

			$query = new \WP_Query(
				array(
					'post_type'      => 'lmat_add_blocks',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'ASC',
				)
			);

			$existing_post = $query->posts ? $query->posts[0] : null;

			if (! $existing_post) {
				$post_title    = esc_html__('Add More Gutenberg Blocks', 'linguator-pro');
				$first_post_id = wp_insert_post(
					array(
						'post_title'   => $post_title,
						'post_content' => '',
						'post_status'  => 'publish',
						'post_type'    => 'lmat_add_blocks',
					)
				);
			} elseif ($query->have_posts()) {
				$query->the_post();
				$first_post_id = get_the_ID();
			}

			return $first_post_id;
		}

		/**
		 * Block Parsing Rules
		 *
		 * Handles the block parsing rules AJAX request.
		 */
		public function block_parsing_rules() {
			return $this->linguator_get_block_parse_rules();
		}

		/**
		 * Builds the block translation rules from the bundled wpml-config.xml and merges saved custom block rules on top.
		 *
		 * @return array Rules keyed as ['LmatBlockParseRules' => [ block_name => [ 'attributes' => [...] ] ] ], or an empty array when the config cannot be read.
		 *               When custom rules add attribute paths, those paths are also listed under 'LmatUserBlockRules' in the same structure.
		 */
		public function linguator_get_block_parse_rules() {
			$config_path = trailingslashit( plugin_dir_path( LINGUATOR_ROOT_FILE ) ) . 'modules/page-translation/block-translation-rules/wpml-config.xml';
			$xml_rules   = $this->linguator_parse_single_wpml_config( $config_path );

			if ( empty( $xml_rules ) ) {
				return array();
			}

			$block_translation_rules = array(
				'LmatBlockParseRules' => $this->linguator_prepare_xml_block_rules( $xml_rules ),
			);

			/**
			 * Filters whether block rules from wpml-config.xml files shipped by active plugins and themes are loaded.
			 *
			 * @param bool $enabled Whether third-party block rules are loaded. Default true.
			 */
			if ( apply_filters( 'lmat_enable_third_party_wpml_config', true ) ) {
				$block_translation_rules['LmatBlockParseRules'] = $this->linguator_merge_block_rules_recursive(
					$block_translation_rules['LmatBlockParseRules'],
					$this->linguator_parse_wpml_config_blocks()
				);
			}

			// Custom rules saved by the user take precedence and are deep merged over the built-in and third-party rules.
			$custom_block_translation = $this->linguator_sanitize_custom_block_rules( get_option( 'lmat_custom_block_translation', array() ) );

			if ( ! empty( $custom_block_translation ) ) {
				// Attribute paths added by the user, kept apart so they are translated even when their value looks technical.
				$block_translation_rules['LmatUserBlockRules'] = $this->linguator_diff_block_rules( $custom_block_translation, $block_translation_rules['LmatBlockParseRules'] );

				$block_translation_rules['LmatBlockParseRules'] = $this->linguator_merge_block_rules_recursive(
					$block_translation_rules['LmatBlockParseRules'],
					$custom_block_translation
				);
			}

			return $block_translation_rules;
		}

		/**
		 * Returns the translatable attribute rules of $rules that are not already set in $base.
		 *
		 * XPath entries and disabled (false) rules are ignored.
		 *
		 * @param array $rules Rules to compare.
		 * @param array $base  Rules to compare against.
		 * @return array Rules present only in $rules, in the same structure.
		 */
		private function linguator_diff_block_rules( array $rules, array $base ) {
			$diff = array();

			foreach ( $rules as $key => $value ) {
				if ( 'xpaths' === $key || false === $value ) {
					continue;
				}

				$base_value = isset( $base[ $key ] ) ? $base[ $key ] : null;

				if ( is_array( $value ) ) {
					$value = is_array( $base_value ) ? $this->linguator_diff_block_rules( $value, $base_value ) : $this->linguator_diff_block_rules( $value, array() );

					if ( ! empty( $value ) ) {
						$diff[ $key ] = $value;
					}
				} elseif ( true === $value && true !== $base_value ) {
					$diff[ $key ] = true;
				}
			}

			return $diff;
		}

		/**
		 * Normalizes stored custom block rules into nested arrays keyed by block name.
		 *
		 * Objects are converted to arrays. Rule values are kept only when they are booleans
		 * or arrays, and the reserved 'xpaths' entry is kept only as a list of strings.
		 *
		 * @param mixed $custom_rules Custom rules as stored in the option (array or object).
		 * @return array Sanitized custom rules keyed by block name.
		 */
		private function linguator_sanitize_custom_block_rules( $custom_rules ) {
			if ( is_object( $custom_rules ) ) {
				$custom_rules = json_decode( wp_json_encode( $custom_rules ), true );
			}

			if ( empty( $custom_rules ) || ! is_array( $custom_rules ) ) {
				return array();
			}

			$sanitized = array();

			foreach ( $custom_rules as $block_name => $block_rules ) {
				if ( ! is_string( $block_name ) || '' === $block_name ) {
					continue;
				}

				$block_rules = $this->linguator_sanitize_custom_rule_value( $block_rules );

				if ( is_array( $block_rules ) && ! empty( $block_rules ) ) {
					$sanitized[ $block_name ] = $block_rules;
				}
			}

			return $sanitized;
		}

		/**
		 * Recursively normalizes a custom rule value.
		 *
		 * @param mixed $value Rule value (array, object or scalar).
		 * @return array|bool|null Normalized value, or null when the value is not a valid rule.
		 */
		private function linguator_sanitize_custom_rule_value( $value ) {
			if ( is_object( $value ) ) {
				$value = json_decode( wp_json_encode( $value ), true );
			}

			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( ! is_array( $value ) ) {
				return null;
			}

			$sanitized = array();

			foreach ( $value as $key => $item ) {
				if ( 'xpaths' === $key ) {
					if ( is_array( $item ) ) {
						$xpaths = array_values( array_filter( $item, 'is_string' ) );
						if ( ! empty( $xpaths ) ) {
							$sanitized['xpaths'] = $xpaths;
						}
					}
					continue;
				}

				$item = $this->linguator_sanitize_custom_rule_value( $item );

				if ( null !== $item ) {
					$sanitized[ $key ] = $item;
				}
			}

			return $sanitized;
		}

		/**
		 * Collects block rules from wpml-config.xml files in active plugins and the active child and parent themes.
		 *
		 * Results are cached in a transient keyed by a fingerprint of the found files, their modification times and the plugin version.
		 *
		 * @return array Prepared rules keyed by block name, each wrapped in an 'attributes' entry.
		 */
		private function linguator_parse_wpml_config_blocks() {
			$config_files = $this->linguator_get_wpml_config_files();
			$signature    = array( LINGUATOR_VERSION, 'xpaths' );

			foreach ( $config_files as $config_file ) {
				$signature[] = $config_file . '|' . (int) filemtime( $config_file );
			}

			$fingerprint = md5( implode( "\n", $signature ) );
			$cached_data = get_transient( 'lmat_wpml_blocks_rules' );

			if ( is_array( $cached_data ) && isset( $cached_data['fingerprint'], $cached_data['rules'] ) && $cached_data['fingerprint'] === $fingerprint && is_array( $cached_data['rules'] ) ) {
				return $cached_data['rules'];
			}

			$wpml_rules = array();

			foreach ( $config_files as $config_file ) {
				$parsed = $this->linguator_parse_single_wpml_config( $config_file );

				if ( ! empty( $parsed ) ) {
					$wpml_rules = $this->linguator_merge_block_rules_recursive( $wpml_rules, $this->linguator_prepare_xml_block_rules( $parsed ) );
				}
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only notice when the cache is rebuilt.
				error_log( sprintf( 'Linguator: rebuilt third-party block rules from %d wpml-config.xml file(s), %d block(s).', count( $config_files ), count( $wpml_rules ) ) );
			}

			set_transient(
				'lmat_wpml_blocks_rules',
				array(
					'fingerprint' => $fingerprint,
					'rules'       => $wpml_rules,
				),
				12 * HOUR_IN_SECONDS
			);

			return $wpml_rules;
		}

		/**
		 * Lists readable wpml-config.xml files in the root of active plugins (including network-activated ones) and active themes.
		 *
		 * This plugin's own folder is skipped because its bundled config is loaded separately.
		 *
		 * @return string[] Absolute file paths.
		 */
		private function linguator_get_wpml_config_files() {
			$active_plugins = (array) get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}

			$own_dir = wp_normalize_path( untrailingslashit( plugin_dir_path( LINGUATOR_ROOT_FILE ) ) );
			$dirs    = array();

			foreach ( $active_plugins as $plugin ) {
				$plugin_dir = dirname( $plugin );

				if ( '.' === $plugin_dir || '' === $plugin_dir ) {
					continue;
				}

				$dirs[] = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_dir );
			}

			$dirs[] = wp_normalize_path( get_stylesheet_directory() );
			$dirs[] = wp_normalize_path( get_template_directory() );

			$files = array();

			foreach ( array_unique( $dirs ) as $dir ) {
				if ( $dir === $own_dir ) {
					continue;
				}

				$xml_file = $dir . '/wpml-config.xml';

				if ( is_file( $xml_file ) && is_readable( $xml_file ) ) {
					$files[] = $xml_file;
				}
			}

			return $files;
		}

		/**
		 * Recursively merges block rules so nested attribute keys are added instead of replacing whole branches.
		 *
		 * @param array $base     Existing rules.
		 * @param array $override Rules to merge in; scalar values win on conflict.
		 * @return array Merged rules.
		 */
		private function linguator_merge_block_rules_recursive( array $base, array $override ) {
			foreach ( $override as $key => $value ) {
				if ( 'xpaths' === $key && is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
					$base[ $key ] = array_values( array_unique( array_merge( $base[ $key ], $value ) ) );
				} elseif ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
					$base[ $key ] = $this->linguator_merge_block_rules_recursive( $base[ $key ], $value );
				} else {
					$base[ $key ] = $value;
				}
			}

			return $base;
		}

		/**
		 * Deletes the cached third-party block rules so they are rebuilt on the next request.
		 *
		 * @return void
		 */
		public function linguator_clear_wpml_blocks_cache() {
			delete_transient( 'lmat_wpml_blocks_rules' );
		}

		/**
		 * Converts parsed XML block rules into the attribute rule format used by the translation scripts.
		 *
		 * Drops regex-style keys and url keys. XPath selectors are kept in an 'xpaths' entry next to
		 * 'attributes', so blocks described only by XPath rules get an empty 'attributes' entry.
		 * Blocks left with neither attributes nor XPath selectors are skipped.
		 *
		 * @param array $xml_rules Block rules keyed by block name.
		 * @return array Rules keyed by block name, each with an 'attributes' entry and an optional 'xpaths' entry.
		 */
		private function linguator_prepare_xml_block_rules( array $xml_rules ) {
			$prepared = array();

			/**
			 * Filters the blocks whose XPath-only rules are ignored.
			 *
			 * Raw HTML blocks are excluded by default because their rule selects the whole markup,
			 * including scripts, forms and embeds.
			 *
			 * @param string[] $excluded_blocks Block names.
			 */
			$excluded_xpath_blocks = (array) apply_filters( 'lmat_excluded_xpath_only_blocks', array( 'core/html' ) );

			foreach ( $xml_rules as $block_name => $rules ) {
				if ( ! is_array( $rules ) ) {
					continue;
				}

				$xpaths = array();
				if ( isset( $rules['xpaths'] ) && is_array( $rules['xpaths'] ) ) {
					$xpaths = array_values( array_unique( array_filter( array_map( 'trim', array_filter( $rules['xpaths'], 'is_string' ) ) ) ) );
				}

				unset( $rules['xpaths'] );
				$attributes = $this->linguator_clean_xml_attribute_rules( $rules );

				if ( empty( $attributes ) && ( empty( $xpaths ) || in_array( $block_name, $excluded_xpath_blocks, true ) ) ) {
					continue;
				}

				$prepared[ $block_name ] = array( 'attributes' => $attributes );

				if ( ! empty( $xpaths ) ) {
					$prepared[ $block_name ]['xpaths'] = $xpaths;
				}
			}

			return $prepared;
		}

		/**
		 * Recursively removes regex-style keys and url keys from attribute rules, pruning branches left empty.
		 *
		 * @param array $rules Attribute rules for a block or a nested attribute.
		 * @return array Cleaned attribute rules.
		 */
		private function linguator_clean_xml_attribute_rules( array $rules ) {
			$cleaned = array();

			foreach ( $rules as $key => $value ) {
				if ( is_string( $key ) && ( 'url' === strtolower( $key ) || ( strlen( $key ) > 1 && '/' === $key[0] && '/' === substr( $key, -1 ) ) ) ) {
					continue;
				}

				if ( is_array( $value ) ) {
					$value = $this->linguator_clean_xml_attribute_rules( $value );
					if ( empty( $value ) ) {
						continue;
					}
				} elseif ( true !== $value ) {
					continue;
				}

				$cleaned[ $key ] = $value;
			}

			return $cleaned;
		}

		/**
		 * Parses the <gutenberg-blocks> section of a WPML-format config file into block rules keyed by block name.
		 *
		 * @param string $file_path Absolute path to the wpml-config.xml file.
		 * @return array Extracted block translation rules.
		 */
		private function linguator_parse_single_wpml_config( $file_path ) {
			$rules = array();

			if ( ! function_exists( 'simplexml_load_string' ) ) {
				return $rules;
			}

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			global $wp_filesystem;
			WP_Filesystem();

			$xml_contents = $wp_filesystem && $wp_filesystem->is_readable( $file_path ) ? $wp_filesystem->get_contents( $file_path ) : false;

			if ( false === $xml_contents || '' === trim( $xml_contents ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Genuine failure diagnostic, mirrors the parse-failure log below.
				error_log( sprintf( 'Linguator Error: Failed to read XML file at %s', $file_path ) );
				return $rules;
			}

			// Prevent XXE attacks by disabling external entities (deprecated and unnecessary on PHP 8+).
			$old_disable_entity_loader = null;
			if ( \PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
				$old_disable_entity_loader = libxml_disable_entity_loader( true ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- Only called on PHP < 8.
			}

			// Collect libxml errors internally for malformed XML and prevent network entity loading.
			$old_internal_errors = libxml_use_internal_errors( true );
			$xml                 = simplexml_load_string( $xml_contents, 'SimpleXMLElement', LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $old_internal_errors );

			if ( null !== $old_disable_entity_loader && function_exists( 'libxml_disable_entity_loader' ) ) {
				libxml_disable_entity_loader( $old_disable_entity_loader ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- Only called on PHP < 8.
			}

			if ( ! $xml ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Genuine failure diagnostic for malformed XML.
				error_log( sprintf( 'Linguator Error: Failed to parse XML file at %s', $file_path ) );
				return $rules;
			}

			if ( ! isset( $xml->{'gutenberg-blocks'} ) ) {
				return $rules;
			}

			if ( isset( $xml->{'gutenberg-blocks'}->{'gutenberg-block'} ) ) {
				foreach ( $xml->{'gutenberg-blocks'}->{'gutenberg-block'} as $block ) {
					$block_type = (string) $block['type'];
					if ( empty( $block_type ) ) {
						continue;
					}

					$translate = (string) $block['translate'];
					if ( '0' === $translate ) {
						continue;
					}

					$attributes           = $this->linguator_parse_wpml_keys( $block );
					$rules[ $block_type ] = ! empty( $attributes ) ? $attributes : array();

					if ( isset( $block->xpath ) ) {
						$xpaths = array();
						foreach ( $block->xpath as $xpath_node ) {
							// Link selectors point at URLs, which are never sent for translation.
							if ( 'link' === (string) $xpath_node['type'] ) {
								continue;
							}
							$xpaths[] = (string) $xpath_node;
						}
						if ( ! empty( $xpaths ) ) {
							$rules[ $block_type ]['xpaths'] = $xpaths;
						}
					}
				}
			}

			return $rules;
		}

		/**
		 * Recursively converts <key> nodes into a nested rules array; a key named * becomes a list rule.
		 *
		 * @param \SimpleXMLElement $xml_node The XML node containing key elements.
		 * @return array Extracted attributes and their translation settings.
		 */
		private function linguator_parse_wpml_keys( $xml_node ) {
			$attributes = array();
			if ( isset( $xml_node->key ) ) {
				foreach ( $xml_node->key as $key_node ) {
					$key_name = (string) $key_node['name'];
					if ( empty( $key_name ) ) {
						continue;
					}

					// Link keys hold URLs, which are never sent for translation.
					if ( 'link' === (string) $key_node['type'] ) {
						continue;
					}

					// Keys marked translate="0" are never sent for translation, including their nested keys.
					if ( '0' === (string) $key_node['translate'] ) {
						continue;
					}

					if ( isset( $key_node->key ) && count( $key_node->key ) > 0 ) {
						$nested = $this->linguator_parse_wpml_keys( $key_node );
						if ( '*' === $key_name ) {
							return array( $nested );
						}
						$attributes[ $key_name ] = $nested;
					} else {
						if ( '*' === $key_name ) {
							return array( true );
						}
						$attributes[ $key_name ] = true;
					}
				}
			}
			return $attributes;
		}

		/**
		 * Saves the attributes marked as translatable in the custom block editor.
		 *
		 * Only user-marked rules are stored. They are deep merged into the previously saved custom
		 * rules, so new attributes on an already customized block are added instead of discarded.
		 * Built-in and third-party rules are merged at load time and are never copied into the option.
		 *
		 * @param array|object $updated_blocks_data Marked rules keyed by block name.
		 * @return void
		 */
		public function update_custom_blocks_content( $updated_blocks_data ) {
			$updated_rules = $this->linguator_sanitize_custom_block_rules( $updated_blocks_data );

			if ( empty( $updated_rules ) ) {
				return;
			}

			$custom_rules = $this->linguator_sanitize_custom_block_rules( get_option( 'lmat_custom_block_translation', array() ) );
			$custom_rules = $this->linguator_merge_block_rules_recursive( $custom_rules, $updated_rules );

			if ( ! empty( $custom_rules ) ) {
				update_option( 'lmat_custom_block_translation', $custom_rules );
			}

			delete_option( 'lmat_custom_block_data' );
		}
	}
}
