<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reseller_Intent {
	const VERSION                  = '1.0.0';
	const REQUIRED_PLUGIN_BASENAME = 'reseller-store/reseller-store.php';

	private $assets;
	private $tracker;
	private $admin;
	private $settings;
	private $import;
	private $tld_strip;
	private $price;
	private $phone;
	private $digest;
	private $perf;
	private $health;

	public function __construct() {
		$this->settings  = new Reseller_Intent_Settings();
		$this->assets    = new Reseller_Intent_Assets();
		$this->tracker   = new Reseller_Intent_Tracker();
		$this->admin     = new Reseller_Intent_Admin();
		$this->import    = new Reseller_Intent_Import();
		$this->tld_strip = new Reseller_Intent_TLD_Strip();
		$this->price     = new Reseller_Intent_Price();
		$this->phone     = new Reseller_Intent_Phone();
		$this->digest    = new Reseller_Intent_Digest();
		$this->perf      = new Reseller_Intent_Perf();
		$this->health    = new Reseller_Intent_Health();

		add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );
		add_action( 'admin_notices', array( $this, 'show_dependency_notice' ) );
	}

	public function bootstrap() {
		if ( ! $this->is_reseller_store_active() ) {
			return;
		}

		add_action( 'init', array( 'Reseller_Intent_DB', 'maybe_create_table' ) );

		// Frontend tracking.
		add_action( 'wp_enqueue_scripts', array( $this->assets, 'enqueue_assets' ), 99 );
		add_action( 'wp_ajax_rintent_track', array( $this->tracker, 'handle_track_event' ) );
		add_action( 'wp_ajax_nopriv_rintent_track', array( $this->tracker, 'handle_track_event' ) );

		// Admin dashboard + actions.
		add_action( 'admin_menu', array( $this->admin, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_admin_assets' ) );
		add_action( 'wp_dashboard_setup', array( $this->admin, 'register_glance_widget' ) );
		add_action( 'wp_ajax_rintent_dashboard_data', array( $this->admin, 'ajax_dashboard_data' ) );
		add_action( 'wp_ajax_rintent_panel_rows', array( $this->admin, 'ajax_panel_rows' ) );
		add_action( 'wp_ajax_rintent_clear_preview', array( $this->admin, 'ajax_clear_preview' ) );
		add_action( 'admin_post_rintent_export_csv', array( $this->admin, 'handle_export_domain_searches' ) );
		add_action( 'admin_post_rintent_clear_data', array( $this->admin, 'handle_clear_data' ) );

		// Settings + importer + shortcodes.
		$this->settings->register();
		$this->import->register();
		$this->tld_strip->register();
		$this->price->register();
		$this->phone->register();
		$this->digest->register();
		$this->perf->register();
		$this->health->register();
		Reseller_Intent_CLI::maybe_register();
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );

		// Optional auto-purge (only scheduled when retention is enabled).
		add_action( 'rintent_auto_purge', array( 'Reseller_Intent_DB', 'run_auto_purge' ) );
		add_action( 'init', array( $this->settings, 'sync_purge_schedule' ) );
	}

	public function show_dependency_notice() {
		if ( $this->is_reseller_store_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'Reseller Intent requires the GoDaddy Reseller Store plugin.', 'reseller-intent' )
			. '</strong> '
			. esc_html__( 'Please install and activate Reseller Store to start tracking domain searches.', 'reseller-intent' )
			. '</p></div>';
	}

	private function is_reseller_store_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::REQUIRED_PLUGIN_BASENAME );
	}

	public static function activate() {
		Reseller_Intent_DB::activate();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'rintent_auto_purge' );
		Reseller_Intent_TLD_Strip::unschedule();
		Reseller_Intent_Digest::unschedule();
	}

	/**
	 * Suggested text for the site's privacy policy page.
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Reseller Intent', 'reseller-intent' ),
			'<p>' . esc_html__( 'This site records anonymous statistics about domain name searches made in the search box: the searched name, whether it was available, the country-level region, the device type (mobile or desktop) and the page it happened on. No IP addresses, no names, no accounts and no cookies are stored, and single visitors cannot be identified or tracked over time. The data is kept only to understand which domains people look for.', 'reseller-intent' ) . '</p>'
		);
	}
}
