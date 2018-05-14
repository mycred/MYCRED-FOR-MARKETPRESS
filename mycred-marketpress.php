<?php
/**
 * Plugin Name: myCRED for MarketPress
 * Plugin URI: http://mycred.me
 * Description: Let users pay using myCRED points in your MarketPress store.
 * Version: 1.1
 * Tags: mycred, marketpress, gateway, payment
 * Author: Gabriel S Merovingi
 * Author URI: http://www.merovingi.com
 * Author Email: support@mycred.me
 * Requires at least: WP 4.0
 * Tested up to: WP 4.8
 * Text Domain: mycred_market
 * Domain Path: /lang
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
if ( ! class_exists( 'myCRED_MarketPress_Plugin' ) ) :
	final class myCRED_MarketPress_Plugin {

		// Plugin Version
		public $version             = '1.1';

		// Instnace
		protected static $_instance = NULL;

		// Current session
		public $session             = NULL;

		public $slug                = '';
		public $domain              = '';
		public $plugin              = NULL;
		public $plugin_name         = '';
		protected $update_url       = 'http://mycred.me/api/plugins/';

		/**
		 * Setup Instance
		 * @since 1.0
		 * @version 1.0
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		/**
		 * Not allowed
		 * @since 1.0
		 * @version 1.0
		 */
		public function __clone() { _doing_it_wrong( __FUNCTION__, 'Cheatin&#8217; huh?', '1.0' ); }

		/**
		 * Not allowed
		 * @since 1.0
		 * @version 1.0
		 */
		public function __wakeup() { _doing_it_wrong( __FUNCTION__, 'Cheatin&#8217; huh?', '1.0' ); }

		/**
		 * Define
		 * @since 1.0
		 * @version 1.0
		 */
		private function define( $name, $value, $definable = true ) {
			if ( ! defined( $name ) )
				define( $name, $value );
		}

		/**
		 * Require File
		 * @since 1.0
		 * @version 1.0
		 */
		public function file( $required_file ) {
			if ( file_exists( $required_file ) )
				require_once $required_file;
		}

		/**
		 * Construct
		 * @since 1.0
		 * @version 1.0
		 */
		public function __construct() {

			$this->slug        = 'mycred-marketpress';
			$this->plugin      = plugin_basename( __FILE__ );
			$this->domain      = 'mycred_market';
			$this->plugin_name = 'myCRED for MarketPress';

			$this->define_constants();
			$this->includes();
			$this->plugin_updates();

			register_activation_hook( MYCRED_MARKETPRESS, 'mycred_marketpress_activate_plugin' );

			add_action( 'mycred_init',                                array( $this, 'load_textdomain' ) );
			add_action( 'mycred_all_references',                      array( $this, 'add_badge_support' ) );

			// Payment gateway
			add_action( 'mp_load_gateway_plugins',                    'mycred_marketpress_load_gateway' );
			add_action( 'marketpress/load_plugins/mp_include',        'mycred_marketpress_load_gateway' );
			add_filter( 'mp_gateway_api/get_gateways',                array( $this, 'load_gateway' ) );

			add_filter( 'mp_format_currency',                         array( $this, 'adjust_currency_format' ), 10, 4 );

			add_filter( 'mycred_parse_log_entry_marketpress_payment', 'mycred_marketpress_parse_log', 90, 2 );
			add_filter( 'mycred_email_before_send',                   'mycred_marketpress_parse_email', 20 );

			// Rewards
			add_action( 'mycred_load_hooks',                          'mycred_marketpress_load_rewards', 79 );

		}

		/**
		 * Define Constants
		 * @since 1.0
		 * @version 1.0.1
		 */
		public function define_constants() {

			$this->define( 'MYCRED_MARKET_VERSION',    $this->version );
			$this->define( 'MYCRED_MARKET_SLUG',       $this->slug );

			$this->define( 'MYCRED_SLUG',              'mycred' );
			$this->define( 'MYCRED_DEFAULT_LABEL',     'myCRED' );

			$this->define( 'MYCRED_MARKETPRESS',        __FILE__ );
			$this->define( 'MYCRED_MARKET_ROOT_DIR',    plugin_dir_path( MYCRED_MARKETPRESS ) );
			$this->define( 'MYCRED_MARKET_CLASSES_DIR', MYCRED_MARKET_ROOT_DIR . 'classes/' );
			$this->define( 'MYCRED_MARKET_INC_DIR',     MYCRED_MARKET_ROOT_DIR . 'includes/' );

		}

		/**
		 * Includes
		 * @since 1.0
		 * @version 1.0
		 */
		public function includes() {

			$this->file( MYCRED_MARKET_INC_DIR . 'marketpress-gateway.php' );
			$this->file( MYCRED_MARKET_INC_DIR . 'marketpress-rewards.php' );

		}

		/**
		 * Includes
		 * @since 1.0.1
		 * @version 1.0
		 */
		public function load_gateway( $list ) {

			if ( ! array_key_exists( MYCRED_SLUG, $list ) && version_compare( MP_VERSION, '3.0', '>=' ) ) {

				$global = ( is_multisite() && mycred_centralize_log() ) ? true : false;
				$list[ MYCRED_SLUG ] = array( 'MP_Gateway_myCRED_New', MYCRED_DEFAULT_LABEL, $global, false );

			}

			return $list;

		}

		/**
		 * Adjust Currency Format
		 * @since 1.1
		 * @version 1.0
		 */
		public function adjust_currency_format( $formatted, $currency, $symbol, $amount ) {

			if ( $currency == 'POINTS' || ( function_exists( 'mycred_point_type_exists' ) && mycred_point_type_exists( $currency ) ) ) {

				$point_type = mp_get_setting( "gateways->" . MYCRED_SLUG . "->{type}", MYCRED_DEFAULT_TYPE_KEY );
				$mycred     = mycred( $point_type );

				return $mycred->format_creds( $amount );

			}

			return $formatted;

		}

		/**
		 * Load Textdomain
		 * @since 1.0
		 * @version 1.0
		 */
		public function load_textdomain() {

			// Load Translation
			$locale = apply_filters( 'plugin_locale', get_locale(), $this->domain );

			load_textdomain( $this->domain, WP_LANG_DIR . '/' . $this->slug . '/' . $this->domain . '-' . $locale . '.mo' );
			load_plugin_textdomain( $this->domain, false, dirname( $this->plugin ) . '/lang/' );

		}

		/**
		 * Plugin Updates
		 * @since 1.0
		 * @version 1.0
		 */
		public function plugin_updates() {

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_update' ), 390 );
			add_filter( 'plugins_api',                           array( $this, 'plugin_api_call' ), 390, 3 );
			add_filter( 'plugin_row_meta',                       array( $this, 'plugin_view_info' ), 390, 3 );

		}

		/**
		 * Add Badge Support
		 * @since 1.0
		 * @version 1.0
		 */
		public function add_badge_support( $references ) {

			if ( ! class_exists( 'Marketpress' ) ) return $references;

			$references['marketpress_payment'] = __( 'Store Payment (MarketPress)', 'mycred_market' );
			$references['marketpress_sale']    = __( 'Store Sale (MarketPress)', 'mycred_market' );
			$references['marketpress_reward']  = __( 'Store Reward (MarketPress)', 'mycred_market' );

			return $references;

		}

		/**
		 * Plugin Update Check
		 * @since 1.0
		 * @version 1.0
		 */
		public function check_for_plugin_update( $checked_data ) {

			global $wp_version;

			if ( empty( $checked_data->checked ) )
				return $checked_data;

			$args = array(
				'slug'    => $this->slug,
				'version' => $this->version,
				'site'    => site_url()
			);
			$request_string = array(
				'body'       => array(
					'action'     => 'version', 
					'request'    => serialize( $args ),
					'api-key'    => md5( get_bloginfo( 'url' ) )
				),
				'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' )
			);

			// Start checking for an update
			$response = wp_remote_post( $this->update_url, $request_string );

			if ( ! is_wp_error( $response ) ) {

				$result = maybe_unserialize( $response['body'] );

				if ( is_object( $result ) && ! empty( $result ) )
					$checked_data->response[ $this->slug . '/' . $this->slug . '.php' ] = $result;

			}

			return $checked_data;

		}

		/**
		 * Plugin View Info
		 * @since 1.0
		 * @version 1.0
		 */
		public function plugin_view_info( $plugin_meta, $file, $plugin_data ) {

			if ( $file != $this->plugin ) return $plugin_meta;

			$plugin_meta[] = sprintf( '<a href="%s" class="thickbox" aria-label="%s" data-title="%s">%s</a>',
				esc_url( network_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $this->slug .
				'&TB_iframe=true&width=600&height=550' ) ),
				esc_attr( __( 'More information about this plugin', 'mycred_market' ) ),
				esc_attr( $this->plugin_name ),
				__( 'View details', 'mycred_market' )
			);

			return $plugin_meta;

		}

		/**
		 * Plugin New Version Update
		 * @since 1.0
		 * @version 1.0
		 */
		public function plugin_api_call( $result, $action, $args ) {

			global $wp_version;

			if ( ! isset( $args->slug ) || ( $args->slug != $this->slug ) )
				return $result;

			// Get the current version
			$args = array(
				'slug'    => $this->slug,
				'version' => $this->version,
				'site'    => site_url()
			);
			$request_string = array(
				'body'       => array(
					'action'     => 'info', 
					'request'    => serialize( $args ),
					'api-key'    => md5( get_bloginfo( 'url' ) )
				),
				'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' )
			);

			$request = wp_remote_post( $this->update_url, $request_string );

			if ( ! is_wp_error( $request ) )
				$result = maybe_unserialize( $request['body'] );

			return $result;

		}

	}
