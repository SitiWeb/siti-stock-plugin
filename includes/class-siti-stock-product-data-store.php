<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom product data store that exposes combined (local + external) stock in SQL queries.
 *
 * WooCommerce's stock reservation system queries the raw `_stock` meta directly and therefore
 * ignores any runtime filters. By decorating the default CPT data store we make sure that the
 * reservation queries see the same combined stock value that the storefront shows.
 */
class Siti_Stock_Product_Data_Store extends WC_Product_Data_Store_CPT {

	/**
	 * Build a SQL snippet that adds the external stock meta value to the native `_stock`.
	 *
	 * {@inheritDoc}
	 *
	 * @param int $product_id Product ID that the stock query should represent.
	 * @return string
	 */
	public function get_query_for_stock( $product_id ) {
		global $wpdb;

		return $wpdb->prepare(
			"
			SELECT
				GREATEST(
					0,
					CAST( COALESCE( stock_meta.meta_value, 0 ) AS SIGNED )
				) +
				GREATEST(
					0,
					CAST( COALESCE( external_meta.meta_value, 0 ) AS SIGNED )
				)
			FROM {$wpdb->posts} AS posts
			LEFT JOIN {$wpdb->postmeta} AS stock_meta
				ON stock_meta.post_id = posts.ID AND stock_meta.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} AS external_meta
				ON external_meta.post_id = posts.ID AND external_meta.meta_key = %s
			WHERE posts.ID = %d
			LIMIT 1
			",
			'_stock',
			Siti_Stock_Plugin::EXTERNAL_STOCK_META_KEY,
			$product_id
		);
	}
}
