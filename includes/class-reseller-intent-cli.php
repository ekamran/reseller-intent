<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: wp rintent <command>
 *
 * stats [--days=<n>]        Event counts and conversion for the window.
 * clear --range=<range>     Delete events (hour|day|week|month|6months|year|all).
 * refresh-tld               Fetch fresh TLD strip prices right now.
 */
final class Reseller_Intent_CLI {

	public static function maybe_register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'rintent', __CLASS__ );
		}
	}

	/**
	 * Event counts and conversion for the last N days.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Window in days. Default 7.
	 */
	public function stats( $args, $assoc_args ) {
		global $wpdb;

		$days       = max( 1, (int) ( $assoc_args['days'] ?? 7 ) );
		$table_name = Reseller_Intent_DB::table_name();
		$cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END) AS searches,
					COUNT(DISTINCT CASE WHEN event_type = 'domain_search' AND domain_query <> '' THEN domain_query END) AS unique_searches,
					SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END) AS carts,
					SUM(CASE WHEN event_type = 'continue_to_cart' THEN items_count ELSE 0 END) AS domains_added
				FROM {$table_name} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		$searches = $row ? (int) $row->searches : 0;
		$carts    = $row ? (int) $row->carts : 0;

		WP_CLI\Utils\format_items(
			'table',
			array(
				array( 'metric' => 'searches', 'value' => $searches ),
				array( 'metric' => 'unique searches', 'value' => $row ? (int) $row->unique_searches : 0 ),
				array( 'metric' => 'cart clicks', 'value' => $carts ),
				array( 'metric' => 'domains added', 'value' => $row ? (int) $row->domains_added : 0 ),
				array( 'metric' => 'search to cart rate', 'value' => $searches ? round( $carts / $searches * 100, 1 ) . '%' : '0%' ),
			),
			array( 'metric', 'value' )
		);
	}

	/**
	 * Delete tracked events for a window.
	 *
	 * ## OPTIONS
	 *
	 * --range=<range>
	 * : One of hour, day, week, month, 6months, year, all.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 */
	public function clear( $args, $assoc_args ) {
		$ranges = array(
			'hour'    => HOUR_IN_SECONDS,
			'day'     => DAY_IN_SECONDS,
			'week'    => WEEK_IN_SECONDS,
			'month'   => 30 * DAY_IN_SECONDS,
			'6months' => 182 * DAY_IN_SECONDS,
			'year'    => 365 * DAY_IN_SECONDS,
			'all'     => 0,
		);

		$range = (string) ( $assoc_args['range'] ?? '' );

		if ( ! isset( $ranges[ $range ] ) ) {
			WP_CLI::error( 'Invalid --range. Use hour, day, week, month, 6months, year or all.' );
		}

		$count = Reseller_Intent_DB::count_events_since( $ranges[ $range ] );

		WP_CLI::confirm( sprintf( 'Delete %d events (%s)?', $count, $range ), $assoc_args );

		$deleted = Reseller_Intent_DB::delete_events_since( $ranges[ $range ] );

		WP_CLI::success( sprintf( '%d events deleted.', (int) $deleted ) );
	}

	/**
	 * Fetch fresh TLD strip prices from the storefront API right now.
	 *
	 * @subcommand refresh-tld
	 */
	public function refresh_tld() {
		$sets = (array) get_option( Reseller_Intent_TLD_Strip::SETS_OPTION, array() );

		if ( empty( $sets ) ) {
			WP_CLI::warning( 'Nothing to refresh yet, the TLD strip has not rendered anywhere.' );
			return;
		}

		$strip = new Reseller_Intent_TLD_Strip();
		$strip->prefetch();

		WP_CLI::success( sprintf( '%d TLD set(s) refreshed.', count( $sets ) ) );
	}
}
