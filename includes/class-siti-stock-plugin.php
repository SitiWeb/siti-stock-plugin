<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-siti-stock-settings.php';
require_once __DIR__ . '/class-siti-stock-admin-notices.php';
require_once __DIR__ . '/class-siti-stock-admin.php';
require_once __DIR__ . '/class-siti-stock-inventory-manager.php';
require_once __DIR__ . '/class-siti-stock-sync-service.php';
require_once __DIR__ . '/class-siti-stock-sync-controller.php';

/**
 * Core plugin bootstrap that wires together all sub-components.
 */
class Siti_Stock_Plugin {

	const OPTION_KEY = 'siti_stock_settings';
	const CRON_HOOK  = 'siti_stock_plugin_sync_inventory';
	const EXTERNAL_STOCK_META_KEY = '_siti_external_stock';

	/**
	 * @var Siti_Stock_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Siti_Stock_Settings
	 */
	private $settings;

	/**
	 * @var Siti_Stock_Admin_Notices
	 */
	private $notices;

	/**
	 * @var Siti_Stock_Admin
	 */
	private $admin;

	/**
	 * @var Siti_Stock_Inventory_Manager
	 */
	private $inventory_manager;

	/**
	 * @var Siti_Stock_Sync_Controller
	 */
	private $sync_controller;

	/**
	 * Get singleton instance.
	 *
	 * @return Siti_Stock_Plugin
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin activation callback.
	 */
	public static function activate() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			if ( defined( 'SITI_STOCK_PLUGIN_FILE' ) ) {
				deactivate_plugins( plugin_basename( SITI_STOCK_PLUGIN_FILE ) );
			}
			wp_die(
				__( 'WooCommerce is vereist voor de Siti Stock Plugin.', 'siti-stock-plugin' ),
				__( 'Siti Stock Plugin', 'siti-stock-plugin' ),
				array( 'back_link' => true )
			);
		}

		$settings_repo = new Siti_Stock_Settings( self::OPTION_KEY );
		Siti_Stock_Sync_Controller::maybe_schedule_from_settings( self::CRON_HOOK, $settings_repo->get_all() );
	}

	/**
	 * Plugin deactivation callback.
	 */
	public static function deactivate() {
		Siti_Stock_Sync_Controller::clear_schedule( self::CRON_HOOK );
	}

	private function __construct() {
		$this->settings         = new Siti_Stock_Settings( self::OPTION_KEY );
		$this->notices          = new Siti_Stock_Admin_Notices();
		$this->sync_controller  = new Siti_Stock_Sync_Controller( $this->settings, $this->notices, self::CRON_HOOK );
		$this->inventory_manager = new Siti_Stock_Inventory_Manager( self::EXTERNAL_STOCK_META_KEY );
		$this->admin            = new Siti_Stock_Admin( $this->settings, $this->sync_controller, $this->notices );

		$this->admin->register_hooks();
		$this->inventory_manager->register_hooks();
		$this->sync_controller->register_hooks();
		add_filter( 'woocommerce_data_stores', array( $this, 'override_product_data_store' ), 20 );
	}

	/**
	 * Ensure WooCommerce uses the custom data store so reservations see combined stock.
	 *
	 * @param array<string,string> $stores Registered data store map.
	 * @return array<string,string>
	 */
		public function override_product_data_store( $stores ) {
			if ( isset( $stores['product'] ) && $this->ensure_product_data_store_loaded() ) {
				$is_cpt_store = is_a( $stores['product'], 'WC_Product_Data_Store_CPT', true );

				if ( $is_cpt_store ) {
					$stores['product'] = 'Siti_Stock_Product_Data_Store';
				}
			}

			return $stores;
		}

		/**
		 * Load the custom product data store once WooCommerce base classes exist.
		 *
		 * @return bool
		 */
		private function ensure_product_data_store_loaded() {
			if ( class_exists( 'Siti_Stock_Product_Data_Store' ) ) {
				return true;
			}

			if ( ! class_exists( 'WC_Product_Data_Store_CPT' ) ) {
				return false;
			}

			require_once __DIR__ . '/class-siti-stock-product-data-store.php';

			return class_exists( 'Siti_Stock_Product_Data_Store' );
		}
	}
