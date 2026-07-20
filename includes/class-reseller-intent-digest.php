<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly email digest: last 7 days of intent data in a compact HTML email.
 * Scheduled only while enabled in Settings; "Send test email" button sends
 * one immediately.
 */
final class Reseller_Intent_Digest {

	const CRON_HOOK = 'rintent_weekly_digest';

	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'send_weekly' ) );
		add_action( 'admin_post_rintent_send_digest_test', array( $this, 'handle_send_test' ) );
		add_action( 'init', array( $this, 'sync_schedule' ) );
	}

	public function sync_schedule() {
		$enabled   = (bool) Reseller_Intent_Settings::get( 'digest_enabled' );
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function handle_send_test() {
		if ( ! current_user_can( Reseller_Intent_Admin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'reseller-intent' ) );
		}

		check_admin_referer( 'rintent_send_digest_test' );

		$sent = $this->send();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'reseller-intent-settings',
					'rintent_notice' => $sent ? 'digest_sent' : 'digest_failed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Weekly cron: skip quiet weeks, a "0 searches" email every Monday is
	 * just inbox noise. The test button still always sends via send().
	 */
	public function send_weekly() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();
		$cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 7 * DAY_IN_SECONDS );
		$events     = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $events > 0 ) {
			$this->send();
		}
	}

	/**
	 * @return bool Whether wp_mail() reported success.
	 */
	public function send() {
		$recipient = (string) Reseller_Intent_Settings::get( 'digest_email' );

		if ( '' === $recipient || ! is_email( $recipient ) ) {
			$recipient = (string) get_option( 'admin_email' );
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Domain search digest, last 7 days', 'reseller-intent' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		return wp_mail(
			$recipient,
			$subject,
			$this->build_html(),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * @return string HTML body.
	 */
	public function build_html() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();
		$start      = wp_date( 'Y-m-d 00:00:00', time() - ( 6 * DAY_IN_SECONDS ) );
		$prev_start = wp_date( 'Y-m-d 00:00:00', time() - ( 13 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END) AS searches,
					COUNT(DISTINCT CASE WHEN event_type = 'domain_search' AND domain_query <> '' THEN domain_query END) AS unique_searches,
					SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END) AS carts
				FROM {$table_name}
				WHERE created_at >= %s",
				$start
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END) AS searches
				FROM {$table_name}
				WHERE created_at >= %s AND created_at < %s",
				$prev_start,
				$start
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$top_searches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s
				GROUP BY domain_query
				ORDER BY hits DESC
				LIMIT 5",
				$start
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$top_tlds = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(SUBSTRING_INDEX(domain_query, '.', -1)) AS tld, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query LIKE %s AND created_at >= %s
				GROUP BY tld
				ORDER BY hits DESC
				LIMIT 5",
				'%' . $wpdb->esc_like( '.' ) . '%',
				$start
			)
		);

		$searches = $now ? (int) $now->searches : 0;
		$uniques  = $now ? (int) $now->unique_searches : 0;
		$carts    = $now ? (int) $now->carts : 0;
		$prev_s   = $prev ? (int) $prev->searches : 0;
		$rate     = $searches > 0 ? round( ( $carts / $searches ) * 100, 1 ) : 0;
		$change   = '';

		if ( $prev_s > 0 ) {
			$delta  = round( ( ( $searches - $prev_s ) / $prev_s ) * 100 );
			$change = sprintf( ' (%s%d%% vs prior week)', $delta >= 0 ? '+' : '', $delta );
		}

		$accent = (string) Reseller_Intent_Settings::get( 'accent_color' );

		$html  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:560px;margin:0 auto;color:#1d2327;">';
		$html .= '<h2 style="border-bottom:3px solid ' . esc_attr( $accent ) . ';padding-bottom:8px;">' . esc_html( get_bloginfo( 'name' ) ) . ', ' . esc_html__( 'Domain search digest', 'reseller-intent' ) . '</h2>';
		$html .= '<p style="color:#646970;">' . esc_html( wp_date( 'M j', time() - ( 6 * DAY_IN_SECONDS ) ) . ' – ' . wp_date( 'M j, Y' ) ) . '</p>';

		$html .= '<table role="presentation" style="width:100%;border-collapse:collapse;margin:16px 0;">';
		$html .= '<tr>';
		foreach ( array(
			array( number_format_i18n( $searches ) . esc_html( $change ), __( 'Searches', 'reseller-intent' ) ),
			array( number_format_i18n( $uniques ), __( 'Unique domains', 'reseller-intent' ) ),
			array( number_format_i18n( $carts ), __( 'Cart clicks', 'reseller-intent' ) ),
			array( number_format_i18n( $rate, 1 ) . '%', __( 'Search → cart', 'reseller-intent' ) ),
		) as $cell ) {
			$html .= '<td style="padding:10px;border:1px solid #e2e8f0;text-align:center;">'
				. '<strong style="font-size:18px;display:block;">' . $cell[0] . '</strong>'
				. '<span style="color:#646970;font-size:12px;">' . esc_html( $cell[1] ) . '</span></td>';
		}
		$html .= '</tr></table>';

		$html .= $this->list_section( __( 'Top searches', 'reseller-intent' ), $top_searches, 'domain_query' );
		$html .= $this->list_section( __( 'Top TLDs', 'reseller-intent' ), $top_tlds, 'tld', '.' );

		$html .= '<p style="margin-top:20px;"><a href="' . esc_url( admin_url( 'admin.php?page=reseller-intent' ) ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html__( 'Open the full dashboard →', 'reseller-intent' ) . '</a></p>';
		$html .= '<p style="color:#a7aaad;font-size:11px;">' . esc_html__( 'Sent by Reseller Intent. Disable or change the recipient under Settings.', 'reseller-intent' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	private function list_section( $title, $rows, $field, $prefix = '' ) {
		if ( empty( $rows ) ) {
			return '';
		}

		$html = '<h3 style="margin-bottom:6px;">' . esc_html( $title ) . '</h3><table role="presentation" style="width:100%;border-collapse:collapse;">';

		foreach ( $rows as $row ) {
			$html .= '<tr>'
				. '<td style="padding:4px 8px;border-bottom:1px solid #f0f0f1;">' . esc_html( $prefix . (string) $row->{$field} ) . '</td>'
				. '<td style="padding:4px 8px;border-bottom:1px solid #f0f0f1;text-align:right;color:#646970;">' . esc_html( number_format_i18n( (int) $row->hits ) ) . '</td>'
				. '</tr>';
		}

		return $html . '</table>';
	}
}
