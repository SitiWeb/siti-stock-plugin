<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the external stock field and ensures WooCommerce exposes combined stock values.
 */
class Siti_Stock_Inventory_Manager {

	/**
	 * @var string
	 */
	private $external_stock_key;

	/**
	 * @param string $meta_key Meta key used to store external stock.
	 */
	public function __construct( $meta_key ) {
		$this->external_stock_key = $meta_key;
	}

	/**
	 * Register relevant hooks.
	 */
	public function register_hooks() {
		add_action( 'woocommerce_product_options_stock_fields', array( $this, 'render_external_stock_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_external_stock_value' ) );
		add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'filter_stock_quantity_with_external' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_quantity', array( $this, 'filter_stock_quantity_with_external' ), 10, 2 );
		add_filter( 'woocommerce_product_get_stock_status', array( $this, 'filter_stock_status_with_external' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_status', array( $this, 'filter_stock_status_with_external' ), 10, 2 );
		add_action( 'woocommerce_reduce_order_item_stock', array( $this, 'rebalance_stock_after_order_reduction' ), 20, 3 );
	}

	/**
	 * Render external stock field within the inventory tab.
	 */
	public function render_external_stock_field() {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}

		global $product_object;

		if ( ! $product_object instanceof WC_Product ) {
			return;
		}

		$external_stock = $this->get_external_stock( $product_object );

		woocommerce_wp_text_input(
			array(
				'id'                => $this->external_stock_key,
				'value'             => $external_stock,
				'label'             => __( 'Externe voorraad', 'siti-stock-plugin' ),
				'desc_tip'          => true,
				'description'       => __( 'Voorraad beschikbaar op externe locatie(s).', 'siti-stock-plugin' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
			)
		);
	}

	/**
	 * Persist external stock coming from the product edit screen.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_external_stock_value( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! isset( $_POST[ $this->external_stock_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$raw_value = wp_unslash( $_POST[ $this->external_stock_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value     = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $raw_value ) : (int) $raw_value;
		$value     = max( 0, (int) $value );

		$product->update_meta_data( $this->external_stock_key, $value );
	}

	/**
	 * Ensure WooCommerce exposes combined stock (local + external) on the frontend.
	 *
	 * @param int|null   $stock   Stock reported by WooCommerce.
	 * @param WC_Product $product Product instance.
	 * @return int|null
	 */
	public function filter_stock_quantity_with_external( $stock, $product ) {
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return $stock;
		}

		return $this->calculate_combined_stock( $product );
	}

	/**
	 * Mirror combined stock towards frontend stock status without touching edit context.
	 *
	 * @param string     $status  Current status.
	 * @param WC_Product $product Product instance.
	 * @return string
	 */
	public function filter_stock_status_with_external( $status, $product ) {
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return $status;
		}

		$combined = $this->calculate_combined_stock( $product );

		if ( 'outofstock' === $status && $combined > 0 ) {
			return 'instock';
		}

		if ( 'instock' === $status && $combined <= 0 ) {
			return 'outofstock';
		}

		return $status;
	}

	/**
	 * Retrieve stored external stock value.
	 *
	 * @param WC_Product $product Product instance.
	 * @return int
	 */
	private function get_external_stock( $product ) {
		$raw = (int) $product->get_meta( $this->external_stock_key, true );

		return max( 0, $raw );
	}

	/**
	 * Calculate combined stock without triggering recursion.
	 *
	 * @param WC_Product $product Product instance.
	 * @return int
	 */
	private function calculate_combined_stock( $product ) {
		$raw_stock      = $product->get_stock_quantity( 'edit' );
		$base_stock     = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $raw_stock ) : (int) $raw_stock;
		$external_stock = $this->get_external_stock( $product );

		return max( 0, (int) $base_stock ) + $external_stock;
	}

	/**
	 * Ensure local stock only dips below zero when the external stock is depleted.
	 *
	 * @param WC_Order_Item_Product $item   Order line item that triggered the reduction.
	 * @param array                 $change Change context provided by WooCommerce.
	 * @param WC_Order              $order  Order instance (unused).
	 */
	public function rebalance_stock_after_order_reduction( $item, $change, $order ) {
		unset( $change, $order );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$product = $item->get_product();

		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return;
		}

		$managed_id   = $product->get_stock_managed_by_id();
		$stock_holder = ( $managed_id === $product->get_id() ) ? $product : wc_get_product( $managed_id );

		if ( ! $stock_holder instanceof WC_Product ) {
			return;
		}

		$current_local   = (int) $stock_holder->get_stock_quantity( 'edit' );
		$external_stock  = $this->get_external_stock( $stock_holder );

		if ( $current_local >= 0 || $external_stock <= 0 ) {
			return;
		}

		$shortage       = min( abs( $current_local ), $external_stock );
		$new_local      = $current_local + $shortage;
		$new_external   = $external_stock - $shortage;
		$needs_save     = false;

		if ( $new_local !== $current_local ) {
			$stock_holder->set_stock_quantity( $new_local );
			$needs_save = true;
		}

		if ( $new_external !== $external_stock ) {
			$stock_holder->update_meta_data( $this->external_stock_key, $new_external );
			$needs_save = true;
		}

		if ( $needs_save ) {
			$stock_holder->save();
		}
	}
}
