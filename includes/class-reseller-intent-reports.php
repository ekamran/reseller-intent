<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every aggregate the plugin can report, in one place.
 *
 * The dashboard, the glance widget, WP-CLI and the Abilities API all read
 * from here, so a number can only ever be computed one way. Before this
 * class each surface carried its own copy of the SQL and a grep guard was
 * the only thing keeping them from drifting apart.
 *
 * All methods are static and take plain values: a range start, an
 * exclusive range end, and an optional normalized page path. Nothing here
 * reads the request or checks capabilities; callers do that.
 */
final class Reseller_Intent_Reports {

	// Open range edges, so every query keeps the same two date placeholders.
	const RANGE_MIN = '1970-01-01 00:00:00';
	const RANGE_MAX = '9999-12-31 23:59:59';

	const RANGE_KEYS = array( '7', '30', '90', 'all', 'custom' );

	// Every event type the tracker writes, in funnel order.
	const EVENT_TYPES = array( 'domain_search', 'domain_select', 'continue_to_cart', 'domain_transfer', 'product_add', 'cart_view', 'login_click', 'phone_click' );

	/*
	 * SQL that both MySQL/MariaDB and SQLite run. The functions used here,
	 * INSTR, SUBSTR, CASE, LOWER, DATE and DATE_FORMAT, exist on both. The
	 * MySQL-only ones (substring by delimiter, locate with an offset, string
	 * reverse) do not, and WordPress Playground, where the wordpress.org
	 * Live Preview runs, is SQLite.
	 */

	/**
	 * $expr cut off before the first $char, or unchanged when absent.
	 */
	private static function cut_before_sql( $expr, $char ) {
		return "CASE WHEN INSTR($expr, '$char') > 0 THEN SUBSTR($expr, 1, INSTR($expr, '$char') - 1) ELSE $expr END";
	}

	/**
	 * page_url without its query string and fragment.
	 */
	private static function page_url_clean_sql() {
		return self::cut_before_sql( self::cut_before_sql( 'page_url', '?' ), '#' );
	}

	/**
	 * The lower-cased path of page_url: everything from the first slash
	 * past the scheme ("https://" is eight characters, so the host starts
	 * at nine), without query string and fragment. A URL with no path at
	 * all yields '', which the home filter passes as its second value.
	 */
	private static function page_path_sql() {
		$rest = 'SUBSTR(page_url, 9)';
		$path = "CASE WHEN INSTR($rest, '/') > 0 THEN SUBSTR($rest, INSTR($rest, '/')) ELSE '' END";

		return 'LOWER(' . self::cut_before_sql( self::cut_before_sql( $path, '?' ), '#' ) . ')';
	}

	/**
	 * Per-page filter, appended verbatim to range-bound queries. An exact
	 * path match, never a substring one: filtering /domains/ must not
	 * count /old-domains/ or /foo/domains/. Two placeholders cover the
	 * stored URL with and without its trailing slash.
	 */
	public static function page_filter_sql() {
		return ' AND ' . self::page_path_sql() . ' IN (%s, %s)';
	}

	// Inputs: ranges and the page filter.

	/**
	 * A range key the dashboard understands, or the default.
	 */
	public static function sanitize_range_key( $raw, $default_key = '90' ) {
		$key = (string) $raw;

		return in_array( $key, self::RANGE_KEYS, true ) ? $key : $default_key;
	}

	/**
	 * Sanitized page filter, '' = all pages.
	 *
	 * @param string $raw Raw value.
	 * @return string Normalized path ('/', '/domains/', ...) or ''.
	 */
	public static function sanitize_page( $raw ) {
		$path = strtolower( trim( (string) $raw ) );

		if ( '' === $path || '/' === $path ) {
			return $path;
		}

		if ( strlen( $path ) > 191 || ! preg_match( '#^/[a-z0-9/._%\-]*$#', $path ) ) {
			return '';
		}

		return untrailingslashit( $path ) . '/';
	}

	/**
	 * The two IN() values for page_filter_sql().
	 */
	public static function page_params( $page_path ) {
		return array( $page_path, '/' === $page_path ? '' : untrailingslashit( $page_path ) );
	}

	/**
	 * @return array{0:string,1:array} SQL fragment and its params, both empty for "all pages".
	 */
	private static function page_clause( $page ) {
		if ( '' === (string) $page ) {
			return array( '', array() );
		}

		return array( self::page_filter_sql(), self::page_params( $page ) );
	}

