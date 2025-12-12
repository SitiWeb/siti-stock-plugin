<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates sync triggers (cron, REST, manual).
 */
class Siti_Stock_Sync_Controller {

	/**
	 * @var Siti_Stock_Settings
	 */
	private $settings;

	/**
	 * @var Siti_Stock_Admin_Notices
	 */
	private $notices;

	/**
	 * @var string
	 */
	private $cron_hook;

	/**
	 * @param Siti_Stock_Settings      $settings Settings repository.
	 * @param Siti_Stock_Admin_Notices $notices Notice handler.
	 * @param string                   $cron_hook Cron hook identifier.
	 */
	public function __construct( Siti_Stock_Settings $settings, Siti_Stock_Admin_Notices $notices, $cron_hook ) {
		$this->settings  = $settings;
		$this->notices   = $notices;
		$this->cron_hook = $cron_hook;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'register_custom_schedules' ) );
		add_action( $this->cron_hook, array( $this, 'run_scheduled_sync' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_post_siti_stock_manual_sync', array( $this, 'handle_manual_sync' ) );
		add_action( 'update_option_' . $this->settings->get_option_key(), array( $this, 'handle_settings_update' ), 10, 2 );
	}

	/**
	 * Register cron intervals.
	 *
	 * @param array<string,array<string,int|string>> $schedules Schedules.
	 * @return array
	 */
	public function register_custom_schedules( $schedules ) {
		$label = 'Elke 15 minuten (Siti Stock)';

		if ( did_action( 'init' ) ) {
			$label = __( 'Elke 15 minuten (Siti Stock)', 'siti-stock-plugin' );
		}

		$schedules['siti_stock_quarter_hour'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => $label,
		);

		return $schedules;
	}

	/**
	 * Handle manual sync submissions.
	 */
	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Onvoldoende rechten.', 'siti-stock-plugin' ) );
		}

		check_admin_referer( 'siti_stock_manual_sync' );

		$result = $this->run_sync();

		if ( is_wp_error( $result ) ) {
			$this->notices->add_notice( $result->get_error_message(), 'error' );
		} else {
			$this->notices->add_notice(
				sprintf(
					/* translators: 1: updated count, 2: skipped count */
					__( 'Sync voltooid. %1$d producten bijgewerkt, %2$d overgeslagen.', 'siti-stock-plugin' ),
					(int) $result['updated'],
					(int) $result['skipped']
				),
				'success'
			);
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=siti-stock-plugin' ) );
		exit;
	}

	/**
	 * Handle scheduled sync.
	 */
	public function run_scheduled_sync() {
		$this->run_sync();
	}

	/**
	 * Execute actual sync.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function run_sync() {
		$settings = $this->settings->get_all();

		if ( empty( $settings['api_endpoint'] ) ) {
			return new WP_Error( 'siti_stock_missing_endpoint', __( 'Stel eerst een API-endpoint in.', 'siti-stock-plugin' ) );
		}

		$service = new Siti_Stock_Sync_Service( $settings );

		return $service->sync();
	}

	/**
	 * Register REST endpoint for remote triggers.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'siti-stock/v1',
			'/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_trigger_sync' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * REST callback to trigger sync.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_trigger_sync( WP_REST_Request $request ) {
		$result = $this->run_sync();

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'updated' => (int) $result['updated'],
				'skipped' => (int) $result['skipped'],
				'errors'  => $result['errors'],
			),
			200
		);
	}

	/**
	 * React when settings change to schedule/unschedule cron.
	 *
	 * @param array<string,mixed> $old_value Old settings.
	 * @param array<string,mixed> $value New value.
	 */
	public function handle_settings_update( $old_value, $value ) {
		if ( empty( $value['enable_auto_sync'] ) ) {
			self::clear_schedule( $this->cron_hook );
			return;
		}

		$interval = isset( $value['sync_interval'] ) ? $value['sync_interval'] : 'hourly';
		$this->schedule_sync( $interval );
	}

	/**
	 * Schedule cron hook.
	 *
	 * @param string $interval WP interval.
	 */
	private function schedule_sync( $interval ) {
		self::clear_schedule( $this->cron_hook );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, $this->cron_hook );
	}

	/**
	 * Retrieve next scheduled timestamp.
	 *
	 * @return int|false
	 */
	public function get_next_scheduled_run() {
		return wp_next_scheduled( $this->cron_hook );
	}

	/**
	 * Schedule cron during plugin activation if needed.
	 *
	 * @param string                 $cron_hook Cron hook.
	 * @param array<string,mixed>    $settings Settings snapshot.
	 */
	public static function maybe_schedule_from_settings( $cron_hook, $settings ) {
		if ( empty( $settings['enable_auto_sync'] ) ) {
			return;
		}

		self::clear_schedule( $cron_hook );
		$interval = isset( $settings['sync_interval'] ) ? sanitize_key( $settings['sync_interval'] ) : 'hourly';
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, $cron_hook );
	}

	/**
	 * Clear scheduled events (used on deactivation/settings change).
	 *
	 * @param string $cron_hook Cron hook identifier.
	 */
	public static function clear_schedule( $cron_hook ) {
		$timestamp = wp_next_scheduled( $cron_hook );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, $cron_hook );
			$timestamp = wp_next_scheduled( $cron_hook );
		}
	}
}
