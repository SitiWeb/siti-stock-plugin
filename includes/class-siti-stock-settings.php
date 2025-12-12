<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings repository with caching/sanitization helpers.
 */
class Siti_Stock_Settings {

	/**
	 * Cached settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * @var string
	 */
	private $option_key;

	/**
	 * @var string
	 */
	private $settings_group;

	/**
	 * @param string      $option_key Option key.
	 * @param string|null $settings_group Settings group (defaults to option key).
	 */
	public function __construct( $option_key, $settings_group = null ) {
		$this->option_key    = $option_key;
		$this->settings_group = $settings_group ? $settings_group : $option_key;
	}

	/**
	 * Register option storage with WordPress.
	 */
	public function register() {
		register_setting(
			$this->settings_group,
			$this->option_key,
			array( $this, 'sanitize' )
		);
	}

	/**
	 * Sanitize input before saving.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$output = $this->get_all();

		$output['api_endpoint']     = isset( $input['api_endpoint'] ) ? esc_url_raw( $input['api_endpoint'] ) : '';
		$output['api_key']          = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
		$output['default_status']   = isset( $input['default_status'] ) ? sanitize_key( $input['default_status'] ) : 'instock';
		$output['enable_auto_sync'] = ! empty( $input['enable_auto_sync'] );
		$output['sync_interval']    = isset( $input['sync_interval'] ) ? sanitize_key( $input['sync_interval'] ) : 'hourly';

		$this->cache = $output;

		return $output;
	}

	/**
	 * Get option key.
	 *
	 * @return string
	 */
	public function get_option_key() {
		return $this->option_key;
	}

	/**
	 * Get settings group name.
	 *
	 * @return string
	 */
	public function get_settings_group() {
		return $this->settings_group;
	}

	/**
	 * Retrieve all settings (cached).
	 *
	 * @return array<string,mixed>
	 */
	public function get_all() {
		if ( null === $this->cache ) {
			$this->cache = wp_parse_args(
				get_option( $this->option_key, array() ),
				array(
					'api_endpoint'     => '',
					'api_key'          => '',
					'default_status'   => 'instock',
					'enable_auto_sync' => false,
					'sync_interval'    => 'hourly',
				)
			);
		}

		return $this->cache;
	}

	/**
	 * Retrieve single setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = '' ) {
		$settings = $this->get_all();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Drop cache so latest values get read.
	 */
	public function reset_cache() {
		$this->cache = null;
	}
}
