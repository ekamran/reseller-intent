<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Site Health. One entry, six checks inside it.
 *
 * Six separate tests scattered the plugin across the whole Site Health page
 * and buried the passing ones in the collapsed accordion, so a site owner had
 * to hunt to learn anything. One panel answers "is Reseller Intent alright?"
 * in a single place: the headline carries the worst finding, the body lists
 * every check with its own status, and nothing is hidden behind another click.
 */
final class Reseller_Intent_Health {

	/**
	 * Worst-first, so a numeric comparison picks the headline status.
	 *
	 * @var array<string,int>
	 */
	private static $severity = array(
		'good'        => 0,
		'recommended' => 1,
		'critical'    => 2,
	);

	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	public function add_tests( $tests ) {
		$tests['direct']['rintent_health'] = array(
			'label' => __( 'Reseller Intent', 'reseller-intent' ),
			'test'  => array( $this, 'test_all' ),
		);

		return $tests;
	}

	/**
	 * Run every check, report the worst status, list them all.
	 *
	 * @return array
	 */
	public function test_all() {
		$checks = array(
			$this->check_table(),
			$this->check_tracking(),
			$this->check_store(),
			$this->check_prices(),
			$this->check_cron(),
			$this->check_retention(),
		);

		$status = 'good';
		$issues = array();

		foreach ( $checks as $i => $check ) {
			if ( self::$severity[ $check['status'] ] > self::$severity[ $status ] ) {
				$status = $check['status'];
			}

			if ( 'good' !== $check['status'] ) {
				$check['order'] = $i;
				$issues[]       = $check;
			}
		}

		/*
		 * The headline always names the most serious finding rather than
		 * counting them, because "The events table is missing" sends someone
		 * to the right place and "3 things to look at" sends them nowhere.
		 * Any others are added as a count so nothing looks like the only one.
		 *
		 * Sorted on severity then original position: usort is not stable on
		 * PHP 7.4, and an unstable sort would let the headline flip between
		 * two equally serious findings on consecutive page loads.
		 */
		if ( ! $issues ) {
			$label = __( 'Reseller Intent is healthy', 'reseller-intent' );
		} else {
			usort(
				$issues,
				function ( $a, $b ) {
					return array( self::$severity[ $b['status'] ], $a['order'] )
						<=> array( self::$severity[ $a['status'] ], $b['order'] );
				}
			);

			$others = count( $issues ) - 1;
			$label  = $issues[0]['label'];

			if ( $others > 0 ) {
				$label = sprintf(
					/* translators: 1: the most serious finding, 2: how many other findings there are */
					_n( '%1$s, and %2$s more', '%1$s, and %2$s more', $others, 'reseller-intent' ),
					$label,
					number_format_i18n( $others )
				);
			}
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Reseller Intent', 'reseller-intent' ),
				'color' => 'blue',
			),
			'description' => $this->render( $checks ),
			'actions'     => $this->actions(),
			'test'        => 'rintent_health',
		);
	}

	/**
	 * The six checks as one list. Each line carries its own dot, so a panel
	 * flagged for one problem still shows the five things that are fine.
	 *
	 * Every check escapes its own body already; wp_kses_post here is the
	 * second lock, and it leaves room for a body to carry a link later
	 * without this method having to change.
	 *
	 * @param array[] $checks
	 * @return string
	 */
	private function render( array $checks ) {
		$dots = array(
			'good'        => '#00a32a',
			'recommended' => '#dba617',
			'critical'    => '#d63638',
		);

		$html = '<ul style="margin:0;padding:0;list-style:none">';

		foreach ( $checks as $check ) {
			// Logical properties, not left/padding-left: this plugin runs on
			// right-to-left admins, and a physical offset would strand every
			// dot on the wrong side of its own line there.
			$html .= sprintf(
				'<li style="margin:0 0 .9em;padding-inline-start:1.4em;position:relative">
					<span aria-hidden="true" style="position:absolute;inset-inline-start:0;top:.45em;width:.6em;height:.6em;border-radius:50%%;background:%1$s"></span>
					<strong>%2$s</strong><br>%3$s
				</li>',
				esc_attr( $dots[ $check['status'] ] ),
				esc_html( $check['label'] ),
				wp_kses_post( $check['body'] )
			);
		}

		return $html . '</ul>';
	}

	private function actions() {
		return sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Reseller_Intent_Admin::PAGE_SLUG ) ),
			esc_html__( 'Open Reseller Intent', 'reseller-intent' )
		);
	}

	/**
	 * @param string $status good|recommended|critical
	 * @param string $label  One line, shown in bold.
	 * @param string $body   Already escaped.
	 * @return array
	 */
	private function check( $status, $label, $body ) {
		return array(
			'status' => $status,
			'label'  => $label,
			'body'   => $body,
		);
	}

	/**
	 * True once a TLD price strip has actually rendered somewhere. The
	 * shortcode records the sets it draws, so an empty option means the
	 * strip is not on the site and none of the price plumbing matters.
	 */
	private function strip_in_use() {
		return ! empty( (array) get_option( Reseller_Intent_TLD_Strip::SETS_OPTION, array() ) );
	}

	private function table_exists() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	private function check_table() {
		if ( $this->table_exists() ) {
			return $this->check(
				'good',
				__( 'The events table exists', 'reseller-intent' ),
				esc_html__( 'Search events have somewhere to go.', 'reseller-intent' )
			);
		}

		return $this->check(
			'critical',
			__( 'The events table is missing', 'reseller-intent' ),
			esc_html__( 'Tracking cannot store anything. Open any Reseller Intent admin page, the table is recreated automatically. If it stays missing, check that the database user may create tables.', 'reseller-intent' )
		);
	}

	private function check_tracking() {
		global $wpdb;

		// Without this the query below errors and the empty result reads as
		// "no events yet", sending the owner to hunt for a JavaScript problem
		// when the real fault is that there is nowhere to write.
		if ( ! $this->table_exists() ) {
			return $this->check(
				'critical',
				__( 'Tracking has nowhere to store events', 'reseller-intent' ),
				esc_html__( 'The events table is missing, so nothing can be recorded. Deactivate and reactivate Reseller Intent to recreate it.', 'reseller-intent' )
			);
		}

		$table_name = Reseller_Intent_DB::table_name();
		$last       = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $last ) {
			return $this->check(
				'recommended',
				__( 'No search events recorded yet', 'reseller-intent' ),
				esc_html__( 'Fine on a new site. If the domain search widget is live and this stays empty, check for JavaScript errors or a firewall blocking admin-ajax.php.', 'reseller-intent' )
			);
		}

		$age = strtotime( current_time( 'mysql' ) ) - (int) strtotime( $last );

		if ( $age > 7 * DAY_IN_SECONDS ) {
			return $this->check(
				'recommended',
				__( 'No search events for over a week', 'reseller-intent' ),
				esc_html__( 'Could just be quiet traffic. Worth a quick look: is the search widget still on the page, and does a test search appear on the dashboard?', 'reseller-intent' )
			);
		}

		return $this->check(
			'good',
			__( 'Tracking is receiving events', 'reseller-intent' ),
			esc_html__( 'Recent search activity is being recorded.', 'reseller-intent' )
		);
	}

	private function check_store() {
		if ( function_exists( 'rstore_is_setup' ) && rstore_is_setup() ) {
			return $this->check(
				'good',
				__( 'Reseller Store is connected', 'reseller-intent' ),
				esc_html__( 'The GoDaddy Reseller Store plugin is active and set up.', 'reseller-intent' )
			);
		}

		return $this->check(
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
	private function check_prices() {
		if ( ! $this->strip_in_use() ) {
			return $this->check(
				'good',
				__( 'No TLD price strip on the site', 'reseller-intent' ),
				esc_html__( 'Nothing to keep fresh. This starts checking once a [rintent_tld_strip] shortcode renders somewhere.', 'reseller-intent' )
			);
		}

		$last = (int) get_option( Reseller_Intent_TLD_Strip::LAST_REFRESH, 0 );

		if ( ! $last ) {
			return $this->check(
				'recommended',
				__( 'TLD prices have never refreshed', 'reseller-intent' ),
				esc_html__( 'The strip is on the site but no price fetch has succeeded yet. Check that outbound requests to secureserver.net are allowed, then reload a page carrying the strip.', 'reseller-intent' )
			);
		}

		$age = time() - $last;

		if ( $age > 2 * DAY_IN_SECONDS ) {
			return $this->check(
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

		/*
		 * Freshness alone is not health. The timestamp is stamped when any
		 * one TLD comes back, so a set where four of five fetches fail looks
		 * perfectly fresh forever while the strip renders a single price.
		 * That is exactly the quiet failure this check exists to catch.
		 */
		$missing = $this->tlds_without_prices();

		if ( ! empty( $missing ) ) {
			return $this->check(
				'recommended',
				__( 'Some TLD prices are missing', 'reseller-intent' ),
				esc_html(
					sprintf(
						/* translators: 1: comma separated TLD list, e.g. ".org, .net", 2: human readable time difference, e.g. "4 hours" */
						__( 'No price has come back for %1$s, so the strip leaves them out and shows only the TLDs it has. Prices last refreshed %2$s ago, so the connection works for the others. A TLD your reseller account does not sell will never return one, in which case drop it from the shortcode. Otherwise the lookups are being refused: check that the server can reach secureserver.net, since a host IP blocked at GoDaddy fails here while the same request from a browser succeeds.', 'reseller-intent' ),
						implode( ', ', $missing ),
						human_time_diff( $last )
					)
				)
			);
		}

		return $this->check(
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
	 * Every TLD the site renders that has no cached price, deduplicated
	 * across sets and kept in the order the shortcodes list them.
	 *
	 * @return string[]
	 */
	private function tlds_without_prices() {
		$sets      = (array) get_option( Reseller_Intent_TLD_Strip::SETS_OPTION, array() );
		$last_good = (array) get_option( Reseller_Intent_TLD_Strip::LAST_GOOD, array() );
		$missing   = array();

		foreach ( $sets as $key => $tlds ) {
			$prices = isset( $last_good[ $key ] ) && is_array( $last_good[ $key ] ) ? $last_good[ $key ] : array();

			foreach ( (array) $tlds as $tld ) {
				if ( empty( $prices[ $tld ] ) ) {
					$missing[ $tld ] = true;
				}
			}
		}

		return array_keys( $missing );
	}

	/**
	 * Both of this plugin's scheduled jobs fail quietly: prices simply stop
	 * refreshing, retention simply stops deleting. Neither shows an error
	 * anywhere, so the only clue is a number that stopped moving.
	 */
	private function check_cron() {
		$missing   = array();
		$retention = (int) Reseller_Intent_Settings::get( 'retention_days' );

		if ( $this->strip_in_use() && ! wp_next_scheduled( Reseller_Intent_TLD_Strip::CRON_HOOK ) ) {
			$missing[] = __( 'the TLD price refresh', 'reseller-intent' );
		}

		if ( $retention > 0 && ! wp_next_scheduled( 'rintent_auto_purge' ) ) {
			$missing[] = __( 'the retention cleanup', 'reseller-intent' );
		}

		if ( $missing ) {
			return $this->check(
				'recommended',
				__( 'A scheduled task is not queued', 'reseller-intent' ),
				esc_html(
					sprintf(
						/* translators: %s: list of unscheduled task names */
						__( 'Not scheduled: %s. Deactivating and reactivating Reseller Intent queues them again. If your site sets DISABLE_WP_CRON, make sure a real system cron is calling wp-cron.php, otherwise nothing scheduled ever runs.', 'reseller-intent' ),
						implode( ', ', $missing )
					)
				)
			);
		}

		return $this->check(
			'good',
			__( 'Scheduled tasks are queued', 'reseller-intent' ),
			esc_html__( 'Everything this plugin schedules is waiting its turn.', 'reseller-intent' )
		);
	}

	/**
	 * Events are one row each and nothing prunes them unless retention is
	 * on. Small sites never notice; a busy storefront quietly grows a table
	 * it never asked for.
	 */
	private function check_retention() {
		$retention = (int) Reseller_Intent_Settings::get( 'retention_days' );
		$total     = Reseller_Intent_DB::count_events_since( 0 );

		if ( $retention > 0 ) {
			return $this->check(
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
			return $this->check(
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

		return $this->check(
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