	/**
	 * @return bool True when $from/$to are valid Y-m-d, ordered, max 2 years.
	 */
	public static function valid_custom_range( $from, $to ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
			return false;
		}

		$from_ts = strtotime( $from . ' 00:00:00' );
		$to_ts   = strtotime( $to . ' 00:00:00' );

		return $from_ts && $to_ts && $from_ts <= $to_ts && ( $to_ts - $from_ts ) <= 2 * YEAR_IN_SECONDS;
	}

	/**
	 * Today's local midday as a real UTC timestamp. Day buckets anchor here
	 * rather than on time(), because 86400 seconds is not one local day across
	 * a DST change: plain second arithmetic slides a bucket into the next
	 * calendar day while DATE(created_at) stays on the stored wall clock.
	 * Midday absorbs any real shift. NOT current_time(), wp_date() already
	 * adds the site offset.
	 */
	public static function day_anchor_ts() {
		return ( new DateTimeImmutable( wp_date( 'Y-m-d' ) . ' 12:00:00', wp_timezone() ) )->getTimestamp();
	}

	/**
	 * Start and exclusive end for a range, as plain values. Every query
	 * carries the same literal "created_at >= %s AND created_at < %s"
	 * condition and passes these two through $wpdb->prepare(), so no SQL
	 * fragment is ever built from a variable. Open-ended sides use the
	 * epoch and a far-future date.
	 */
	public static function bounds( $range_key, $from = '', $to = '' ) {
		if ( 'custom' === $range_key ) {
			return array( $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' );
		}

		if ( 'all' === $range_key ) {
			return array( self::RANGE_MIN, self::RANGE_MAX );
		}

		$len = (int) $range_key;

		return array( wp_date( 'Y-m-d 00:00:00', self::day_anchor_ts() - ( ( $len - 1 ) * DAY_IN_SECONDS ) ), self::RANGE_MAX );
	}

	/**
	 * Everything a report needs to know about one window: the bounds, the
	 * matching previous window for comparison, and the day anchor the trend
	 * buckets hang from.
	 *
	 * @return array{bounded:bool,custom:bool,len:int,start:string,end:string,prev_start:string,prev_end:string,anchor_ts:int,range_start:string,range_end:string}
	 */
	public static function window( $range_key, $from = '', $to = '' ) {
		$bounded = ( 'all' !== $range_key );
		$custom  = ( 'custom' === $range_key );
		$now_ts  = self::day_anchor_ts();
		$end     = ''; // Exclusive upper bound, custom range only.

		if ( $custom ) {
			// Dates are site-local calendar days; created_at is stored site-local.
			$len         = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
			$start       = $from . ' 00:00:00';
			$end         = gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00';
			$prev_start  = gmdate( 'Y-m-d', strtotime( $from . ' -' . $len . ' days' ) ) . ' 00:00:00';
			$anchor_ts   = ( new DateTimeImmutable( $to . ' 12:00:00', wp_timezone() ) )->getTimestamp();
			$range_start = $start;
			$range_end   = $end;
		} else {
			$len         = $bounded ? (int) $range_key : 0;
			$start       = $bounded ? wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( $len - 1 ) * DAY_IN_SECONDS ) ) : '';
			$prev_start  = $bounded ? wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( ( 2 * $len ) - 1 ) * DAY_IN_SECONDS ) ) : '';
			$anchor_ts   = $now_ts;
			$range_start = $bounded ? $start : self::RANGE_MIN;
			$range_end   = self::RANGE_MAX;
		}

		/*
		 * Today is only partly over, so the previous window has to stop at
		 * the same clock time it did $len days ago, otherwise every badge
		 * reads as a decline all morning and recovers by midnight.
		 */
		$prev_end = ( $bounded && ( '' === $end || strtotime( $end ) > $now_ts ) )
			? wp_date( 'Y-m-d H:i:s', $now_ts - ( $len * DAY_IN_SECONDS ) )
			: $start;

		return array(
			'bounded'     => $bounded,
			'custom'      => $custom,
			'len'         => $len,
			'start'       => $start,
			'end'         => $end,
			'prev_start'  => $prev_start,
			'prev_end'    => $prev_end,
			'anchor_ts'   => $anchor_ts,
			'range_start' => $range_start,
			'range_end'   => $range_end,
		);
	}

	// Aggregates.

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table_name is the fixed prefixed table; $page_sql is a class constant carrying its own placeholders.

	/**
	 * Aggregate KPI counts for a window. Empty bounds mean open ended.
	 */
	public static function kpis( $start = '', $end = '', $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		$start                          = '' !== $start ? $start : self::RANGE_MIN;
		$end                            = '' !== $end ? $end : self::RANGE_MAX;
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END),0) AS searches,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS cart_clicks,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN items_count ELSE 0 END),0) AS domains_added,
					COALESCE(SUM(CASE WHEN event_type = 'domain_transfer' THEN 1 ELSE 0 END),0) AS transfers,
					COALESCE(SUM(CASE WHEN event_type = 'product_add' THEN 1 ELSE 0 END),0) AS product_adds,
					COUNT(DISTINCT CASE WHEN event_type = 'domain_search' AND domain_query <> '' THEN domain_query END) AS unique_searches
				FROM {$table_name}
				WHERE created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $start, $end ), $page_params )
			),
			ARRAY_A
		);

		$searches = isset( $row['searches'] ) ? (int) $row['searches'] : 0;
		$uniques  = isset( $row['unique_searches'] ) ? (int) $row['unique_searches'] : 0;

		return array(
			'searches'       => $searches,
			'cartClicks'     => isset( $row['cart_clicks'] ) ? (int) $row['cart_clicks'] : 0,
			'domainsAdded'   => isset( $row['domains_added'] ) ? (int) $row['domains_added'] : 0,
			// Searches beyond the first for a name: total minus distinct names.
			'repeatSearches' => max( 0, $searches - $uniques ),
			// Not KPI cards, the panels that own these events read them.
			'transfers'      => isset( $row['transfers'] ) ? (int) $row['transfers'] : 0,
			'productAdds'    => isset( $row['product_adds'] ) ? (int) $row['product_adds'] : 0,
		);
	}

	/**
	 * Event counts per type for a window, every type the tracker knows.
	 *
	 * @return array<string,int>
	 */
	public static function by_type( $start = '', $end = '', $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		$start                          = '' !== $start ? $start : self::RANGE_MIN;
		$end                            = '' !== $end ? $end : self::RANGE_MAX;
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS n
				FROM {$table_name}
				WHERE created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY event_type",
				array_merge( array( $start, $end ), $page_params )
			)
		);

		$by_type = array();
		foreach ( self::EVENT_TYPES as $type ) {
			$by_type[ $type ] = 0;
		}
		foreach ( (array) $rows as $row ) {
			$by_type[ (string) $row->event_type ] = (int) $row->n;
		}

		return $by_type;
	}

	/**
	 * Daily buckets for bounded ranges, monthly for lifetime.
	 */
	public static function trend( $bounded, $len, $anchor_ts, $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		if ( $bounded ) {
			$start    = wp_date( 'Y-m-d 00:00:00', $anchor_ts - ( ( $len - 1 ) * DAY_IN_SECONDS ) );
			$rows     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE(created_at) AS bucket,
						COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END),0) AS searches,
						COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts
					FROM {$table_name}
					WHERE created_at >= %s{$page_sql}
					GROUP BY DATE(created_at)",
					array_merge( array( $start ), $page_params )
				),
				OBJECT_K
			);
			$labels   = array();
			$searches = array();
			$carts    = array();
			for ( $i = $len - 1; $i >= 0; $i-- ) {
				$ts         = $anchor_ts - ( $i * DAY_IN_SECONDS );
				$key        = wp_date( 'Y-m-d', $ts );
				$labels[]   = wp_date( 'M j', $ts );
				$searches[] = isset( $rows[ $key ] ) ? (int) $rows[ $key ]->searches : 0;
				$carts[]    = isset( $rows[ $key ] ) ? (int) $rows[ $key ]->carts : 0;
			}

			return array(
				'labels'   => $labels,
				'searches' => $searches,
				'carts'    => $carts,
			);
		}

		// Lifetime: monthly buckets, capped at the last 24 months of data.
		// The epoch lower bound keeps the query shape identical to the
		// bounded one, so the page filter appends the same way.
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS bucket,
					COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END),0) AS searches,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts
				FROM {$table_name}
				WHERE created_at >= %s{$page_sql}
				GROUP BY bucket
				ORDER BY bucket ASC",
				array_merge( array( self::RANGE_MIN ), $page_params )
			)
		);
		$rows     = array_slice( (array) $rows, -24 );
		$labels   = array();
		$searches = array();
		$carts    = array();
		foreach ( $rows as $row ) {
			$month_ts   = strtotime( (string) $row->bucket . '-01' );
			$labels[]   = $month_ts ? wp_date( 'M Y', $month_ts ) : (string) $row->bucket;
			$searches[] = (int) $row->searches;
			$carts[]    = (int) $row->carts;
		}

		return array(
			'labels'   => $labels,
			'searches' => $searches,
			'carts'    => $carts,
		);
	}

	/**
	 * Searched TLDs, most searched first.
	 *
	 * @return array<int,array{label:string,count:int}>
	 */
	public static function tlds( $start, $end, $page = '', $limit = 25, $offset = 0 ) {
		$items = array();
		foreach ( array_slice( self::tld_totals( $start, $end, $page ), (int) $offset, (int) $limit, true ) as $tld => $hits ) {
			$items[] = array(
				'label' => '.' . sanitize_key( (string) $tld ),
				'count' => (int) $hits,
			);
		}

		return $items;
	}

	public static function count_tlds( $start, $end, $page = '' ) {
		return count( self::tld_totals( $start, $end, $page ) );
	}

	/**
	 * Searches per TLD, most searched first, ties by name. The database
	 * groups by name; the TLD is cut off in PHP because "after the last
	 * dot" has no expression that both MySQL and SQLite accept. The rows
	 * that cross over are one per distinct searched name in the window.
	 *
	 * @return array<string,int> tld => searches, already sorted.
	 */
	private static function tld_totals( $start, $end, $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(domain_query) AS domain, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query LIKE %s AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY LOWER(domain_query)",
				array_merge( array( '%' . $wpdb->esc_like( '.' ) . '%', $start, $end ), $page_params )
			)
		);

		$totals = array();
		foreach ( (array) $rows as $row ) {
			$domain = (string) $row->domain;
			$domain = function_exists( 'mb_strtolower' ) ? mb_strtolower( $domain, 'UTF-8' ) : strtolower( $domain );
			$tld    = (string) substr( (string) strrchr( $domain, '.' ), 1 );

			$totals[ $tld ] = ( isset( $totals[ $tld ] ) ? $totals[ $tld ] : 0 ) + (int) $row->hits;
		}

		uksort(
			$totals,
			static function ( $a, $b ) use ( $totals ) {
				if ( $totals[ $a ] !== $totals[ $b ] ) {
					return $totals[ $b ] <=> $totals[ $a ];
				}
				return strcmp( (string) $a, (string) $b );
			}
		);

		return $totals;
	}

	/**
	 * Names searched two or more times inside the window.
	 *
	 * @return array<int,array{domain:string,hits:int}>
	 */
	public static function repeats( $start, $end, $page = '', $limit = 25, $offset = 0 ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query AS domain, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY domain_query
				HAVING hits >= 2
				ORDER BY hits DESC, domain ASC
				LIMIT %d OFFSET %d",
				array_merge( array( $start, $end ), $page_params, array( (int) $limit, (int) $offset ) )
			)
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'domain' => Reseller_Intent_Admin::display_domain( $row->domain ),
				'hits'   => (int) $row->hits,
			);
		}

		return $items;
	}

	public static function count_repeats( $start, $end, $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (SELECT 1 FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql} GROUP BY domain_query HAVING COUNT(*) >= 2) grouped",
				array_merge( array( $start, $end ), $page_params )
			)
		);
	}

	/**
	 * Searches by visitor country (2-letter geo header codes, never IPs).
	 *
	 * @return array<int,array{code:string,hits:int}>
	 */
	public static function countries( $start, $end, $page = '', $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND country <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY country
				ORDER BY hits DESC, country ASC
				LIMIT %d OFFSET %d",
				array_merge( array( $start, $end ), $page_params, array( (int) $limit, (int) $offset ) )
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'code' => isset( $row['country'] ) ? (string) $row['country'] : '',
				'hits' => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			);
		}

		return $items;
	}

	/**
	 * Domains carried to the cart, from the items_json of cart clicks. The
	 * scan is bounded at 2000 rows; every caller slices the same aggregate,
	 * so the first page and the deeper pages rank from one sample.
	 *
	 * @return array{domains:array<int,array{domain:string,count:int}>,total:int}
	 */
	public static function carted( $start, $end, $page = '', $limit = 15, $offset = 0 ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$json_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT items_json FROM {$table_name}
				WHERE event_type = 'continue_to_cart' AND items_json IS NOT NULL AND items_json <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				ORDER BY id DESC
				LIMIT 2000",
				array_merge( array( $start, $end ), $page_params )
			)
		);

		$domain_counts = array();
		foreach ( (array) $json_rows as $json_row ) {
			foreach ( self::extract_carted_domains( (string) $json_row->items_json ) as $domain ) {
				$domain_counts[ $domain ] = isset( $domain_counts[ $domain ] ) ? $domain_counts[ $domain ] + 1 : 1;
			}
		}
		// Most carried first; equal counts fall back to the name, so the
		// order is the same on every reload.
		uksort(
			$domain_counts,
			static function ( $a, $b ) use ( $domain_counts ) {
				if ( $domain_counts[ $a ] !== $domain_counts[ $b ] ) {
					return $domain_counts[ $b ] <=> $domain_counts[ $a ];
				}
				return strcmp( $a, $b );
			}
		);

		$domains = array();
		foreach ( array_slice( $domain_counts, (int) $offset, (int) $limit, true ) as $domain => $count ) {
			$domains[] = array(
				'domain' => Reseller_Intent_Admin::display_domain( $domain ),
				'count'  => (int) $count,
			);
		}

		return array(
			'domains' => $domains,
			'total'   => count( $domain_counts ),
		);
	}

	/**
	 * Top domain_query values for one event type, most hits first. Transfer
	 * searches and product adds are both plain "which name, how often"
	 * lists, so they share this instead of carrying a query each.
	 *
	 * @return array<int,array{label:string,count:int}>
	 */
	public static function ranking( $event_type, $start, $end, $page = '', $limit = 15, $offset = 0 ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = %s AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY domain_query
				ORDER BY hits DESC, domain_query ASC
				LIMIT %d OFFSET %d",
				array_merge( array( $event_type, $start, $end ), $page_params, array( (int) $limit, (int) $offset ) )
			)
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'label' => Reseller_Intent_Admin::display_domain( (string) $row->domain_query ),
				'count' => (int) $row->hits,
			);
		}

		return $items;
	}

	/**
	 * How many distinct names the ranking above has in total.
	 */
	public static function count_ranking( $event_type, $start, $end, $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT domain_query)
				FROM {$table_name}
				WHERE event_type = %s AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $event_type, $start, $end ), $page_params )
			)
		);
	}

	/**
	 * How many domains each cart click carried.
	 *
	 * @return array<int,array{label:string,count:int}>
	 */
	public static function cart_sizes( $start, $end, $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN items_count <= 1 THEN 1 ELSE 0 END),0) AS b1,
					COALESCE(SUM(CASE WHEN items_count = 2 THEN 1 ELSE 0 END),0) AS b2,
					COALESCE(SUM(CASE WHEN items_count = 3 THEN 1 ELSE 0 END),0) AS b3,
					COALESCE(SUM(CASE WHEN items_count >= 4 THEN 1 ELSE 0 END),0) AS b4
				FROM {$table_name}
				WHERE event_type = 'continue_to_cart' AND created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $start, $end ), $page_params )
			),
			ARRAY_A
		);
		$row = is_array( $row ) ? array_map( 'intval', $row ) : array();

		return array(
			array(
				'label' => '1×',
				'count' => isset( $row['b1'] ) ? $row['b1'] : 0,
			),
			array(
				'label' => '2×',
				'count' => isset( $row['b2'] ) ? $row['b2'] : 0,
			),
			array(
				'label' => '3×',
				'count' => isset( $row['b3'] ) ? $row['b3'] : 0,
			),
			array(
				'label' => '4+',
				'count' => isset( $row['b4'] ) ? $row['b4'] : 0,
			),
		);
	}

	/**
	 * @return array{available:int,taken:int}
	 */
	public static function availability( $start, $end, $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END),0) AS avail,
					COALESCE(SUM(CASE WHEN is_available = 0 THEN 1 ELSE 0 END),0) AS taken
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND is_available IS NOT NULL AND created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $start, $end ), $page_params )
			),
			ARRAY_A
		);

		return array(
			'available' => isset( $row['avail'] ) ? (int) $row['avail'] : 0,
			'taken'     => isset( $row['taken'] ) ? (int) $row['taken'] : 0,
		);
	}

	/**
	 * @return array{mobile:int,tablet:int,desktop:int}
	 */
	public static function devices( $start, $end, $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT device, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND device IN ('mobile','tablet','desktop') AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY device",
				array_merge( array( $start, $end ), $page_params )
			),
			ARRAY_A
		);

		$devices = array(
			'mobile'  => 0,
			'tablet'  => 0,
			'desktop' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$key = isset( $row['device'] ) ? (string) $row['device'] : '';
			if ( isset( $devices[ $key ] ) ) {
				$devices[ $key ] = (int) $row['hits'];
			}
		}

		return $devices;
	}

	/**
	 * The three links that leave the site carrying nothing to rank.
	 *
	 * @return array{cart:int,login:int,phone:int}
	 */
	public static function outbound( $start, $end, $page = '' ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN event_type = 'cart_view' THEN 1 ELSE 0 END),0) AS cart,
					COALESCE(SUM(CASE WHEN event_type = 'login_click' THEN 1 ELSE 0 END),0) AS login,
					COALESCE(SUM(CASE WHEN event_type = 'phone_click' THEN 1 ELSE 0 END),0) AS phone
				FROM {$table_name}
				WHERE created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $start, $end ), $page_params )
			),
			ARRAY_A
		);

		return array(
			'cart'  => isset( $row['cart'] ) ? (int) $row['cart'] : 0,
			'login' => isset( $row['login'] ) ? (int) $row['login'] : 0,
			'phone' => isset( $row['phone'] ) ? (int) $row['phone'] : 0,
		);
	}

	/**
	 * Searches and cart clicks grouped by the page the widget sits on.
	 *
	 * page_url stores the full URL (query strings included), so rows are
	 * grouped in SQL first, then merged by normalized path here, UTM
	 * variants of the same page collapse into one row. Never page-filtered:
	 * this is the page comparator and feeds the filter its options.
	 *
	 * @return array<int,array{path:string,searches:int,carts:int,events:int,label:string}>
	 */
	public static function pages( $start, $end ) {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();

		/*
		 * Group on the URL without its query string, not the whole URL. One
		 * page can carry hundreds of distinct URLs once campaign parameters
		 * and ?domainToCheck= links are in play, and collapsing those in PHP
		 * afterwards was too late: the LIMIT had already thrown most of them
		 * away, so a busy landing page could rank below a quiet one or drop
		 * off entirely. The limit now bounds distinct paths, of which a real
		 * site has a handful. normalize_page_path() still merges what is
		 * left, the scheme, host, casing and trailing slash.
		 */
		$clean_url = self::page_url_clean_sql();
		$page_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$clean_url} AS page_url,
					COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END),0) AS searches,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts,
					COUNT(*) AS events
				FROM {$table_name}
				WHERE page_url IS NOT NULL AND page_url <> '' AND created_at >= %s AND created_at < %s
				GROUP BY 1
				ORDER BY searches DESC
				LIMIT 200",
				$start,
				$end
			)
		);

		$by_path = array();
		foreach ( (array) $page_rows as $page_row ) {
			$path = self::normalize_page_path( (string) $page_row->page_url );
			if ( '' === $path ) {
				continue;
			}
			if ( ! isset( $by_path[ $path ] ) ) {
				$by_path[ $path ] = array(
					'path'     => $path,
					'searches' => 0,
					'carts'    => 0,
					'events'   => 0,
				);
			}
			$by_path[ $path ]['searches'] += (int) $page_row->searches;
			$by_path[ $path ]['carts']    += (int) $page_row->carts;
			$by_path[ $path ]['events']   += (int) $page_row->events;
		}

		usort(
			$by_path,
			static function ( $a, $b ) {
				if ( $a['searches'] !== $b['searches'] ) {
					return $b['searches'] <=> $a['searches'];
				}
				return strcmp( $a['path'], $b['path'] );
			}
		);

		// 50 paths = five pager pages; a real site has a handful.
		$top = array_slice( array_values( $by_path ), 0, 50 );
		foreach ( $top as &$page ) {
			$page['label'] = self::page_label_for_path( $page['path'] );
		}
		unset( $page );

		return $top;
	}

	/**
	 * Every page carrying any tracked event, busiest first, for a filter
	 * dropdown that has to reach pages which only ever saw a product add.
	 */
	public static function page_options( $rows ) {
		$rows = (array) $rows;

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['events'] <=> $a['events'];
			}
		);

		$options = array();
		foreach ( $rows as $row ) {
			$options[] = array(
				'path'  => $row['path'],
				'label' => $row['label'],
			);
		}

		return $options;
	}

	/**
	 * Latest searches in range, newest first. Transfer searches ride along,
	 * they are the same act with a different destination.
	 *
	 * @return array<int,array{domain:string,time:string,available:?bool,device:string,transfer:bool}>
	 */
	public static function recent( $start, $end, $page = '', $limit = 1000 ) {
		global $wpdb;

		$table_name                     = Reseller_Intent_DB::table_name();
		list( $page_sql, $page_params ) = self::page_clause( $page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query, created_at, is_available, device, event_type
				FROM {$table_name}
				WHERE event_type IN ('domain_search', 'domain_transfer') AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				ORDER BY id DESC
				LIMIT %d",
				array_merge( array( $start, $end ), $page_params, array( (int) $limit ) )
			)
		);

		$recent = array();
		foreach ( (array) $rows as $row ) {
			$recent[] = array(
				'domain'    => Reseller_Intent_Admin::display_domain( $row->domain_query ),
				'time'      => self::format_datetime_local( (string) $row->created_at ),
				'available' => ( null === $row->is_available || '' === (string) $row->is_available ) ? null : (bool) (int) $row->is_available,
				'device'    => (string) $row->device,
				'transfer'  => 'domain_transfer' === (string) $row->event_type,
			);
		}

		return $recent;
	}

	/**
	 * Time since the newest event, any range. Surfaces silent breakage (JS
	 * error, markup drift, blocked AJAX) at a glance.
	 *
	 * @return array{at:string,ts:int,age:int,stale:bool}
	 */
	public static function last_event() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();
		$at         = (string) $wpdb->get_var( "SELECT MAX(created_at) FROM {$table_name}" );
		// created_at is stored in site-local time, so diff against local now.
		$ts  = $at ? (int) strtotime( $at ) : 0;
		$age = $ts ? max( 0, strtotime( current_time( 'mysql' ) ) - $ts ) : 0;

		return array(
			'at'    => $at,
			'ts'    => $ts,
			'age'   => $age,
			'stale' => $ts ? ( $age > 3 * DAY_IN_SECONDS ) : false,
		);
	}

	/**
	 * The dashboard glance widget's week, in one query: this runs on every
	 * visit to the WordPress dashboard, the busiest screen in the admin.
	 * Searches count transfers too and carts count product adds, because a
	 * storefront selling hosting through product pods was reading zeroes
	 * here while its dashboard showed the adds.
	 *
	 * @return array<string,int>
	 */
	public static function glance( $week_start, $prev_start ) {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN created_at >= %s AND event_type IN ('domain_search','domain_transfer') THEN 1 ELSE 0 END) AS searches_now,
					SUM(CASE WHEN created_at <  %s AND event_type IN ('domain_search','domain_transfer') THEN 1 ELSE 0 END) AS searches_prev,
					SUM(CASE WHEN created_at >= %s AND event_type IN ('continue_to_cart','product_add') THEN 1 ELSE 0 END) AS carts_now,
					SUM(CASE WHEN created_at <  %s AND event_type IN ('continue_to_cart','product_add') THEN 1 ELSE 0 END) AS carts_prev,
					SUM(CASE WHEN created_at >= %s AND event_type = 'domain_search' THEN 1 ELSE 0 END) AS rate_base_now,
					SUM(CASE WHEN created_at <  %s AND event_type = 'domain_search' THEN 1 ELSE 0 END) AS rate_base_prev,
					SUM(CASE WHEN created_at >= %s AND event_type = 'continue_to_cart' THEN 1 ELSE 0 END) AS cart_clicks_now,
					SUM(CASE WHEN created_at <  %s AND event_type = 'continue_to_cart' THEN 1 ELSE 0 END) AS cart_clicks_prev,
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS events_now
				FROM {$table_name}
				WHERE created_at >= %s",
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$prev_start
			),
			ARRAY_A
		);

		$out = array();
		foreach ( array( 'searches_now', 'searches_prev', 'carts_now', 'carts_prev', 'rate_base_now', 'rate_base_prev', 'cart_clicks_now', 'cart_clicks_prev', 'events_now' ) as $key ) {
			$out[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}

		return $out;
	}

	/**
	 * The one name people kept typing this week, or null.
	 *
	 * @return object|null {label, hits}
	 */
	public static function glance_lead( $week_start ) {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT domain_query AS label, COUNT(*) AS hits
				FROM {$table_name}
				WHERE created_at >= %s
					AND domain_query <> ''
					AND event_type IN ('domain_search','domain_transfer','product_add')
				GROUP BY domain_query
				ORDER BY hits DESC, label ASC
				LIMIT 1",
				$week_start
			)
		);
	}

	/**
	 * Row count for the whole table.
	 */
	public static function total_events() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

	// Non-event reports.

	/**
	 * The TLD strip's last good prices, one entry per configured set, with
	 * the refresh schedule around them.
	 *
	 * @return array{sets:array<int,array{tlds:array<string,string>}>,last_refresh:string,next_refresh:string}
	 */
	public static function tld_prices() {
		$last_good = (array) get_option( Reseller_Intent_TLD_Strip::LAST_GOOD, array() );
		$sets      = array();

		foreach ( $last_good as $set ) {
			$prices = array();
			foreach ( (array) $set as $tld => $price ) {
				$prices[ (string) $tld ] = (string) $price;
			}
			if ( $prices ) {
				$sets[] = array( 'tlds' => $prices );
			}
		}

		$last = Reseller_Intent_TLD_Strip::last_refresh_ts();
		$next = Reseller_Intent_TLD_Strip::next_refresh_ts();

		return array(
			'sets'         => $sets,
			'last_refresh' => $last ? wp_date( 'c', $last ) : '',
			'next_refresh' => $next ? wp_date( 'c', $next ) : '',
		);
	}

	/**
	 * Is tracking alive, and what is it writing into.
	 *
	 * @return array{version:string,table:string,events:int,last_event:string,last_event_age:int,stale:bool,retention_days:int,timezone:string}
	 */
	public static function status() {
		$last = self::last_event();
		$data = get_file_data( RINTENT_FILE, array( 'Version' => 'Version' ) );

		return array(
			'version'        => (string) ( $data['Version'] ?? '' ),
			'table'          => Reseller_Intent_DB::table_name(),
			'events'         => self::total_events(),
			'last_event'     => $last['at'],
			'last_event_age' => $last['age'],
			'stale'          => $last['stale'],
			'retention_days' => (int) Reseller_Intent_Settings::get( 'retention_days' ),
			'timezone'       => wp_timezone_string(),
		);
	}

	// Helpers.

	/**
	 * Label for a path: the path itself, which is short and unambiguous.
	 * Page titles can be long enough to wreck the column; only the home
	 * page gets a word, its path says nothing.
	 */
	public static function page_label_for_path( $path ) {
		if ( '/' === $path ) {
			return __( 'Home', 'reseller-intent' );
		}

		return $path;
	}

	public static function normalize_page_path( $page_url ) {
		$path = wp_parse_url( $page_url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$path = strtolower( $path );
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path ) . '/';
		}

		return substr( $path, 0, 191 );
	}

	public static function format_datetime_local( $datetime_raw ) {
		$datetime_raw = trim( (string) $datetime_raw );
		if ( '' === $datetime_raw ) {
			return '';
		}

		// created_at is stored in the site's local timezone already.
		return substr( $datetime_raw, 0, 16 );
	}

	/**
	 * Pull domain names out of a continue_to_cart items_json payload.
	 */
	public static function extract_carted_domains( $items_json ) {
		$decoded = json_decode( $items_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$domains = array();
		foreach ( $decoded as $entry ) {
			$candidate = '';
			if ( is_string( $entry ) ) {
				$candidate = $entry;
			} elseif ( is_array( $entry ) ) {
				foreach ( array( 'domain', 'domainName', 'domain_name', 'name', 'label' ) as $entry_key ) {
					if ( isset( $entry[ $entry_key ] ) && is_string( $entry[ $entry_key ] ) ) {
						$candidate = $entry[ $entry_key ];
						break;
					}
				}
			}

			$candidate = strtolower( trim( (string) $candidate ) );
			if ( '' === $candidate || strlen( $candidate ) > 191 ) {
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9][a-z0-9.-]*$/', $candidate ) ) {
				continue;
			}
			$domains[] = $candidate;
		}

		return $domains;
	}
}
