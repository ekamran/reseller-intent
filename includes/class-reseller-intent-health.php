<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Site Health tests. Surfaces the silent failure modes (missing
 * table, dead tracking, unscheduled crons, missing Reseller Store setup)
 * where site owners already look when something feels off.
 */
final class Reseller_Intent_Health {

	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	public function add_tests( $tests ) {
		$tests['direct']['rintent_table']     = array(
			'label' => __( 'Reseller Intent events table', 'reseller-intent' ),
			'test'  => array( $this, 'test_table' ),
		);
		$tests['direct']['rintent_tracking']  = array(
			'label' => __( 'Reseller Intent tracking', 'reseller-intent' ),
			'test'  => array( $this, 'test_tracking' ),
		);
		$tests['direct']['rintent_store']     = array(
			'label' => __( 'Reseller Store connection', 'reseller-intent' ),
			'test'  => array( $this, 'test_store' ),
		);
		$tests['direct']['rintent_prices']    = array(
			'label' => __( 'Reseller Intent TLD prices', 'reseller-intent' ),
			'test'  => array( $this, 'test_prices' ),
		);
		$tests['direct']['rintent_cron']      = array(
			'label' => __( 'Reseller Intent scheduled tasks', 'reseller-intent' ),
			'test'  => array( $this, 'test_cron' ),
		);
		$tests['direct']['rintent_retention'] = array(
			'label' => __( 'Reseller Intent stored events', 'reseller-intent' ),
			'test'  => array( $this, 'test_retention' ),
		);

		return $tests;
	}

	/**
	 * True once a TLD price strip has actually rendered somewhere. The
	 * shortcode records the sets it draws, so an empty option means the
	 * strip is not on the site and none of the price plumbing matters.
	 */
	private function strip_in_use() {
		return ! empty( (array) get_option( Reseller_Intent_TLD_Strip::SETS_OPTION, array() ) );
	}

	private function result( $test, $status, $label, $description ) {
		return array(
			'label'       => $label,
			'status'      => $status, // One of: good, recommended, critical.
			'badge'       => array(
				'label' => __( 'Reseller Intent', 'reseller-intent' ),
				'color' => 'blue',
			),
			'description' => '<p>' . $description . '</p>',
			'actions'     => '',
			'test'        => $test,
		);
	}

	public function test_table() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( $exists ) {
			return $this->result(
				'rintent_table',
				'good',
				__( 'The Reseller Intent events table exists', 'reseller-intent' ),
				esc_html__( 'Search events have somewhere to go.', 'reseller-intent' )
			);
		}

