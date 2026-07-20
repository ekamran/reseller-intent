<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [rintent_tld_strip], TLD price pills fed by the same GoDaddy storefront
 * API the domain search widget uses, so displayed prices always match
 * checkout.
 *
 * Attributes:
 *   tlds       Comma-separated TLD list.            Default: ".com,.in,.org,.net,.io"
 *   more_label Text for the trailing "more" pill.   Default: "More TLDs"
 *   more_url   Link for the trailing pill ("" hides it). Default: ""
 *   theme      "light" or "dark".                   Default: "light"
 *
 * Prices are cached in a 12h transient and kept warm by an 11h cron, so a
 * frontend render never blocks on the API. If the API fails for a TLD, its
 * pill renders without a price rather than showing a stale or wrong number.
 *
 * Privacy note (disclosed in readme): the server-side price lookup calls
 * secureserver.net with a static probe name, no visitor data is sent.
 */
final class Reseller_Intent_TLD_Strip {

	const TRANSIENT_KEY = 'rintent_tld_prices';
	const CACHE_TTL     = 12 * HOUR_IN_SECONDS;
	const PROBE_NAME    = 'rintent-tld-pricecheck-77341';
	const SETS_OPTION   = 'rintent_tld_strip_sets';
	const LAST_GOOD     = 'rintent_tld_last_good';
	const CRON_HOOK     = 'rintent_tld_prefetch';

