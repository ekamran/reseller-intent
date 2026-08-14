<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Today's pulse on the admin bar: WordPress's own icon-and-count shape (the
 * comments item's), counting every event tracked today, with a dropdown that
 * decomposes that exact number one panel per line.
 *
 * Everything renders through core's ab-icon / ab-label classes and a stock
 * submenu, so the plugin ships no bar styling of its own: every admin color
 * scheme, RTL and future core restyle just works. The count is anchored to
 * the site's timezone and keeps one definition from midnight to midnight.
 *
 * The bar renders on every admin page and, for logged-in users, on the
 * storefront itself, so the numbers come from one ten-minute transient: a
 * warm cache costs the pageview zero queries.
 */
final class Reseller_Intent_Adminbar {

	const TRANSIENT = 'rintent_pulse';

	public function register() {
		// After core's own items (comments 60, + New 70).
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 80 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_style' ) );
	}

	/**
	 * Same gate as the dashboard, plus the Settings toggle. Checked at hook
	 * time, not registration, so flipping the toggle needs no reload logic.
	 */
	private function allowed() {
		return is_admin_bar_showing()
			&& (bool) Reseller_Intent_Settings::get( 'admin_bar' )
			&& current_user_can( Reseller_Intent_Admin::capability() );
	}

	public function enqueue_style() {
		if ( ! $this->allowed() ) {
			return;
		}

		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

		wp_enqueue_style(
			'rintent-adminbar',
			$base_url . 'assets/css/adminbar.css',
			array(),
			filemtime( $base_path . 'assets/css/adminbar.css' )
		);
	}

	/**
	 * @param WP_Admin_Bar $bar The admin bar, by reference.
	 */
	public function add_nodes( $bar ) {
		if ( ! $this->allowed() ) {
			return;
		}

		$pulse     = $this->pulse();
		$dashboard = admin_url( 'admin.php?page=' . Reseller_Intent_Admin::PAGE_SLUG );

		$bar->add_node(
			array(
				'id'    => 'rintent-pulse',
				'title' => '<span class="ab-icon dashicons-before dashicons-chart-bar" aria-hidden="true"></span><span class="ab-label">'
					. esc_html( number_format_i18n( $pulse['total'] ) ) . '</span>',
				'href'  => $dashboard,
				'meta'  => array(
					'title' => sprintf(
						/* translators: %s: number of tracked events */
						__( 'Reseller Intent: %s events tracked today', 'reseller-intent' ),
						number_format_i18n( $pulse['total'] )
					),
				),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'rintent-pulse-today',
				'parent' => 'rintent-pulse',
				'title'  => esc_html(
					sprintf(
						/* translators: %s: number of tracked events */
						__( 'Today: %s tracked', 'reseller-intent' ),
						number_format_i18n( $pulse['total'] )
					)
				),
			)
		);

		foreach ( $this->lines( $pulse['by_type'] ) as $key => $line ) {
			$bar->add_node(
				array(
					'id'     => 'rintent-pulse-' . $key,
					'parent' => 'rintent-pulse',
					'title'  => esc_html( $line ),
				)
			);
		}

		/*
		 * Core's own pattern for a menu's closing section (the my-account
		 * menu uses it): a secondary group, drawn darker, no divider hacks.
		 */
		$bar->add_group(
			array(
				'id'     => 'rintent-pulse-foot',
				'parent' => 'rintent-pulse',
				'meta'   => array( 'class' => 'ab-sub-secondary' ),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'rintent-pulse-open',
				'parent' => 'rintent-pulse-foot',
				'title'  => esc_html__( 'Reseller Intent', 'reseller-intent' ),
				'href'   => $dashboard,
			)
		);
	}

	/**
	 * One line per panel, in the funnel's order, zero lines dropped. The
	 * displayed lines always sum to the bubble, because both come from the
	 * same per-type counts: anyone who adds the menu up lands on the bar.
	 *
	 * @param array<string,int> $by_type Event type => today's count.
	 * @return array<string,string> Node id suffix => line.
	 */
	private function lines( array $by_type ) {
		$outbound = ( $by_type['cart_view'] ?? 0 ) + ( $by_type['login_click'] ?? 0 ) + ( $by_type['phone_click'] ?? 0 );

		$defs = array(
			/* translators: %s: a count. */
			'searches'  => array( $by_type['domain_search'] ?? 0, _n_noop( '%s domain search', '%s domain searches', 'reseller-intent' ) ),
			/* translators: %s: a count. */
			'picks'     => array( $by_type['domain_select'] ?? 0, _n_noop( '%s picked from results', '%s picked from results', 'reseller-intent' ) ),
			/* translators: %s: a count. */
			'transfers' => array( $by_type['domain_transfer'] ?? 0, _n_noop( '%s transfer search', '%s transfer searches', 'reseller-intent' ) ),
			/* translators: %s: a count. */
			'carts'     => array( $by_type['continue_to_cart'] ?? 0, _n_noop( '%s cart click', '%s cart clicks', 'reseller-intent' ) ),
			/* translators: %s: a count. */
			'products'  => array( $by_type['product_add'] ?? 0, _n_noop( '%s product added', '%s products added', 'reseller-intent' ) ),
			/* translators: %s: a count. */
			'outbound'  => array( $outbound, _n_noop( '%s outbound click', '%s outbound clicks', 'reseller-intent' ) ),
		);

		$lines = array();

		foreach ( $defs as $key => $def ) {
			if ( $def[0] < 1 ) {
				continue;
			}

			$lines[ $key ] = sprintf( translate_nooped_plural( $def[1], $def[0], 'reseller-intent' ), number_format_i18n( $def[0] ) );
		}

		return $lines;
	}

	/**
	 * Today's per-type counts, cached ten minutes
	 * and revalidated on the site's own midnight so "today" never bleeds
	 * across days. One indexed query on a cache miss, zero when warm.
	 *
	 * @return array{total:int,by_type:array<string,int>,day:string}
	 */
	private function pulse() {
		$today  = wp_date( 'Y-m-d' );
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['day'], $cached['by_type'] ) && $cached['day'] === $today ) {
			return $cached;
		}

		global $wpdb;

		$table_name  = Reseller_Intent_DB::table_name();
		$today_start = $today . ' 00:00:00';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is the fixed prefixed table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS n
				FROM {$table_name}
				WHERE created_at >= %s
				GROUP BY event_type",
				$today_start
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_type = array();
		$total   = 0;

		foreach ( (array) $rows as $row ) {
			$by_type[ (string) $row->event_type ] = (int) $row->n;
			$total                               += (int) $row->n;
		}

		$pulse = array(
			'total'   => $total,
			'by_type' => $by_type,
			'day'     => $today,
		);

		set_transient( self::TRANSIENT, $pulse, 10 * MINUTE_IN_SECONDS );

		return $pulse;
	}
}
