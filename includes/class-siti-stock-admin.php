<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin UI, menus and asset loading.
 */
class Siti_Stock_Admin {

	/**
	 * @var Siti_Stock_Settings
	 */
	private $settings;

	/**
	 * @var Siti_Stock_Sync_Controller
	 */
	private $sync_controller;

	/**
	 * @var Siti_Stock_Admin_Notices
	 */
	private $notices;

	/**
	 * Cached settings snapshot for rendering.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings_cache = null;

	/**
	 * @param Siti_Stock_Settings        $settings Settings repository.
	 * @param Siti_Stock_Sync_Controller $sync_controller Sync controller.
	 * @param Siti_Stock_Admin_Notices   $notices Notice handler.
	 */
	public function __construct( Siti_Stock_Settings $settings, Siti_Stock_Sync_Controller $sync_controller, Siti_Stock_Admin_Notices $notices ) {
		$this->settings        = $settings;
		$this->sync_controller = $sync_controller;
		$this->notices         = $notices;
	}

	/**
	 * Register all admin-related hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_missing_dependencies' ) );
		$this->notices->register_hooks();
	}

	/**
	 * Register settings sections/fields.
	 */
	public function register_settings() {
		$this->settings->register();

		add_settings_section(
			'siti_stock_api',
			__( 'API-instellingen', 'siti-stock-plugin' ),
			function () {
				echo '<p>' . esc_html__( 'Configureer het endpoint dat de actuele voorraad teruggeeft.', 'siti-stock-plugin' ) . '</p>';
			},
			'siti-stock-plugin'
		);

		add_settings_field(
			'api_endpoint',
			__( 'API-endpoint', 'siti-stock-plugin' ),
			array( $this, 'render_text_field' ),
			'siti-stock-plugin',
			'siti_stock_api',
			array(
				'key'         => 'api_endpoint',
				'placeholder' => 'https://example.com/api/stock',
			)
		);

		add_settings_field(
			'api_key',
			__( 'API-sleutel', 'siti-stock-plugin' ),
			array( $this, 'render_text_field' ),
			'siti-stock-plugin',
			'siti_stock_api',
			array(
				'key'         => 'api_key',
				'type'        => 'password',
				'description' => __( 'Wordt als Bearer-token meegestuurd.', 'siti-stock-plugin' ),
			)
		);

		add_settings_section(
			'siti_stock_sync',
			__( 'Synchronisatie', 'siti-stock-plugin' ),
			function () {
				echo '<p>' . esc_html__( 'Stel in hoe en wanneer de voorraad automatisch bijgewerkt moet worden.', 'siti-stock-plugin' ) . '</p>';
			},
			'siti-stock-plugin'
		);

		add_settings_field(
			'default_status',
			__( 'Standaardstatus', 'siti-stock-plugin' ),
			array( $this, 'render_select_field' ),
			'siti-stock-plugin',
			'siti_stock_sync',
			array(
				'key'     => 'default_status',
				'options' => array(
					'instock'    => __( 'Op voorraad', 'siti-stock-plugin' ),
					'outofstock' => __( 'Niet op voorraad', 'siti-stock-plugin' ),
					'onbackorder'=> __( 'In nabestelling', 'siti-stock-plugin' ),
				),
			)
		);

		add_settings_field(
			'enable_auto_sync',
			__( 'Automatisch synchroniseren', 'siti-stock-plugin' ),
			array( $this, 'render_checkbox_field' ),
			'siti-stock-plugin',
			'siti_stock_sync',
			array(
				'key'         => 'enable_auto_sync',
				'description' => __( 'Voer de synchronisatie periodiek uit via WP-Cron.', 'siti-stock-plugin' ),
			)
		);

		add_settings_field(
			'sync_interval',
			__( 'Interval', 'siti-stock-plugin' ),
			array( $this, 'render_select_field' ),
			'siti-stock-plugin',
			'siti_stock_sync',
			array(
				'key'     => 'sync_interval',
				'options' => array(
					'siti_stock_quarter_hour' => __( 'Elke 15 minuten', 'siti-stock-plugin' ),
					'hourly'                  => __( 'Elk uur', 'siti-stock-plugin' ),
					'twicedaily'              => __( 'Twee keer per dag', 'siti-stock-plugin' ),
					'daily'                   => __( 'Dagelijks', 'siti-stock-plugin' ),
				),
			)
		);
	}