endif;

function mycred_for_marketpress_plugin() {
	return myCRED_MarketPress_Plugin::instance();
}
mycred_for_marketpress_plugin();

/**
 * Plugin Activation
 * @since 1.0
 * @version 1.0
 */
function mycred_marketpress_activate_plugin() {

	global $wpdb;

	$message = array();

	// WordPress check
	$wp_version = $GLOBALS['wp_version'];
	if ( version_compare( $wp_version, '4.0', '<' ) )
		$message[] = __( 'This myCRED Add-on requires WordPress 4.0 or higher. Version detected:', 'mycred_market' ) . ' ' . $wp_version;

	// PHP check
	$php_version = phpversion();
	if ( version_compare( $php_version, '5.3', '<' ) )
		$message[] = __( 'This myCRED Add-on requires PHP 5.3 or higher. Version detected: ', 'mycred_market' ) . ' ' . $php_version;

	// SQL check
	$sql_version = $wpdb->db_version();
	if ( version_compare( $sql_version, '5.0', '<' ) )
		$message[] = __( 'This myCRED Add-on requires SQL 5.0 or higher. Version detected: ', 'mycred_market' ) . ' ' . $sql_version;

	// myCRED Check
	if ( defined( 'myCRED_VERSION' ) && version_compare( myCRED_VERSION, '1.7', '<' ) )
		$message[] = __( 'This add-on requires myCRED 1.7 or higher. Older versions of myCRED has built-in support for MarketPress making this plugin redundant.', 'mycred_market' );

	// Not empty $message means there are issues
	if ( ! empty( $message ) ) {

		$error_message = implode( "\n", $message );
		die( __( 'Sorry but your WordPress installation does not reach the minimum requirements for running this add-on. The following errors were given:', 'mycred_market' ) . "\n" . $error_message );

	}

}
