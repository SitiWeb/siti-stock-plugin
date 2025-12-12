<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles communication with the remote stock API and applies updates to WooCommerce products.
 */
class Siti_Stock_Sync_Service {

	/**
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * @param array<string,mixed> $settings
	 */
	public function __construct( array $settings ) {
		$this->settings = wp_parse_args(
			$settings,
			array(
				'api_endpoint'     => '',
				'api_key'          => '',
				'default_status'   => 'instock',
				'enable_auto_sync' => false,
			)
		);
	}

	/**
	 * Trigger a sync run.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function sync() {
		$data = $this->fetch_remote_stock();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $this->apply_stock_updates( $data );
	}

	/**
	 * Fetch stock payload from the configured API.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function fetch_remote_stock() {
		$endpoint = trim( (string) $this->settings['api_endpoint'] );

		if ( empty( $endpoint ) ) {
			return new WP_Error( 'siti_stock_missing_endpoint', __( 'Geen API-endpoint ingesteld.', 'siti-stock-plugin' ) );
		}

		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);

		if ( ! empty( $this->settings['api_key'] ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . trim( (string) $this->settings['api_key'] );
		}

		$response = wp_remote_get( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'siti_stock_bad_response',
				sprintf(
					/* translators: %d = HTTP status code */
					__( 'API gaf een onverwachte status terug (%d).', 'siti-stock-plugin' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new WP_Error( 'siti_stock_bad_json', __( 'Kan de API-respons niet parseren.', 'siti-stock-plugin' ) );
		}

		return $data;
	}

	/**
	 * Apply stock changes to WooCommerce products.
	 *
	 * Expected payload: [{ \"sku\": \"123\", \"stock_quantity\": 12, \"external_stock\": 5, \"status\": \"instock\" }]
	 *
	 * @param array<int,array<string,mixed>> $records Array of stock records.
	 * @return array<string,mixed>|WP_Error
	 */
	private function apply_stock_updates( array $records ) {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return new WP_Error( 'siti_stock_missing_wc', __( 'WooCommerce is vereist voor de Siti Stock Plugin.', 'siti-stock-plugin' ) );
		}

		$summary = array(
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		$default_status = in_array( $this->settings['default_status'], array( 'instock', 'outofstock' ), true )
			? $this->settings['default_status']
			: 'instock';

		foreach ( $records as $record ) {
			$sku = isset( $record['sku'] ) ? sanitize_text_field( (string) $record['sku'] ) : '';

			if ( empty( $sku ) ) {
				$summary['skipped']++;
				continue;
			}

			$product_id = wc_get_product_id_by_sku( $sku );

			if ( ! $product_id ) {
				$summary['skipped']++;
				$summary['errors'][] = sprintf(
					/* translators: %s = product SKU */
					__( 'Geen product gevonden met SKU %s.', 'siti-stock-plugin' ),
					$sku
				);
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				$summary['skipped']++;
				continue;
			}

			$qty = isset( $record['stock_quantity'] ) ? (int) $record['stock_quantity'] : null;
			$status = isset( $record['status'] ) ? sanitize_key( (string) $record['status'] ) : $default_status;
			$status = in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $status : $default_status;
			$external_stock = array_key_exists( 'external_stock', $record ) ? (int) $record['external_stock'] : null;
			$external_stock = null !== $external_stock ? max( 0, $external_stock ) : null;

			if ( null !== $qty ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $qty );
			}

			if ( null !== $external_stock ) {
				$product->update_meta_data( Siti_Stock_Plugin::EXTERNAL_STOCK_META_KEY, $external_stock );
			}

			$product->set_stock_status( $status );
			$product->save();

			$summary['updated']++;
		}

		return $summary;
	}
}