	/**
	 * Register admin menu page.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Siti Stock', 'siti-stock-plugin' ),
			__( 'Siti Stock', 'siti-stock-plugin' ),
			'manage_options',
			'siti-stock-plugin',
			array( $this, 'render_settings_page' ),
			'dashicons-products'
		);
	}

	/**
	 * Enqueue admin assets only on our page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_siti-stock-plugin' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'siti-stock-admin',
			plugins_url( 'assets/css/admin.css', SITI_STOCK_PLUGIN_FILE ),
			array(),
			SITI_STOCK_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'siti-stock-admin',
			plugins_url( 'assets/js/admin.js', SITI_STOCK_PLUGIN_FILE ),
			array( 'jquery' ),
			SITI_STOCK_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Display main admin page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->get_settings_snapshot();
		$next_run = $this->sync_controller->get_next_scheduled_run();
		?>
		<div class="wrap siti-stock-settings">
			<h1><?php esc_html_e( 'Siti Stock Plugin', 'siti-stock-plugin' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Beheer API-sleutels, synchronisatie-instellingen en voer handmatige voorraadupdates uit.', 'siti-stock-plugin' ); ?></p>

			<form method="post" action="options.php">
				<?php
				settings_fields( $this->settings->get_settings_group() );
				do_settings_sections( 'siti-stock-plugin' );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Handmatige synchronisatie', 'siti-stock-plugin' ); ?></h2>
			<p><?php esc_html_e( 'Voer direct een synchronisatie uit wanneer je net voorraad hebt bijgewerkt in het bronsysteem.', 'siti-stock-plugin' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'siti_stock_manual_sync' ); ?>
				<input type="hidden" name="action" value="siti_stock_manual_sync" />
				<?php submit_button( __( 'Start handmatige sync', 'siti-stock-plugin' ), 'secondary', 'siti_stock_manual_sync', false ); ?>
			</form>

			<div class="siti-stock-cron-info<?php echo ! empty( $settings['enable_auto_sync'] ) ? ' is-visible' : ''; ?>" data-visible="<?php echo esc_attr( ! empty( $settings['enable_auto_sync'] ) ? '1' : '0' ); ?>">
				<h3><?php esc_html_e( 'Cron status', 'siti-stock-plugin' ); ?></h3>
				<p>
					<?php
					if ( $next_run ) {
						printf(
							/* translators: %s = formatted datetime */
							esc_html__( 'Volgende geplande run: %s', 'siti-stock-plugin' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) )
						);
					} else {
						esc_html_e( 'Momenteel staat er geen geplande sync klaar.', 'siti-stock-plugin' );
					}
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a text/password input.
	 *
	 * @param array<string,string> $args Field args.
	 */
	public function render_text_field( $args ) {
		$key         = $args['key'];
		$type        = isset( $args['type'] ) ? $args['type'] : 'text';
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		$value       = $this->get_settings_value( $key );
		?>
		<input
			type="<?php echo esc_attr( $type ); ?>"
			name="<?php echo esc_attr( $this->settings->get_option_key() . '[' . $key . ']' ); ?>"
			id="<?php echo esc_attr( $key ); ?>"
			class="regular-text"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render select field.
	 *
	 * @param array<string,mixed> $args Field args.
	 */
	public function render_select_field( $args ) {
		$key     = $args['key'];
		$value   = $this->get_settings_value( $key );
		$options = isset( $args['options'] ) ? $args['options'] : array();
		?>
		<select name="<?php echo esc_attr( $this->settings->get_option_key() . '[' . $key . ']' ); ?>" id="<?php echo esc_attr( $key ); ?>">
			<?php foreach ( $options as $option_key => $label ) : ?>
				<option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $value, $option_key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render checkbox.
	 *
	 * @param array<string,mixed> $args Arguments.
	 */
	public function render_checkbox_field( $args ) {
		$key   = $args['key'];
		$value = (bool) $this->get_settings_value( $key );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->settings->get_option_key() . '[' . $key . ']' ); ?>"
				value="1"
				<?php checked( $value ); ?>
				data-siti-stock-toggle="cron"
			/>
			<?php esc_html_e( 'Inschakelen', 'siti-stock-plugin' ); ?>
		</label>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Show dependencies notice.
	 */
	public function maybe_show_missing_dependencies() {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce is vereist voor de Siti Stock Plugin.', 'siti-stock-plugin' ) . '</p></div>';
	}

	/**
	 * Get cached settings snapshot for admin rendering.
	 *
	 * @return array<string,mixed>
	 */
	private function get_settings_snapshot() {
		if ( null === $this->settings_cache ) {
			$this->settings_cache = $this->settings->get_all();
		}

		return $this->settings_cache;
	}

	/**
	 * Helper for retrieving a specific setting.
	 *
	 * @param string $key Array key.
	 * @return mixed
	 */
	private function get_settings_value( $key ) {
		$settings = $this->get_settings_snapshot();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : '';
	}
}
