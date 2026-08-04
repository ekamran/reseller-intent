<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: wp rintent <command>
 *
 * stats [--days=<n>]        Event counts and conversion for the window.
 * export [--days=<n>] [--format=<csv|json>]
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
					SUM(CASE WHEN event_type = 'continue_to_cart' THEN items_count ELSE 0 END) AS domains_added,
					SUM(CASE WHEN event_type = 'domain_transfer' THEN 1 ELSE 0 END) AS transfers,
					SUM(CASE WHEN event_type = 'product_add' THEN 1 ELSE 0 END) AS product_adds
				FROM {$table_name} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		$searches = $row ? (int) $row->searches : 0;
		$carts    = $row ? (int) $row->carts : 0;

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'metric' => 'searches',
					'value'  => $searches,
				),
				array(
					'metric' => 'unique searches',
					'value'  => $row ? (int) $row->unique_searches : 0,
				),
				array(
					'metric' => 'cart clicks',
					'value'  => $carts,
				),
				array(
					'metric' => 'domains added',
					'value'  => $row ? (int) $row->domains_added : 0,
				),
				array(
					'metric' => 'transfer searches',
					'value'  => $row ? (int) $row->transfers : 0,
				),
				array(
					'metric' => 'product adds',
					'value'  => $row ? (int) $row->product_adds : 0,
				),
				array(
					'metric' => 'search to cart rate',
					'value'  => $searches ? round( $carts / $searches * 100, 1 ) . '%' : '0%',
				),
			),
			array( 'metric', 'value' )
		);
	}

	/**
	 * Export events as CSV or JSON, to stdout or a file.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Window in days. 0 = everything. Default 0.
	 *
	 * [--format=<format>]
	 * : csv (default) or json.
	 *
	 */
	public function export( $args, $assoc_args ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming export to stdout; WP_Filesystem cannot stream.
		global $wpdb;

		$days   = max( 0, (int) ( $assoc_args['days'] ?? 0 ) );
		$format = 'json' === ( $assoc_args['format'] ?? 'csv' ) ? 'json' : 'csv';

		$table_name = Reseller_Intent_DB::table_name();

		// Epoch lower bound for "everything", so the query shape and its
		// placeholders stay identical for every window.
		$cutoff = $days > 0
			? gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS )
			: '1970-01-01 00:00:00';

		$handle = fopen( 'php://output', 'w' );

		if ( ! $handle ) {
			WP_CLI::error( 'Could not open the output stream.' );
		}

		$columns = array( 'id', 'event_type', 'domain_query', 'related_query', 'event_count', 'items_count', 'items_json', 'is_available', 'device', 'country', 'page_url', 'created_at' );
		// Column list is written out literally below; this array only drives
		// the CSV header and the row order.
		$total   = 0;
		$last_id = 0;

		if ( 'csv' === $format ) {
			fputcsv( $handle, $columns, ',', '"', '\\' );
		} else {
			fwrite( $handle, '{"generated":' . wp_json_encode( gmdate( 'c' ) ) . ',"timezone":' . wp_json_encode( wp_timezone_string() ) . ',"events":[' );
		}

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, event_type, domain_query, related_query, event_count, items_count, items_json, is_available, device, country, page_url, created_at
					FROM {$table_name}
					WHERE created_at >= %s AND id > %d
					ORDER BY id ASC
					LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefixed table name.
					$cutoff,
					$last_id
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];

				if ( 'csv' === $format ) {
					fputcsv( $handle, array_map( array( 'Reseller_Intent_Admin', 'sanitize_csv_cell' ), $row ), ',', '"', '\\' );
				} else {
					fwrite( $handle, ( $total ? ',' : '' ) . wp_json_encode( $row ) );
				}

				++$total;
			}
			$fetched = count( $rows );
		} while ( 5000 === $fetched );

		if ( 'json' === $format ) {
			fwrite( $handle, ']}' );
		}

		fclose( $handle );

		// phpcs:enable WordPress.WP.AlternativeFunctions
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
