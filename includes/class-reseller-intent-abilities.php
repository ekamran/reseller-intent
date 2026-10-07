<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities API: the dashboard's data, readable by AI agents and anything
 * else that speaks WordPress abilities (the REST endpoint, MCP adapters,
 * WP-CLI).
 *
 * WordPress 6.9 brought the API into core. This plugin supports 6.2, so
 * registration hangs off the core hooks, which simply never fire on an
 * older site; nothing here is reached there. The same callbacks also back
 * `wp rintent report`, so the CLI works everywhere the plugin does.
 *
 * Every ability is an aggregate over anonymous events, gated by the same
 * capability as the dashboard. Nothing returns a raw row, an IP, a user
 * or a cookie, because none of those are stored to begin with.
 */
final class Reseller_Intent_Abilities {

	const NS       = 'reseller-intent';
	const CATEGORY = 'reseller-intent';

	public function register() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Reseller Intent', 'reseller-intent' ),
				'description' => __( 'What visitors search, pick and take to cart on a GoDaddy Reseller Store storefront. Anonymous aggregates only, never individual visitors.', 'reseller-intent' ),
			)
		);
	}

	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::definitions() as $slug => $args ) {
			wp_register_ability( self::NS . '/' . $slug, $args );
		}
	}

	/**
	 * Runs one ability's callback directly, without going through the
	 * registry. This is how WP-CLI reaches the same numbers on a WordPress
	 * too old to have the Abilities API.
	 *
	 * The capability gate protects HTTP callers. A shell with WP-CLI already
	 * reads the database directly, so the CLI passes $check_permission as
	 * false and runs as whoever holds the terminal, the way `wp rintent
	 * stats` and `wp rintent export` always have.
	 *
	 * @return array|WP_Error
	 */
	public static function run( $slug, $input = array(), $check_permission = true ) {
		$defs = self::definitions();

		if ( ! isset( $defs[ $slug ] ) ) {
			return new WP_Error( 'rintent_unknown_ability', __( 'Unknown report.', 'reseller-intent' ), array( 'status' => 404 ) );
		}

		if ( $check_permission && ! call_user_func( $defs[ $slug ]['permission_callback'] ) ) {
			return new WP_Error( 'rintent_forbidden', __( 'Sorry, you are not allowed to read this.', 'reseller-intent' ), array( 'status' => 403 ) );
		}

		return call_user_func( $defs[ $slug ]['execute_callback'], $input );
	}

	/**
	 * The slugs, for the CLI's help text and the tests.
	 */
	public static function slugs() {
		return array_keys( self::definitions() );
	}

	// Definitions.

	/**
	 * @return array<string,array> slug => wp_register_ability() args.
	 */
	public static function definitions() {
		$read = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'public'       => true,
			'show_in_rest' => true,
		);

		$permission = array( __CLASS__, 'can_read' );

		return array(
			'get-summary'           => array(
				'label'               => __( 'Get summary', 'reseller-intent' ),
				'description'         => __( 'The storefront funnel for a window: searches, cart clicks, domains added, repeat searches, transfers and product adds, with the previous window for comparison, plus event counts by type, availability split, device split, cart sizes and when the last event arrived.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'range'          => self::range_output(),
						'kpis'           => array(
							'type'       => 'object',
							'properties' => array(
								'now'  => self::kpi_schema(),
								'prev' => array(
									'type'        => array( 'object', 'null' ),
									'description' => __( 'The same counts for the window before this one; null for all time.', 'reseller-intent' ),
								),
							),
						),
						'events_by_type' => array(
							'type'        => 'object',
							'description' => __( 'Event count per type.', 'reseller-intent' ),
						),
						'outbound'       => self::int_map( array( 'cart', 'login', 'phone' ) ),
						'availability'   => self::int_map( array( 'available', 'taken' ) ),
						'devices'        => self::int_map( array( 'mobile', 'tablet', 'desktop' ) ),
						'cart_sizes'     => self::items_schema(
							array(
								'label' => 'string',
								'count' => 'integer',
							)
						),
						'last_event'     => array(
							'type'       => 'object',
							'properties' => array(
								'at'          => array( 'type' => 'string' ),
								'age_seconds' => array( 'type' => 'integer' ),
								'stale'       => array( 'type' => 'boolean' ),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'exec_summary' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-top-searches'      => array(
				'label'               => __( 'Get top searches', 'reseller-intent' ),
				'description'         => __( 'The domain names visitors searched most, with how many times each. Set repeats_only to see only names searched twice or more.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input(
					array(
						'limit'        => self::limit_schema( 25, 100 ),
						'repeats_only' => array(
							'type'        => 'boolean',
							'description' => __( 'Only names searched two or more times.', 'reseller-intent' ),
						),
					)
				),
				'output_schema'       => self::list_output(
					array(
						'domain'   => 'string',
						'searches' => 'integer',
					)
				),
				'execute_callback'    => array( __CLASS__, 'exec_top_searches' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-top-tlds'          => array(
				'label'               => __( 'Get top TLDs', 'reseller-intent' ),
				'description'         => __( 'Which extensions visitors search for, most searched first.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input( array( 'limit' => self::limit_schema( 25, 100 ) ) ),
				'output_schema'       => self::list_output(
					array(
						'tld'      => 'string',
						'searches' => 'integer',
					)
				),
				'execute_callback'    => array( __CLASS__, 'exec_top_tlds' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-countries'         => array(
				'label'               => __( 'Get countries', 'reseller-intent' ),
				'description'         => __( 'Searches by visitor country as two-letter codes. Country comes from the edge geo header, no IP address is ever stored.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input( array( 'limit' => self::limit_schema( 20, 100 ) ) ),
				'output_schema'       => self::list_output(
					array(
						'code'     => 'string',
						'searches' => 'integer',
					)
				),
				'execute_callback'    => array( __CLASS__, 'exec_countries' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-carted-domains'    => array(
				'label'               => __( 'Get carted domains', 'reseller-intent' ),
				'description'         => __( 'Domain names visitors carried to the cart, most carried first.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input( array( 'limit' => self::limit_schema( 15, 100 ) ) ),
				'output_schema'       => self::list_output(
					array(
						'domain' => 'string',
						'carts'  => 'integer',
					)
				),
				'execute_callback'    => array( __CLASS__, 'exec_carted' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-product-adds'      => array(
				'label'               => __( 'Get product adds', 'reseller-intent' ),
				'description'         => __( 'Hosting, email, SSL and other products visitors added to the cart, most added first.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input( array( 'limit' => self::limit_schema( 15, 100 ) ) ),
				'output_schema'       => self::list_output(
					array(
						'product' => 'string',
						'adds'    => 'integer',
					)
				),
				'execute_callback'    => array( __CLASS__, 'exec_product_adds' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-transfer-searches' => array(
				'label'               => __( 'Get transfer searches', 'reseller-intent' ),
				'description'         => __( 'Domain names visitors looked up for a transfer in, most looked up first.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input( array( 'limit' => self::limit_schema( 15, 100 ) ) ),
				'output_schema'       => self::list_output(
					array(
						'domain'   => 'string',
						'searches' => 'integer',
					)
				),
				'execute_callback'    => array( __CLASS__, 'exec_transfers' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-pages'             => array(
				'label'               => __( 'Get pages', 'reseller-intent' ),
				'description'         => __( 'Searches and cart clicks per page the widgets sit on, busiest first. Never filtered by page, it is the page comparator.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'range' => self::range_output(),
						'items' => self::items_schema(
							array(
								'path'        => 'string',
								'label'       => 'string',
								'searches'    => 'integer',
								'cart_clicks' => 'integer',
								'events'      => 'integer',
							)
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'exec_pages' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-trend'             => array(
				'label'               => __( 'Get trend', 'reseller-intent' ),
				'description'         => __( 'Searches and cart clicks per day for a bounded window, per month for all time.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'range'       => self::range_output(),
						'labels'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'searches'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'cart_clicks' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'exec_trend' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-recent-searches'   => array(
				'label'               => __( 'Get recent searches', 'reseller-intent' ),
				'description'         => __( 'The latest domain and transfer searches, newest first, with availability and device. Anonymous: no visitor can be told apart from another.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::range_input( array( 'limit' => self::limit_schema( 50, 200 ) ) ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'range' => self::range_output(),
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'domain'    => array( 'type' => 'string' ),
									'time'      => array( 'type' => 'string' ),
									'available' => array( 'type' => array( 'boolean', 'null' ) ),
									'device'    => array( 'type' => 'string' ),
									'transfer'  => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'exec_recent' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-tld-prices'        => array(
				'label'               => __( 'Get TLD prices', 'reseller-intent' ),
				'description'         => __( 'The current registration prices the TLD price strip shows, one set per strip placed on the site, with the last and next refresh times.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'sets'         => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'tlds' => array(
										'type'        => 'object',
										'description' => __( 'Extension to price text, like ".com" to "$12.99".', 'reseller-intent' ),
									),
								),
							),
						),
						'last_refresh' => array( 'type' => 'string' ),
						'next_refresh' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'exec_tld_prices' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'get-tracking-status'   => array(
				'label'               => __( 'Get tracking status', 'reseller-intent' ),
				'description'         => __( 'Is tracking alive: plugin version, total events, when the last one arrived, whether that is stale, and the retention setting.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'version'        => array( 'type' => 'string' ),
						'table'          => array( 'type' => 'string' ),
						'events'         => array( 'type' => 'integer' ),
						'last_event'     => array( 'type' => 'string' ),
						'last_event_age' => array( 'type' => 'integer' ),
						'stale'          => array( 'type' => 'boolean' ),
						'retention_days' => array( 'type' => 'integer' ),
						'timezone'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'exec_status' ),
				'permission_callback' => $permission,
				'meta'                => $read,
			),
			'refresh-tld-prices'    => array(
				'label'               => __( 'Refresh TLD prices', 'reseller-intent' ),
				'description'         => __( 'Fetches fresh prices for every TLD strip on the site right now instead of waiting for the scheduled refresh.', 'reseller-intent' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'refreshed_sets' => array( 'type' => 'integer' ),
						'last_refresh'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'exec_refresh_tld_prices' ),
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'public'       => true,
					'show_in_rest' => true,
				),
			),
		);
	}

	// Schema helpers.

	private static function range_input( $extra = array() ) {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'range' => array(
						'type'        => 'string',
						'enum'        => Reseller_Intent_Reports::RANGE_KEYS,
						'description' => __( 'Window: the last 7, 30 or 90 days, all time, or custom with from and to. Default 30.', 'reseller-intent' ),
					),
					'from'  => array(
						'type'        => 'string',
						'pattern'     => '^\d{4}-\d{2}-\d{2}$',
						'description' => __( 'Custom range start, YYYY-MM-DD in the site timezone.', 'reseller-intent' ),
					),
					'to'    => array(
						'type'        => 'string',
						'pattern'     => '^\d{4}-\d{2}-\d{2}$',
						'description' => __( 'Custom range end, YYYY-MM-DD, inclusive.', 'reseller-intent' ),
					),
					'page'  => array(
						'type'        => 'string',
						'description' => __( 'Only events from this page path, like /domains/. Leave empty for every page.', 'reseller-intent' ),
					),
				),
				$extra
			),
			'additionalProperties' => false,
		);
	}

	private static function limit_schema( $default_limit, $max ) {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => $max,
			/* translators: %d: default number of rows */
			'description' => sprintf( __( 'Rows to return. Default %d.', 'reseller-intent' ), $default_limit ),
		);
	}

	private static function range_output() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'key'   => array( 'type' => 'string' ),
				'label' => array( 'type' => 'string' ),
				'start' => array( 'type' => 'string' ),
				'end'   => array( 'type' => 'string' ),
				'page'  => array( 'type' => 'string' ),
			),
		);
	}

	private static function kpi_schema() {
		return self::int_map( array( 'searches', 'cartClicks', 'domainsAdded', 'repeatSearches', 'transfers', 'productAdds' ) );
	}

	private static function int_map( array $keys ) {
		$props = array();
		foreach ( $keys as $key ) {
			$props[ $key ] = array( 'type' => 'integer' );
		}

		return array(
			'type'       => 'object',
			'properties' => $props,
		);
	}

	private static function items_schema( array $fields ) {
		$props = array();
		foreach ( $fields as $key => $type ) {
			$props[ $key ] = array( 'type' => $type );
		}

		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => $props,
			),
		);
	}

	private static function list_output( array $fields ) {
		return array(
			'type'       => 'object',
			'properties' => array(
				'range' => self::range_output(),
				'items' => self::items_schema( $fields ),
				'total' => array(
					'type'        => 'integer',
					'description' => __( 'How many distinct entries the whole window has, beyond the rows returned.', 'reseller-intent' ),
				),
			),
		);
	}

	// Input helpers.

	public static function can_read() {
		return current_user_can( Reseller_Intent_Admin::capability() );
	}

	/**
	 * The window an input asks for, or a WP_Error when a custom range is
	 * not usable. Default is the last 30 days, every page.
	 *
	 * @return array|WP_Error
	 */
	private static function window_from_input( $input ) {
		$input = is_array( $input ) ? $input : array();
		$range = Reseller_Intent_Reports::sanitize_range_key( $input['range'] ?? '30', '30' );
		$from  = (string) ( $input['from'] ?? '' );
		$to    = (string) ( $input['to'] ?? '' );
		$page  = Reseller_Intent_Reports::sanitize_page( $input['page'] ?? '' );

		if ( 'custom' === $range && ! Reseller_Intent_Reports::valid_custom_range( $from, $to ) ) {
			return new WP_Error( 'rintent_bad_range', __( 'A custom range needs from and to as YYYY-MM-DD, in order, at most two years apart.', 'reseller-intent' ), array( 'status' => 400 ) );
		}

		$win          = Reseller_Intent_Reports::window( $range, $from, $to );
		$win['key']   = $range;
		$win['page']  = $page;
		$win['label'] = $win['custom']
			? sprintf(
				/* translators: 1: range start date, 2: range end date */
				__( '%1$s to %2$s', 'reseller-intent' ),
				wp_date( 'M j, Y', strtotime( $from . ' 12:00:00' ) ),
				wp_date( 'M j, Y', strtotime( $to . ' 12:00:00' ) )
			)
			: ( $win['bounded']
				/* translators: %d: number of days */
				? sprintf( __( 'Last %d days', 'reseller-intent' ), $win['len'] )
				: __( 'Lifetime', 'reseller-intent' ) );

		return $win;
	}

	private static function range_out( array $win ) {
		return array(
			'key'   => $win['key'],
			'label' => $win['label'],
			'start' => $win['range_start'],
			'end'   => $win['range_end'],
			'page'  => $win['page'],
		);
	}

	private static function limit_from_input( $input, $default_limit, $max ) {
		$input = is_array( $input ) ? $input : array();
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : $default_limit;

		return max( 1, min( $max, $limit ) );
	}

	/**
	 * A label/count ranking as a list with the caller's own key names.
	 */
	private static function relabel( array $items, $from_a, $to_a, $from_b, $to_b ) {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				$to_a => $item[ $from_a ],
				$to_b => (int) $item[ $from_b ],
			);
		}

		return $out;
	}

	// Execute callbacks.

	public static function exec_summary( $input = array() ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$last = Reseller_Intent_Reports::last_event();

		return array(
			'range'          => self::range_out( $win ),
			'kpis'           => array(
				'now'  => Reseller_Intent_Reports::kpis( $win['start'], $win['end'], $win['page'] ),
				'prev' => $win['bounded'] ? Reseller_Intent_Reports::kpis( $win['prev_start'], $win['prev_end'], $win['page'] ) : null,
			),
			'events_by_type' => Reseller_Intent_Reports::by_type( $win['range_start'], $win['range_end'], $win['page'] ),
			'outbound'       => Reseller_Intent_Reports::outbound( $win['range_start'], $win['range_end'], $win['page'] ),
			'availability'   => Reseller_Intent_Reports::availability( $win['range_start'], $win['range_end'], $win['page'] ),
			'devices'        => Reseller_Intent_Reports::devices( $win['range_start'], $win['range_end'], $win['page'] ),
			'cart_sizes'     => Reseller_Intent_Reports::cart_sizes( $win['range_start'], $win['range_end'], $win['page'] ),
			'last_event'     => array(
				'at'          => $last['at'],
				'age_seconds' => $last['age'],
				'stale'       => $last['stale'],
			),
		);
	}

	public static function exec_top_searches( $input = array() ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$limit = self::limit_from_input( $input, 25, 100 );

		if ( ! empty( $input['repeats_only'] ) ) {
			return array(
				'range' => self::range_out( $win ),
				'items' => self::relabel( Reseller_Intent_Reports::repeats( $win['range_start'], $win['range_end'], $win['page'], $limit ), 'domain', 'domain', 'hits', 'searches' ),
				'total' => Reseller_Intent_Reports::count_repeats( $win['range_start'], $win['range_end'], $win['page'] ),
			);
		}

		return array(
			'range' => self::range_out( $win ),
			'items' => self::relabel( Reseller_Intent_Reports::ranking( 'domain_search', $win['range_start'], $win['range_end'], $win['page'], $limit ), 'label', 'domain', 'count', 'searches' ),
			'total' => Reseller_Intent_Reports::count_ranking( 'domain_search', $win['range_start'], $win['range_end'], $win['page'] ),
		);
	}

	public static function exec_top_tlds( $input = array() ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$limit = self::limit_from_input( $input, 25, 100 );

		return array(
			'range' => self::range_out( $win ),
			'items' => self::relabel( Reseller_Intent_Reports::tlds( $win['range_start'], $win['range_end'], $win['page'], $limit ), 'label', 'tld', 'count', 'searches' ),
			'total' => Reseller_Intent_Reports::count_tlds( $win['range_start'], $win['range_end'], $win['page'] ),
		);
	}

	public static function exec_countries( $input = array() ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$limit = self::limit_from_input( $input, 20, 100 );
		$items = self::relabel( Reseller_Intent_Reports::countries( $win['range_start'], $win['range_end'], $win['page'], $limit ), 'code', 'code', 'hits', 'searches' );

		// Countries carry no separate distinct count; the sum is the total.
		$total = 0;
		foreach ( Reseller_Intent_Reports::countries( $win['range_start'], $win['range_end'], $win['page'], 500 ) as $row ) {
			$total += $row['hits'];
		}

		return array(
			'range' => self::range_out( $win ),
			'items' => $items,
			'total' => $total,
		);
	}

	public static function exec_carted( $input = array() ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$limit  = self::limit_from_input( $input, 15, 100 );
		$carted = Reseller_Intent_Reports::carted( $win['range_start'], $win['range_end'], $win['page'], $limit );

		return array(
			'range' => self::range_out( $win ),
			'items' => self::relabel( $carted['domains'], 'domain', 'domain', 'count', 'carts' ),
			'total' => (int) $carted['total'],
		);
	}

	public static function exec_product_adds( $input = array() ) {
		return self::ranking_report( $input, 'product_add', 'product', 'adds', 15 );
	}

	public static function exec_transfers( $input = array() ) {
		return self::ranking_report( $input, 'domain_transfer', 'domain', 'searches', 15 );
	}

	private static function ranking_report( $input, $event_type, $label_key, $count_key, $default_limit ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$limit = self::limit_from_input( $input, $default_limit, 100 );

		return array(
			'range' => self::range_out( $win ),
			'items' => self::relabel( Reseller_Intent_Reports::ranking( $event_type, $win['range_start'], $win['range_end'], $win['page'], $limit ), 'label', $label_key, 'count', $count_key ),
			'total' => Reseller_Intent_Reports::count_ranking( $event_type, $win['range_start'], $win['range_end'], $win['page'] ),
		);
	}

	public static function exec_pages( $input = array() ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$items = array();
		foreach ( Reseller_Intent_Reports::pages( $win['range_start'], $win['range_end'] ) as $row ) {
			$items[] = array(
				'path'        => $row['path'],
				'label'       => $row['label'],
				'searches'    => (int) $row['searches'],
				'cart_clicks' => (int) $row['carts'],
				'events'      => (int) $row['events'],
			);
		}

		return array(
			'range' => self::range_out( $win ),
			'items' => $items,
		);
	}

	public static function exec_trend( $input = array() ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$trend = Reseller_Intent_Reports::trend( $win['bounded'], $win['len'], $win['anchor_ts'], $win['page'] );

		return array(
			'range'       => self::range_out( $win ),
			'labels'      => $trend['labels'],
			'searches'    => $trend['searches'],
			'cart_clicks' => $trend['carts'],
		);
	}

	public static function exec_recent( $input = array() ) {
		$win = self::window_from_input( $input );
		if ( is_wp_error( $win ) ) {
			return $win;
		}

		$limit = self::limit_from_input( $input, 50, 200 );

		return array(
			'range' => self::range_out( $win ),
			'items' => Reseller_Intent_Reports::recent( $win['range_start'], $win['range_end'], $win['page'], $limit ),
		);
	}

	public static function exec_tld_prices() {
		return Reseller_Intent_Reports::tld_prices();
	}

	public static function exec_status() {
		return Reseller_Intent_Reports::status();
	}

	public static function exec_refresh_tld_prices() {
		$sets = (array) get_option( Reseller_Intent_TLD_Strip::SETS_OPTION, array() );

		if ( $sets ) {
			$strip = new Reseller_Intent_TLD_Strip();
			$strip->prefetch();
		}

		$last = Reseller_Intent_TLD_Strip::last_refresh_ts();

		return array(
			'refreshed_sets' => count( $sets ),
			'last_refresh'   => $last ? wp_date( 'c', $last ) : '',
		);
	}
}
