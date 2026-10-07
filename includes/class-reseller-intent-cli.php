<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: wp rintent <command>
 *
 * report <name> [--range=<key>] [--from=<date>] [--to=<date>] [--page=<path>] [--limit=<n>] [--format=<table|json|csv|yaml>]
 *                           Any dashboard panel, same numbers as the dashboard and the Abilities API.
 * stats [--days=<n>]        Event counts and conversion for the window.
 * status                    Is tracking alive, how many events, last one when.
 * prices                    The TLD price strip's current prices.
 * abilities                 The Abilities API entries this plugin registers.
 * export [--days=<n>] [--format=<csv|json>]
 * clear --range=<range>     Delete events (hour|day|week|month|6months|year|all).
 * refresh-tld               Fetch fresh TLD strip prices right now.
 */
final class Reseller_Intent_CLI {

	/**
	 * CLI report name => ability slug. The CLI and the Abilities API are
	 * the same callbacks with different front doors.
	 */
	const REPORTS = array(
		'summary'   => 'get-summary',
		'searches'  => 'get-top-searches',
		'tlds'      => 'get-top-tlds',
		'countries' => 'get-countries',
		'carted'    => 'get-carted-domains',
		'products'  => 'get-product-adds',
		'transfers' => 'get-transfer-searches',
		'pages'     => 'get-pages',
		'trend'     => 'get-trend',
		'recent'    => 'get-recent-searches',
		'prices'    => 'get-tld-prices',
		'status'    => 'get-tracking-status',
	);

