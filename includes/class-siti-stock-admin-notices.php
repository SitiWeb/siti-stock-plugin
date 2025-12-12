<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles temporary admin notices.
 */
class Siti_Stock_Admin_Notices {

	const TRANSIENT_KEY = 'siti_stock_notice';

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'render_flash_notice' ) );
	}

	/**
	 * Store notice in transient for later output.
	 *
	 * @param string $message Message.
	 * @param string $type Notice type.
	 */
	public function add_notice( $message, $type = 'success' ) {
		set_transient(
			self::TRANSIENT_KEY,
			array(
				'message' => $message,
				'type'    => $type,
			),
			30
		);
	}

	/**
	 * Render notice if stored.
	 */
	public function render_flash_notice() {
		$notice = get_transient( self::TRANSIENT_KEY );

		if ( ! $notice ) {
			return;
		}

		delete_transient( self::TRANSIENT_KEY );

		$type = in_array( $notice['type'], array( 'error', 'warning', 'success', 'info' ), true ) ? $notice['type'] : 'info';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}
}
