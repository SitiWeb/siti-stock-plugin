<?php
/**
 * Plugin Name:       Siti Stock Plugin
 * Plugin URI:        https://github.com/SitiWeb/siti-stock-plugin
 * Description:       Synchroniseert WooCommerce voorraad met het externe Siti voorraadplatform.
 * Version:           1.1.1
 * Author:            Siti Web
 * Author URI:        https://www.siti.nl
 * Requires PHP:      8.1
 * Requires at least: 6.4
 * Text Domain:       siti-stock-plugin
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITI_STOCK_PLUGIN_VERSION', '0.1.0' );
define( 'SITI_STOCK_PLUGIN_FILE', __FILE__ );
define( 'SITI_STOCK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/includes/class-siti-stock-plugin.php';

if ( ! class_exists( 'SitiWebUpdater' ) ) {
	require_once __DIR__ . '/SitiWebUpdater.php';
}

register_activation_hook( __FILE__, array( 'Siti_Stock_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Siti_Stock_Plugin', 'deactivate' ) );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'siti-stock-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

Siti_Stock_Plugin::instance();

add_action(
	'admin_init',
	function () {
		if ( ! class_exists( 'SitiWebUpdater' ) ) {
			return;
		}

		$updater = new SitiWebUpdater( __FILE__ );
		$updater->set_username( 'SitiWeb' );
		$updater->set_repository( 'siti-stock-plugin' );
		$updater->initialize();
	}
);
