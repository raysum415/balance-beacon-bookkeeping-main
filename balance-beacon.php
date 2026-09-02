<?php
/**
 * Plugin Name: Balance Beacon
 * Plugin URI:  https://example.com/balance-beacon
 * Description: Standalone front-end bookkeeping dashboard for WordPress.
 * Version:     0.1.0
 * Author:      Balance Beacon
 * Author URI:  https://example.com
 * Text Domain: balance-beacon
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

define( 'BALANCE_BEACON_VERSION', '0.1.0' );
define( 'BALANCE_BEACON_FILE', __FILE__ );
define( 'BALANCE_BEACON_PATH', plugin_dir_path( __FILE__ ) );
define( 'BALANCE_BEACON_URL', plugin_dir_url( __FILE__ ) );
define( 'BALANCE_BEACON_BASENAME', plugin_basename( __FILE__ ) );

require_once BALANCE_BEACON_PATH . 'includes/database/class-schema.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-decimal.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-account-service.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-category-service.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-tag-service.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-member-service.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-store-service.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-account-group-service.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-currency-service.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-transaction-service.php';
require_once BALANCE_BEACON_PATH . 'includes/services/class-transaction-query-service.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-base-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-account-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-category-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-tag-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-member-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-store-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-account-group-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-currency-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/rest/class-transaction-controller.php';
require_once BALANCE_BEACON_PATH . 'includes/class-assets.php';
require_once BALANCE_BEACON_PATH . 'includes/class-shortcodes.php';

/**
 * Creates or updates plugin database tables on activation.
 *
 * @return void
 */
function balance_beacon_activate() {
	Balance_Beacon_Schema::install();

	update_option( 'balance_beacon_version', BALANCE_BEACON_VERSION );
}
register_activation_hook( __FILE__, 'balance_beacon_activate' );

/**
 * Registers REST API controllers.
 *
 * @return void
 */
function balance_beacon_register_rest_routes() {
	$controllers = array(
		new Balance_Beacon_REST_Account_Controller(),
		new Balance_Beacon_REST_Category_Controller(),
		new Balance_Beacon_REST_Tag_Controller(),
		new Balance_Beacon_REST_Member_Controller(),
		new Balance_Beacon_REST_Store_Controller(),
		new Balance_Beacon_REST_Account_Group_Controller(),
		new Balance_Beacon_REST_Currency_Controller(),
		new Balance_Beacon_REST_Transaction_Controller(),
	);

	foreach ( $controllers as $controller ) {
		$controller->register_routes();
	}
}
add_action( 'rest_api_init', 'balance_beacon_register_rest_routes' );

/** Registers frontend assets and shortcodes, including the unified [balance_app] shell. */
function balance_beacon_register_frontend() {
	( new Balance_Beacon_Assets() )->register();
	( new Balance_Beacon_Shortcodes() )->register();
}
add_action( 'init', 'balance_beacon_register_frontend' );
