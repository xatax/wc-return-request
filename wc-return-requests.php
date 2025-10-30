<?php
/**
 * Plugin Name: WooCommerce Return Requests
 * Description: Customers can create and track return requests from My Account in WooCommerce.
 * Author: Your Name
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: wc-return-requests
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCRR_PLUGIN_FILE', __FILE__ );
define( 'WCRR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCRR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoload core class.
require_once WCRR_PLUGIN_DIR . 'includes/class-wcrr-plugin.php';

// Initialize after WooCommerce is loaded.
add_action( 'plugins_loaded', static function () {
	if ( class_exists( 'WooCommerce' ) ) {
		WCRR_Plugin::instance();
	}
} );

register_activation_hook( __FILE__, static function () {
	// Ensure CPT and endpoint are registered on activation for flush.
	require_once WCRR_PLUGIN_DIR . 'includes/class-wcrr-plugin.php';
	WCRR_Plugin::instance()->register_post_type();
	WCRR_Plugin::instance()->add_account_endpoint();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function () {
	flush_rewrite_rules();
} );