	public function register() {
		add_shortcode( 'rintent_tld_strip', array( $this, 'render' ) );

		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( self::CRON_HOOK, array( $this, 'prefetch' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );

		// GD Reseller Store product import/sync touches reseller_product
		// posts; refresh our price cache shortly after (debounced, one
		// single event no matter how many products the import saves).
		add_action( 'save_post_reseller_product', array( $this, 'schedule_refresh' ) );

		add_action( 'admin_post_rintent_refresh_tld', array( $this, 'handle_manual_refresh' ) );
	}

	/**
	 * Queue a one-off prefetch a couple of minutes out. Uses a cron arg so
	 * the guard is independent of the recurring 11h event.
	 */
	public function schedule_refresh() {
		if ( empty( get_option( self::SETS_OPTION, array() ) ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( 'refresh' ) ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CRON_HOOK, array( 'refresh' ) );
		}
	}

	/**
	 * Settings → Tools: fetch fresh prices right now.
	 */
	public function handle_manual_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'reseller-intent' ) );
		}

		check_admin_referer( 'rintent_refresh_tld' );

		$sets   = (array) get_option( self::SETS_OPTION, array() );
		$notice = 'tld_refresh_none';

		if ( ! empty( $sets ) ) {
			$this->prefetch();
			$notice = 'tld_refreshed';
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'reseller-intent-shortcodes', 'rintent_notice' => $notice ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function add_cron_interval( $schedules ) {
		if ( ! isset( $schedules['rintent_11h'] ) ) {
			$schedules['rintent_11h'] = array(
				'interval' => 11 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 11 hours (Reseller Intent)', 'reseller-intent' ),
			);
		}

		return $schedules;
	}

	public function maybe_schedule() {
		// Only run the prefetch cron once the shortcode has actually been used.
		if ( empty( get_option( self::SETS_OPTION, array() ) ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'rintent_11h', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( 'refresh' ) );
	}

	/**
	 * Cron: re-fetch every TLD set the shortcode has rendered and refresh its
	 * transient before the 12h TTL lapses. A failed sweep never clobbers a
	 * good cache, the frontend fallback in get_prices() still covers that.
	 */
	public function prefetch() {
		foreach ( (array) get_option( self::SETS_OPTION, array() ) as $tlds ) {
			$tlds = array_filter( array_map( array( $this, 'sanitize_tld' ), (array) $tlds ) );

			if ( empty( $tlds ) ) {
				continue;
			}

			$prices = $this->fetch_set( $tlds );

			if ( array_filter( $prices ) ) {
				set_transient( $this->cache_key( $tlds ), $prices, self::CACHE_TTL );
				$this->remember_last_good( $tlds, $prices );
			}
		}
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'tlds'       => '.com,.in,.org,.net,.io',
				'more_label' => __( 'More TLDs', 'reseller-intent' ),
				'more_url'   => '',
				'theme'      => 'light',
			),
			$atts,
			'rintent_tld_strip'
		);

		$tlds = array_filter( array_map( array( $this, 'sanitize_tld' ), explode( ',', $atts['tlds'] ) ) );

		if ( empty( $tlds ) ) {
			return '';
		}

		$this->enqueue_style();

		$prices = $this->get_prices( $tlds );
		$theme  = ( 'dark' === $atts['theme'] ) ? 'dark' : 'light';

		$html = '<div class="rintent-tld-strip rintent-tld-strip--' . esc_attr( $theme ) . '">';

		foreach ( $tlds as $tld ) {
			$html .= '<span class="rintent-tld"><b>' . esc_html( $tld ) . '</b>';
			if ( ! empty( $prices[ $tld ] ) ) {
				$html .= '<i>' . esc_html( $prices[ $tld ] ) . '</i>';
			}
			$html .= '</span>';
		}

		if ( '' !== $atts['more_url'] && '' !== $atts['more_label'] ) {
			$html .= '<a class="rintent-tld rintent-tld--more" href="' . esc_url( $atts['more_url'] ) . '"><b>'
				. esc_html( $atts['more_label'] ) . '</b></a>';
		}

		return $html . '</div>';
	}

	private function enqueue_style() {
		if ( wp_style_is( 'reseller-intent-tld-strip', 'enqueued' ) ) {
			return;
		}

		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

		wp_enqueue_style(
			'reseller-intent-tld-strip',
			$base_url . 'assets/css/tld-strip.css',
			array(),
			filemtime( $base_path . 'assets/css/tld-strip.css' )
		);

		wp_add_inline_style(
			'reseller-intent-tld-strip',
			sprintf(
				'body{--rintent-accent:%s;}',
				(string) Reseller_Intent_Settings::get( 'accent_color' )
			)
		);
	}

	/**
	 * @param string[] $tlds Sanitized TLDs, leading dot included.
	 * @return array<string,string|null> TLD => "$13.99" or null on lookup failure.
	 */
	private function get_prices( array $tlds ) {
		$this->remember_set( $tlds );

		$cached = get_transient( $this->cache_key( $tlds ) );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		/*
		 * Transient gone (expired, or an object-cache flush wiped it).
		 * Never make the visitor wait on N remote calls: serve the last
		 * known good prices and let a one-off cron refresh in background.
		 * Only the very first render of a set ever fetches inline.
		 */
		$last_good = (array) get_option( self::LAST_GOOD, array() );
		$key       = md5( implode( ',', $tlds ) );

		if ( isset( $last_good[ $key ] ) && is_array( $last_good[ $key ] ) ) {
			set_transient( $this->cache_key( $tlds ), $last_good[ $key ], 15 * MINUTE_IN_SECONDS );
			$this->schedule_refresh();

			return $last_good[ $key ];
		}

		$prices = $this->fetch_set( $tlds );

		// Cache short on total failure so one bad window doesn't stick for 12h.
		$got_any = (bool) array_filter( $prices );
		set_transient( $this->cache_key( $tlds ), $prices, $got_any ? self::CACHE_TTL : 15 * MINUTE_IN_SECONDS );

		if ( $got_any ) {
			$this->remember_last_good( $tlds, $prices );
		}

		return $prices;
	}

	/**
	 * Keep the newest successful fetch per set in a non-autoloaded option,
	 * survives transient wipes (object-cache flushes) with zero page cost.
	 */
	private function remember_last_good( array $tlds, array $prices ) {
		$last_good = (array) get_option( self::LAST_GOOD, array() );

		$last_good[ md5( implode( ',', $tlds ) ) ] = $prices;

		update_option( self::LAST_GOOD, $last_good, false );
	}

	/**
	 * @param string[] $tlds Sanitized TLDs.
	 * @return array<string,string|null>
	 */
	private function fetch_set( array $tlds ) {
		$pl_id  = (int) get_option( 'rstore_pl_id' );
		$prices = array();

		foreach ( $tlds as $tld ) {
			$prices[ $tld ] = $pl_id ? $this->fetch_price( $pl_id, $tld ) : null;
		}

		return $prices;
	}

	private function cache_key( array $tlds ) {
		return self::TRANSIENT_KEY . '_' . md5( implode( ',', $tlds ) );
	}

	/**
	 * Record the rendered TLD set so the prefetch cron knows what to warm.
	 * Writes only when the set is new/changed; renders are rare anyway
	 * (page caches absorb most traffic).
	 */
	private function remember_set( array $tlds ) {
		$sets = (array) get_option( self::SETS_OPTION, array() );
		$key  = md5( implode( ',', $tlds ) );

		if ( ! isset( $sets[ $key ] ) || $sets[ $key ] !== array_values( $tlds ) ) {
			$sets[ $key ] = array_values( $tlds );
			update_option( self::SETS_OPTION, $sets, false );
		}
	}

	private function fetch_price( $pl_id, $tld ) {
		$url = sprintf(
			'https://www.secureserver.net/api/v1/domains/%d?q=%s',
			$pl_id,
			rawurlencode( self::PROBE_NAME . $tld )
		);

		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$exact = $body['exactMatchDomain'] ?? array();

		if ( ! empty( $exact['available'] ) && ! empty( $exact['listPrice'] ) ) {
			return (string) $exact['listPrice'];
		}

		// Probe name unexpectedly taken: fall back to a suggestion on the same TLD.
		foreach ( (array) ( $body['suggestedDomains'] ?? array() ) as $suggestion ) {
			if ( ! empty( $suggestion['listPrice'] )
				&& str_ends_with( strtolower( $suggestion['domain'] ?? '' ), strtolower( $tld ) ) ) {
				return (string) $suggestion['listPrice'];
			}
		}

		return null;
	}

	private function sanitize_tld( $tld ) {
		$tld = strtolower( trim( (string) $tld ) );

		if ( '' === $tld ) {
			return '';
		}

		if ( '.' !== $tld[0] ) {
			$tld = '.' . $tld;
		}

		return preg_match( '/^\.[a-z0-9.-]{1,20}$/', $tld ) ? $tld : '';
	}
}
