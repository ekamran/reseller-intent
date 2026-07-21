<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reseller_Intent_Settings {
	const OPTION = 'rintent_settings';

	private static $defaults = array(
		'accent_color'        => '#3858e9',
		'accent_dark'         => '',    // '' = auto: the accent lightened for dark surfaces.
		'retention_days'      => 0,     // 0 = keep forever.
		'delete_on_uninstall' => false,
		'track_bots'          => false, // Bot filtering ON by default (track_bots=false).
		'style_widget'        => true,
		'widget_skeletons'    => true,
		'widget_clear_all'    => true,
		'blocklist'           => array(),
		'support_numbers'     => null,  // null = built-in GoDaddy defaults; array = owner's own list.
		'trim_gd_assets'      => false,
		'gd_asset_pages'      => array(),
	);

	public static function get( $key ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::$defaults );

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Readable text color for anything sitting on the accent color. A light
	 * accent (yellow, mint) makes white labels unreadable, so pick dark ink
	 * when the accent is bright.
	 */
	public static function accent_text_color() {
		return self::text_color_for( (string) self::get( 'accent_color' ) );
	}

	/**
	 * Readable text color on the dark-surface accent. The auto-derived
	 * pastel is light, so this usually lands on dark ink.
	 */
	public static function accent_dark_text_color() {
		return self::text_color_for( self::accent_dark_color() );
	}

	private static function text_color_for( $color ) {
		$hex = ltrim( (string) $color, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '#ffffff';
		}

		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;

		$luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

		return $luminance > 0.6 ? '#1d2327' : '#ffffff';
	}

	/**
	 * Accent for dark surfaces: the owner's picked color, or an automatic
	 * 45/55 mix of the accent toward white (same hue, always readable).
	 * The --rintent-accent-dark CSS variable can still override either.
	 */
	public static function accent_dark_color() {
		$custom = sanitize_hex_color( (string) self::get( 'accent_dark' ) );

		if ( $custom ) {
			return $custom;
		}

		$hex = ltrim( (string) self::get( 'accent_color' ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '#7b96ff';
		}

		$mix = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$mix[] = (int) round( hexdec( substr( $hex, $offset, 2 ) ) * 0.45 + 255 * 0.55 );
		}

		return sprintf( '#%02x%02x%02x', $mix[0], $mix[1], $mix[2] );
	}

	public function register() {
		add_action( 'admin_post_rintent_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_rintent_save_numbers', array( $this, 'handle_save_numbers' ) );
		add_action( 'admin_post_rintent_reset_numbers', array( $this, 'handle_reset_numbers' ) );
	}

	/**
	 * Support numbers live on the Shortcodes page with the [rintent_phone]
	 * builder, in their own form with their own save.
	 */
	public function handle_save_numbers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'reseller-intent' ) );
		}

		check_admin_referer( 'rintent_save_numbers' );

		$numbers = $this->maybe_default_support_numbers(
			$this->sanitize_support_numbers(
				isset( $_POST['support_label'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['support_label'] ) ) : array(),
				isset( $_POST['support_number'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['support_number'] ) ) : array(),
				isset( $_POST['support_countries'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['support_countries'] ) ) : array()
			)
		);

		$settings = (array) get_option( self::OPTION, array() );

		$settings['support_numbers'] = $numbers;
		update_option( self::OPTION, $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'reseller-intent-shortcodes',
					'rintent_notice' => 'numbers_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Forget the owner's support number list so the built-in defaults
	 * (and any updates to them) apply again.
	 */
	public function handle_reset_numbers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'reseller-intent' ) );
		}

		check_admin_referer( 'rintent_reset_numbers' );

		$settings = (array) get_option( self::OPTION, array() );
		unset( $settings['support_numbers'] );
		update_option( self::OPTION, $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'reseller-intent-shortcodes',
					'rintent_notice' => 'numbers_reset',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'reseller-intent' ) );
		}

		check_admin_referer( 'rintent_save_settings' );

		$settings = array(
			'accent_color'        => $this->sanitize_color( isset( $_POST['accent_color'] ) ? sanitize_text_field( wp_unslash( $_POST['accent_color'] ) ) : '' ),
			'accent_dark'         => empty( $_POST['accent_dark_custom'] ) ? '' : (string) sanitize_hex_color( isset( $_POST['accent_dark'] ) ? sanitize_text_field( wp_unslash( $_POST['accent_dark'] ) ) : '' ),
			'retention_days'      => $this->sanitize_retention( isset( $_POST['retention_days'] ) ? sanitize_text_field( wp_unslash( $_POST['retention_days'] ) ) : '0' ),
			'delete_on_uninstall' => ! empty( $_POST['delete_on_uninstall'] ),
			'track_bots'          => ! empty( $_POST['track_bots'] ),
			'style_widget'        => ! empty( $_POST['style_widget'] ),
			'widget_skeletons'    => ! empty( $_POST['widget_skeletons'] ),
			'widget_clear_all'    => ! empty( $_POST['widget_clear_all'] ),
			'blocklist'           => $this->sanitize_blocklist( isset( $_POST['blocklist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['blocklist'] ) ) : '' ),
			'trim_gd_assets'      => ! empty( $_POST['trim_gd_assets'] ),
			'gd_asset_pages'      => $this->sanitize_id_list( isset( $_POST['gd_asset_pages'] ) ? sanitize_text_field( wp_unslash( $_POST['gd_asset_pages'] ) ) : '' ),
		);

		/*
		 * Support numbers are saved from their own form on the Shortcodes
		 * page. Carry the stored value through so this full-array write
		 * never wipes them (absent key = defaults, keep it absent too).
		 */
		$stored = (array) get_option( self::OPTION, array() );
		if ( array_key_exists( 'support_numbers', $stored ) ) {
			$settings['support_numbers'] = $stored['support_numbers'];
		}

		update_option( self::OPTION, $settings );
		$this->sync_purge_schedule();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'reseller-intent-settings',
					'rintent_notice' => 'settings_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Keep the daily purge cron in sync with the retention setting:
	 * scheduled only while retention is enabled, zero cron noise otherwise.
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

	private function sanitize_blocklist( $value ) {
		$lines = preg_split( '/[\r\n]+/', (string) $value );
		$clean = array();

		foreach ( (array) $lines as $line ) {
			$line = strtolower( trim( sanitize_text_field( $line ) ) );

			if ( '' !== $line && strlen( $line ) <= 191 && count( $clean ) < 100 ) {
				$clean[] = $line;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * If the submitted list matches the built-in defaults exactly, store
	 * null so the site keeps following default updates. Anything else is
	 * the owner's own list and future plugin updates never touch it.
	 */
	private function maybe_default_support_numbers( array $numbers ) {
		return Reseller_Intent_Phone::default_numbers() === $numbers ? null : $numbers;
	}

	private function sanitize_support_numbers( array $labels, array $numbers, array $countries ) {
		$clean = array();

		foreach ( $numbers as $i => $number ) {
			$number = trim( sanitize_text_field( (string) $number ) );

			if ( '' === $number || ! preg_match( '/^[0-9+][0-9 ()+.\-]{4,24}$/', $number ) || count( $clean ) >= 40 ) {
				continue;
			}

			$codes = array();
			foreach ( preg_split( '/[,\s]+/', strtoupper( (string) ( $countries[ $i ] ?? '' ) ) ) as $code ) {
				if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
					$codes[] = $code;
				}
			}

			$clean[] = array(
				'label'     => trim( sanitize_text_field( (string) ( $labels[ $i ] ?? '' ) ) ),
				'number'    => $number,
				'countries' => array_values( array_unique( $codes ) ),
			);
		}

		return $clean;
	}

	private function sanitize_id_list( $value ) {
		$ids = array();

		foreach ( preg_split( '/[,\s]+/', (string) $value ) as $piece ) {
			$id = absint( $piece );

			if ( $id > 0 && count( $ids ) < 200 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function sanitize_retention( $value ) {
		$allowed = array( 0, 30, 90, 180, 365, 730 );
		$days    = (int) $value;

		return in_array( $days, $allowed, true ) ? $days : 0;
	}
}
