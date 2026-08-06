<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reseller_Intent {
	const VERSION                  = '2.2.0';
	const REQUIRED_PLUGIN_BASENAME = 'reseller-store/reseller-store.php';
	const TESTED_RSTORE            = '3.0.1';
	const MIN_RSTORE               = '2.2.17';

	private $assets;
	private $tracker;
	private $admin;
	private $settings;
	private $tld_strip;
	private $price;
	private $phone;
	private $health;

	public function __construct() {
		$this->settings  = new Reseller_Intent_Settings();
		$this->assets    = new Reseller_Intent_Assets();
		$this->tracker   = new Reseller_Intent_Tracker();
		$this->admin     = new Reseller_Intent_Admin();
		$this->tld_strip = new Reseller_Intent_TLD_Strip();
		$this->price     = new Reseller_Intent_Price();
		$this->phone     = new Reseller_Intent_Phone();
		$this->health    = new Reseller_Intent_Health();

		add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );
		add_action( 'admin_notices', array( $this, 'show_dependency_notice' ) );
		add_action( 'admin_notices', array( $this, 'show_outdated_notice' ) );
		add_action( 'admin_notices', array( $this, 'show_compat_notice' ) );
		add_action( 'admin_post_rintent_ack_rstore', array( $this, 'handle_ack_rstore' ) );
	}

	public function bootstrap() {
		if ( ! $this->is_reseller_store_active() ) {
			return;
		}

		if ( $this->is_reseller_store_outdated() ) {
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
		add_action( 'wp_ajax_rintent_preview_shortcode', array( $this->admin, 'ajax_preview_shortcode' ) );
		add_action( 'wp_ajax_rintent_clear_preview', array( $this->admin, 'ajax_clear_preview' ) );
		add_action( 'admin_post_rintent_export_csv', array( $this->admin, 'handle_export_domain_searches' ) );
		add_action( 'admin_post_rintent_clear_data', array( $this->admin, 'handle_clear_data' ) );

		// Settings + shortcodes.
		$this->settings->register();
		$this->tld_strip->register();
		$this->price->register();
		$this->phone->register();
		$this->health->register();
		Reseller_Intent_CLI::maybe_register();
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );

		// Optional auto-purge (only scheduled when retention is enabled).
		add_action( 'rintent_auto_purge', array( 'Reseller_Intent_DB', 'run_auto_purge' ) );
		add_action( 'init', array( $this->settings, 'sync_purge_schedule' ) );
	}

	/**
	 * Reseller Store 2.2.17 is the oldest build this plugin is tested
	 * against (its widget markup matches 3.x byte for byte; older 2.x
	 * is unknown territory). Below the floor nothing initializes: no
	 * tracking, no assets, no dashboard, only this notice. An empty
	 * version reading does not block, a broken detection must never
	 * brick a working site.
	 */
	private function is_reseller_store_outdated() {
		$rstore_version = $this->reseller_store_version();

		return '' !== $rstore_version && version_compare( $rstore_version, self::MIN_RSTORE, '<' );
	}

	/**
	 * Hard-stop error notice while Reseller Store sits below the floor.
	 */
	public function show_outdated_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || ! $this->is_reseller_store_active() || ! $this->is_reseller_store_outdated() ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Reseller Intent is paused.', 'reseller-intent' )
			. '</strong> '
			. esc_html(
				sprintf(
					/* translators: 1: minimum supported Reseller Store version, 2: installed Reseller Store version */
					__( 'It needs Reseller Store %1$s or newer, but %2$s is running. Please update the Reseller Store plugin to start tracking again.', 'reseller-intent' ),
					self::MIN_RSTORE,
					$this->reseller_store_version()
				)
			)
			. '</p></div>';
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

	/**
	 * Soft heads-up when Reseller Store runs a newer version than this
	 * plugin was tested against. WordPress only checks compatibility with
	 * core, never between plugins, so we do it ourselves. Informational
	 * and dismissible per version, nothing is blocked.
	 */
	public function show_compat_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || ! $this->is_reseller_store_active() ) {
			return;
		}

		$rstore_version = $this->reseller_store_version();

		if ( ! $rstore_version || version_compare( $rstore_version, self::TESTED_RSTORE, '<=' ) ) {
			return;
		}

		if ( get_option( 'rintent_rstore_ack' ) === $rstore_version ) {
			return;
		}

		$ack_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=rintent_ack_rstore&v=' . rawurlencode( $rstore_version ) ),
			'rintent_ack_rstore'
		);

		echo '<div class="notice notice-info"><p><strong>'
			. esc_html__( 'Reseller Intent:', 'reseller-intent' )
			. '</strong> '
			. esc_html(
				sprintf(
					/* translators: 1: installed Reseller Store version, 2: tested version */
					__( 'Reseller Store %1$s detected. This plugin was tested up to Reseller Store %2$s. Everything most likely works, just give the dashboard and the search widget a quick look.', 'reseller-intent' ),
					$rstore_version,
					self::TESTED_RSTORE
				)
			)
			. ' <a href="' . esc_url( $ack_url ) . '">'
			. esc_html__( 'Looks fine, dismiss', 'reseller-intent' )
			. '</a></p></div>';
	}

	public function handle_ack_rstore() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'reseller-intent' ), 403 );
		}

		check_admin_referer( 'rintent_ack_rstore' );

		update_option( 'rintent_rstore_ack', isset( $_GET['v'] ) ? sanitize_text_field( wp_unslash( $_GET['v'] ) ) : '', false );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	private function reseller_store_version() {
		if ( function_exists( 'rstore' ) && isset( rstore()->version ) ) {
			return (string) rstore()->version;
		}

		$file = WP_PLUGIN_DIR . '/' . self::REQUIRED_PLUGIN_BASENAME;

		if ( ! is_readable( $file ) ) {
			return '';
		}

		$data = get_file_data( $file, array( 'Version' => 'Version' ) );

		return (string) ( $data['Version'] ?? '' );
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