	public static function maybe_register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'rintent', __CLASS__ );
		}
	}

	/**
	 * One dashboard panel as a table, JSON, CSV or YAML.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : summary, searches, tlds, countries, carted, products, transfers, pages, trend, recent, prices or status.
	 *
	 * [--range=<range>]
	 * : 7, 30, 90, all or custom. Default 30.
	 *
	 * [--from=<date>]
	 * : Custom range start, YYYY-MM-DD.
	 *
	 * [--to=<date>]
	 * : Custom range end, YYYY-MM-DD.
	 *
	 * [--page=<path>]
	 * : Only events from this page path, like /domains/.
	 *
	 * [--limit=<n>]
	 * : Rows to return for list reports.
	 *
	 * [--repeats]
	 * : For searches: only names searched two or more times.
	 *
	 * [--format=<format>]
	 * : table (default), json, csv or yaml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rintent report summary --range=7
	 *     wp rintent report tlds --range=90 --limit=10
	 *     wp rintent report searches --repeats --format=json
	 *     wp rintent report recent --page=/domains/ --limit=20
	 */
	public function report( $args, $assoc_args ) {
		$name = (string) ( $args[0] ?? '' );

		if ( ! isset( self::REPORTS[ $name ] ) ) {
			WP_CLI::error( 'Unknown report. Use one of: ' . implode( ', ', array_keys( self::REPORTS ) ) . '.' );
		}

		$input = array();
		foreach ( array( 'range', 'from', 'to', 'page' ) as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$input[ $key ] = (string) $assoc_args[ $key ];
			}
		}
		if ( isset( $assoc_args['limit'] ) ) {
			$input['limit'] = (int) $assoc_args['limit'];
		}
		if ( ! empty( $assoc_args['repeats'] ) ) {
			$input['repeats_only'] = true;
		}

		// The shell already has the database; the capability gate is for HTTP.
		$result = Reseller_Intent_Abilities::run( self::REPORTS[ $name ], $input, false );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$this->print_report( $name, $result, $format );
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
		$days  = max( 1, (int) ( $assoc_args['days'] ?? 7 ) );
		$start = wp_date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );
		$end   = Reseller_Intent_Reports::RANGE_MAX;

		$kpis     = Reseller_Intent_Reports::kpis( $start, $end );
		$by_type  = Reseller_Intent_Reports::by_type( $start, $end );
		$searches = $kpis['searches'];
		$carts    = $kpis['cartClicks'];

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'metric' => 'searches',
					'value'  => $searches,
				),
				array(
					'metric' => 'unique searches',
					'value'  => $searches - $kpis['repeatSearches'],
				),
				array(
					'metric' => 'cart clicks',
					'value'  => $carts,
				),
				array(
					'metric' => 'domains added',
					'value'  => $kpis['domainsAdded'],
				),
				array(
					'metric' => 'transfer searches',
					'value'  => $kpis['transfers'],
				),
				array(
					'metric' => 'product adds',
					'value'  => $kpis['productAdds'],
				),
				array(
					'metric' => 'cart views',
					'value'  => $by_type['cart_view'],
				),
				array(
					'metric' => 'sign in clicks',
					'value'  => $by_type['login_click'],
				),
				array(
					'metric' => 'support calls',
					'value'  => $by_type['phone_click'],
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
	 * Is tracking alive: version, events, last event, retention.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 */
	public function status( $args, $assoc_args ) {
		$this->report( array( 'status' ), $assoc_args );
	}

	/**
	 * The TLD price strip's current prices.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 */
	public function prices( $args, $assoc_args ) {
		$this->report( array( 'prices' ), $assoc_args );
	}

	/**
	 * The Abilities API entries this plugin registers, and whether this
	 * WordPress has the API at all.
	 */
	public function abilities() {
		$rows = array();

		foreach ( Reseller_Intent_Abilities::definitions() as $slug => $def ) {
			$name       = Reseller_Intent_Abilities::NS . '/' . $slug;
			$registered = function_exists( 'wp_has_ability' ) && wp_has_ability( $name );
			$rows[]     = array(
				'ability'    => $name,
				'readonly'   => ! empty( $def['meta']['annotations']['readonly'] ) ? 'yes' : 'no',
				'registered' => $registered ? 'yes' : 'no',
				'label'      => $def['label'],
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'ability', 'readonly', 'registered', 'label' ) );

		if ( ! function_exists( 'wp_register_ability' ) ) {
			WP_CLI::line( '' );
			WP_CLI::warning( 'This WordPress has no Abilities API (it arrived in 6.9). Every report above is still available through `wp rintent report`.' );
		}
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
		$result = Reseller_Intent_Abilities::run( 'refresh-tld-prices', array(), false );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['refreshed_sets'] ) ) {
			WP_CLI::warning( 'Nothing to refresh yet, the TLD strip has not rendered anywhere.' );
			return;
		}

		WP_CLI::success( sprintf( '%d TLD set(s) refreshed.', (int) $result['refreshed_sets'] ) );
	}

	// Output.

	/**
	 * Turns an ability result into rows WP-CLI can print. Lists print as
	 * they are; nested summaries flatten to metric/value pairs.
	 */
	private function print_report( $name, array $result, $format ) {
		if ( isset( $result['range']['label'] ) && 'table' === $format ) {
			WP_CLI::line( $result['range']['label'] . ( '' !== $result['range']['page'] ? ' · ' . $result['range']['page'] : '' ) );
		}

		switch ( $name ) {
			case 'trend':
				$rows = array();
				foreach ( $result['labels'] as $i => $label ) {
					$rows[] = array(
						'period'      => $label,
						'searches'    => $result['searches'][ $i ],
						'cart_clicks' => $result['cart_clicks'][ $i ],
					);
				}
				WP_CLI\Utils\format_items( $format, $rows, array( 'period', 'searches', 'cart_clicks' ) );
				return;

			case 'prices':
				$rows = array();
				foreach ( $result['sets'] as $i => $set ) {
					foreach ( $set['tlds'] as $tld => $price ) {
						$rows[] = array(
							'set'   => $i + 1,
							'tld'   => $tld,
							'price' => $price,
						);
					}
				}
				WP_CLI\Utils\format_items( $format, $rows, array( 'set', 'tld', 'price' ) );
				if ( 'table' === $format ) {
					WP_CLI::line( 'Last refresh: ' . ( $result['last_refresh'] ? $result['last_refresh'] : 'never' ) . ' · next: ' . ( $result['next_refresh'] ? $result['next_refresh'] : 'not scheduled' ) );
				}
				return;

			case 'summary':
			case 'status':
				$rows = array();
				foreach ( self::flatten( $result ) as $metric => $value ) {
					if ( 0 === strpos( $metric, 'range.' ) ) {
						continue;
					}
					$rows[] = array(
						'metric' => $metric,
						'value'  => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( null === $value ? '' : $value ),
					);
				}
				WP_CLI\Utils\format_items( $format, $rows, array( 'metric', 'value' ) );
				return;
		}

		$items = isset( $result['items'] ) ? $result['items'] : array();

		if ( ! $items ) {
			WP_CLI::line( 'No rows in this window.' );
			return;
		}

		WP_CLI\Utils\format_items( $format, $items, array_keys( $items[0] ) );

		if ( isset( $result['total'] ) && 'table' === $format && $result['total'] > count( $items ) ) {
			WP_CLI::line( sprintf( '%d of %d, raise --limit for more.', count( $items ), (int) $result['total'] ) );
		}
	}

	private static function flatten( array $data, $prefix = '' ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				// A list of label/count pairs reads better as its own rows.
				if ( isset( $value[0] ) && is_array( $value[0] ) && isset( $value[0]['label'], $value[0]['count'] ) ) {
					foreach ( $value as $pair ) {
						$out[ $path . '.' . $pair['label'] ] = $pair['count'];
					}
					continue;
				}
				$out = array_merge( $out, self::flatten( $value, $path ) );
				continue;
			}
			$out[ $path ] = $value;
		}

		return $out;
	}
}