		return $this->result(
			'rintent_table',
			'critical',
			__( 'The Reseller Intent events table is missing', 'reseller-intent' ),
			esc_html__( 'Tracking cannot store anything. Open any Reseller Intent admin page, the table is recreated automatically. If it stays missing, check that the database user may create tables.', 'reseller-intent' )
		);
	}

	public function test_tracking() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		// Without this the query below errors and the empty result reads as
		// "no events yet", sending the owner to hunt for a JavaScript problem
		// when the real fault is that there is nowhere to write.
		if ( ! $exists ) {
			return $this->result(
				'rintent_tracking',
				'critical',
				__( 'Tracking has nowhere to store events', 'reseller-intent' ),
				esc_html__( 'The events table is missing, so nothing can be recorded. Deactivate and reactivate Reseller Intent to recreate it.', 'reseller-intent' )
			);
		}

		$last = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $last ) {
			return $this->result(
				'rintent_tracking',
				'recommended',
				__( 'No search events recorded yet', 'reseller-intent' ),
				esc_html__( 'Fine on a new site. If the domain search widget is live and this stays empty, check for JavaScript errors or a firewall blocking admin-ajax.php.', 'reseller-intent' )
			);
		}

		$age = strtotime( current_time( 'mysql' ) ) - (int) strtotime( $last );

		if ( $age > 7 * DAY_IN_SECONDS ) {
			return $this->result(
				'rintent_tracking',
				'recommended',
				__( 'No search events for over a week', 'reseller-intent' ),
				esc_html__( 'Could just be quiet traffic. Worth a quick look: is the search widget still on the page, and does a test search appear on the dashboard?', 'reseller-intent' )
			);
		}

		return $this->result(
			'rintent_tracking',
			'good',
			__( 'Tracking is receiving events', 'reseller-intent' ),
			esc_html__( 'Recent search activity is being recorded.', 'reseller-intent' )
		);
	}

	public function test_store() {
		if ( function_exists( 'rstore_is_setup' ) && rstore_is_setup() ) {
			return $this->result(
				'rintent_store',
				'good',
				__( 'Reseller Store is connected', 'reseller-intent' ),
				esc_html__( 'The GoDaddy Reseller Store plugin is active and set up.', 'reseller-intent' )
			);
		}

		return $this->result(
			'rintent_store',
			'recommended',
			__( 'Reseller Store is not set up', 'reseller-intent' ),
			esc_html__( 'Reseller Intent tracks the Reseller Store domain search widget, connect the Reseller Store plugin to your reseller account first.', 'reseller-intent' )
		);
	}

	/**
	 * The one failure here that costs money: the price strip keeps serving
	 * its last good prices when a refresh fails, which is right (a blank
	 * strip is worse than a slightly old one) but silent. Left long enough,
	 * visitors read prices that are no longer real.
	 */
	public function test_prices() {
		if ( ! $this->strip_in_use() ) {
			return $this->result(
				'rintent_prices',
				'good',
				__( 'No TLD price strip on the site', 'reseller-intent' ),
				esc_html__( 'Nothing to keep fresh. This starts checking once a [rintent_tld_strip] shortcode renders somewhere.', 'reseller-intent' )
			);
		}

		$last = (int) get_option( Reseller_Intent_TLD_Strip::LAST_REFRESH, 0 );

		if ( ! $last ) {
			return $this->result(
				'rintent_prices',
				'recommended',
				__( 'TLD prices have never refreshed', 'reseller-intent' ),
				esc_html__( 'The strip is on the site but no price fetch has succeeded yet. Check that outbound requests to secureserver.net are allowed, then reload a page carrying the strip.', 'reseller-intent' )
			);
		}

		$age = time() - $last;

		if ( $age > 2 * DAY_IN_SECONDS ) {
			return $this->result(
				'rintent_prices',
				'recommended',
				__( 'TLD prices are going stale', 'reseller-intent' ),
				esc_html(
					sprintf(
						/* translators: %s: human readable time difference, e.g. "3 days" */
						__( 'The last successful price fetch was %s ago, so visitors may be reading prices that have changed since. The strip keeps showing the last good prices on purpose, a blank strip would be worse, which is why this goes unnoticed. Check that WordPress cron is running and that secureserver.net is reachable from the server.', 'reseller-intent' ),
						human_time_diff( $last )
					)
				)
			);
		}

		return $this->result(
			'rintent_prices',
			'good',
			__( 'TLD prices are fresh', 'reseller-intent' ),
			esc_html(
				sprintf(
					/* translators: %s: human readable time difference, e.g. "4 hours" */
					__( 'Last refreshed %s ago.', 'reseller-intent' ),
					human_time_diff( $last )
				)
			)
		);
	}

	/**
	 * Both of this plugin's scheduled jobs fail quietly: prices simply stop
	 * refreshing, retention simply stops deleting. Neither shows an error
	 * anywhere, so the only clue is a number that stopped moving.
	 */
	public function test_cron() {
		$missing   = array();
		$retention = (int) Reseller_Intent_Settings::get( 'retention_days' );

		if ( $this->strip_in_use() && ! wp_next_scheduled( Reseller_Intent_TLD_Strip::CRON_HOOK ) ) {
			$missing[] = __( 'the TLD price refresh', 'reseller-intent' );
		}

		if ( $retention > 0 && ! wp_next_scheduled( 'rintent_auto_purge' ) ) {
			$missing[] = __( 'the retention cleanup', 'reseller-intent' );
		}

		if ( $missing ) {
			return $this->result(
				'rintent_cron',
				'recommended',
				__( 'A Reseller Intent scheduled task is not queued', 'reseller-intent' ),
				esc_html(
					sprintf(
						/* translators: %s: list of unscheduled task names */
						__( 'Not scheduled: %s. Deactivating and reactivating Reseller Intent queues them again. If your site sets DISABLE_WP_CRON, make sure a real system cron is calling wp-cron.php, otherwise nothing scheduled ever runs.', 'reseller-intent' ),
						implode( ', ', $missing )
					)
				)
			);
		}

		return $this->result(
			'rintent_cron',
			'good',
			__( 'Reseller Intent scheduled tasks are queued', 'reseller-intent' ),
			esc_html__( 'Everything this plugin schedules is waiting its turn.', 'reseller-intent' )
		);
	}

	/**
	 * Events are one row each and nothing prunes them unless retention is
	 * on. Small sites never notice; a busy storefront quietly grows a table
	 * it never asked for.
	 */
	public function test_retention() {
		$retention = (int) Reseller_Intent_Settings::get( 'retention_days' );
		$total     = Reseller_Intent_DB::count_events_since( 0 );

		if ( $retention > 0 ) {
			return $this->result(
				'rintent_retention',
				'good',
				__( 'Stored events are pruned automatically', 'reseller-intent' ),
				esc_html(
					sprintf(
						/* translators: 1: number of stored events, 2: number of days */
						__( '%1$s events stored, anything older than %2$s days is deleted daily.', 'reseller-intent' ),
						number_format_i18n( $total ),
						number_format_i18n( $retention )
					)
				)
			);
		}

		if ( $total > 50000 ) {
			return $this->result(
				'rintent_retention',
				'recommended',
				__( 'Stored events are growing with no retention limit', 'reseller-intent' ),
				esc_html(
					sprintf(
						/* translators: %s: number of stored events */
						__( '%s events are stored and nothing is being deleted. That is fine if you want the full history, but a retention window under Settings keeps the table from growing forever. Exports always carry everything, so setting one does not lose you a report.', 'reseller-intent' ),
						number_format_i18n( $total )
					)
				)
			);
		}

		return $this->result(
			'rintent_retention',
			'good',
			__( 'Stored events are a sensible size', 'reseller-intent' ),
			esc_html(
				sprintf(
					/* translators: %s: number of stored events */
					__( '%s events stored, kept forever because no retention window is set.', 'reseller-intent' ),
					number_format_i18n( $total )
				)
			)
		);
	}
}
