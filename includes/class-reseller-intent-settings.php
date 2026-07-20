<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reseller_Intent_Settings {
	const OPTION = 'rintent_settings';

	private static $defaults = array(
		'accent_color'        => '#3858e9',
		'retention_days'      => 0,     // 0 = keep forever.
		'delete_on_uninstall' => false,
		'track_bots'          => false, // Bot filtering ON by default (track_bots=false).
		'style_widget'        => true,
		'widget_skeletons'    => true,
		'widget_clear_all'    => true,
	);

	public static function get( $key ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::$defaults );

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public function register() {
		add_action( 'admin_post_rintent_save_settings', array( $this, 'handle_save' ) );
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'reseller-intent' ) );
		}

		check_admin_referer( 'rintent_save_settings' );

		$settings = array(
			'accent_color'        => $this->sanitize_color( isset( $_POST['accent_color'] ) ? wp_unslash( $_POST['accent_color'] ) : '' ),
			'retention_days'      => $this->sanitize_retention( isset( $_POST['retention_days'] ) ? wp_unslash( $_POST['retention_days'] ) : '0' ),
			'delete_on_uninstall' => ! empty( $_POST['delete_on_uninstall'] ),
			'track_bots'          => ! empty( $_POST['track_bots'] ),
			'style_widget'        => ! empty( $_POST['style_widget'] ),
			'widget_skeletons'    => ! empty( $_POST['widget_skeletons'] ),
			'widget_clear_all'    => ! empty( $_POST['widget_clear_all'] ),
		);

		update_option( self::OPTION, $settings );
		$this->sync_purge_schedule();

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'reseller-intent', 'rintent_notice' => 'settings_saved' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Keep the daily purge cron in sync with the retention setting:
	 * scheduled only while retention is enabled — zero cron noise otherwise.
	 */
	public function sync_purge_schedule() {
		$enabled   = (int) self::get( 'retention_days' ) > 0;
		$scheduled = (bool) wp_next_scheduled( 'rintent_auto_purge' );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rintent_auto_purge' );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( 'rintent_auto_purge' );
		}
	}

	private function sanitize_color( $value ) {
		$color = sanitize_hex_color( (string) $value );

		return $color ? $color : self::$defaults['accent_color'];
	}

	private function sanitize_retention( $value ) {
		$allowed = array( 0, 30, 90, 180, 365, 730 );
		$days    = (int) $value;

		return in_array( $days, $allowed, true ) ? $days : 0;
	}
}
