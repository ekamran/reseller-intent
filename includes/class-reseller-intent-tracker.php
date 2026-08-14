<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reseller_Intent_Tracker {
	/**
	 * Anonymous frontend tracking endpoint.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing -- deliberately
	 * nonce-free: nonces baked into cached pages outlive their lifetime and
	 * silently kill tracking. Guarded instead by a same-origin check and a
	 * rate limiter; every field below is sanitized individually.
	 */
	public function handle_track_event() {
		global $wpdb;

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== strtoupper( $request_method ) ) {
			wp_send_json_error( array( 'message' => 'Invalid request method' ), 405 );
		}

		if ( $this->is_bot_request() ) {
			wp_send_json_success(
				array(
					'ignored' => true,
					'reason'  => 'bot',
				)
			);
		}

		/*
		 * No nonce on purpose. Nonces live in page markup, so any page cache
		 * older than the nonce lifetime (24h) would silently kill tracking
		 * for every visitor. This endpoint is anonymous and non-privileged;
		 * a same-origin check plus the rate limiter is the right guard.
		 */
		if ( ! $this->is_same_origin() ) {
			wp_send_json_error( array( 'message' => 'Cross-origin request rejected' ), 403 );
		}

		$event_type = isset( $_POST['event_type'] ) ? sanitize_key( wp_unslash( $_POST['event_type'] ) ) : '';
		if ( ! in_array( $event_type, array( 'domain_search', 'continue_to_cart', 'domain_select', 'search_result', 'domain_transfer', 'product_add', 'cart_view', 'login_click', 'phone_click' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid event type' ), 400 );
		}

		$domain_query  = isset( $_POST['domain_query'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_query'] ) ) : '';
		$domain_query  = $this->normalize_domain_query( $domain_query );
		$related_query = isset( $_POST['related_query'] ) ? sanitize_text_field( wp_unslash( $_POST['related_query'] ) ) : '';
		$related_query = $this->normalize_domain_query( $related_query );
		$device        = isset( $_POST['device'] ) ? sanitize_key( wp_unslash( $_POST['device'] ) ) : '';
		$device        = in_array( $device, array( 'mobile', 'tablet', 'desktop' ), true ) ? $device : '';
		$page_url      = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
		$items_count   = isset( $_POST['items_count'] ) ? absint( $_POST['items_count'] ) : 0;
		$items_json    = '';
		$page_url      = (string) substr( (string) $page_url, 0, 2000 );
		$items_count   = max( 0, min( 100, $items_count ) );

		if ( 'search_result' === $event_type ) {
			$this->record_search_outcome( $domain_query );
			return;
		}

		if ( isset( $_POST['items_json'] ) ) {
			$raw_items = wp_unslash( $_POST['items_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; decoded below and rebuilt field by field through normalize_domain_query(), never stored raw.
			$decoded   = json_decode( $raw_items, true );
			$clean     = array();

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $item ) {
					if ( count( $clean ) >= 100 ) {
						break;
					}

					$domain = '';
					if ( is_array( $item ) && isset( $item['domain'] ) && is_string( $item['domain'] ) ) {
						$domain = $item['domain'];
					} elseif ( is_string( $item ) ) {
						$domain = $item;
					}

					$domain = $this->normalize_domain_query( $domain );

					if ( '' !== $domain ) {
						$clean[] = array( 'domain' => $domain );
					}
				}
			}

			if ( array() !== $clean ) {
				$items_json = wp_json_encode( $clean );
				$items_json = is_string( $items_json ) ? substr( $items_json, 0, 10000 ) : '';
			}
		}
		if ( $items_count < 1 && '' !== $items_json ) {
			$items_count = $this->derive_items_count_from_json( $items_json );
		}
		if ( 'continue_to_cart' === $event_type && $items_count < 1 ) {
			$items_count = 1;
		}
		if ( in_array( $event_type, array( 'domain_search', 'domain_select', 'domain_transfer', 'product_add' ), true ) && '' === $domain_query ) {
			wp_send_json_success( array( 'ignored' => true ) );
		}
		if ( $this->is_rate_limited( $event_type, $domain_query, $items_count ) ) {
			wp_send_json_success(
				array(
					'ignored' => true,
					'reason'  => 'rate_limited',
				)
			);
		}

		if ( $this->is_blocklisted( $domain_query ) ) {
			wp_send_json_success(
				array(
					'ignored' => true,
					'reason'  => 'blocklisted',
				)
			);
		}

		$event_data = array(
			'event_type'    => $event_type,
			'domain_query'  => $domain_query,
			'related_query' => $related_query,
			'event_count'   => 1,
			'items_count'   => $items_count,
			'items_json'    => $items_json,
			'device'        => $device,
			'country'       => $this->get_country_code(),
			'page_url'      => $page_url,
			'created_at'    => current_time( 'mysql' ),
		);

		/**
		 * Filter whether this event should be recorded at all. Consent
		 * plugins can hook here and return false until consent is given.
		 *
		 * @param bool  $should_track Default true.
		 * @param array $event_data   The normalized event about to be saved.
		 */
		if ( ! apply_filters( 'rintent_should_track', true, $event_data ) ) {
			wp_send_json_success(
				array(
					'ignored' => true,
					'reason'  => 'filtered',
				)
			);
		}

		/**
		 * Filter the event row before it is written.
		 *
		 * @param array $event_data Column => value map for the events table.
		 */
		$event_data = (array) apply_filters( 'rintent_event_data', $event_data );

		/*
		 * No format array. wpdb applies formats positionally, so a
		 * rintent_event_data filter that adds or reorders a key would shift
		 * every column after it. Letting wpdb default to %s is drift proof
		 * and MySQL casts the numeric columns itself.
		 */
		$inserted = $wpdb->insert( Reseller_Intent_DB::table_name(), $event_data );

		if ( false === $inserted ) {
			wp_send_json_error( array( 'message' => 'Failed to save event' ), 500 );
		}

		/*
		 * The admin bar count reads from a cache so pageviews cost nothing,
		 * but a number about "right now" must not sit on yesterday's cache:
		 * every new event drops it, so the next pageview rebuilds and the
		 * bar is never more than one event behind the table.
		 */
		delete_transient( Reseller_Intent_Adminbar::TRANSIENT );

		wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
	}

	/**
	 * Attach an available/taken outcome to the most recent matching search
	 * event instead of inserting a new row.
	 */
	private function record_search_outcome( $domain_query ) {
		global $wpdb;

		if ( '' === $domain_query ) {
			wp_send_json_success( array( 'ignored' => true ) );
		}

		$is_available = isset( $_POST['is_available'] ) ? (int) ( absint( $_POST['is_available'] ) > 0 ) : null;
		if ( null === $is_available ) {
			wp_send_json_success( array( 'ignored' => true ) );
		}

		$table_name = Reseller_Intent_DB::table_name();
		$cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name}
				SET is_available = %d
				WHERE event_type = 'domain_search' AND domain_query = %s AND is_available IS NULL AND created_at >= %s
				ORDER BY id DESC
				LIMIT 1",
				$is_available,
				$domain_query,
				$cutoff
			)
		);

		wp_send_json_success( array( 'updated' => (int) $updated ) );
	}

	private function derive_items_count_from_json( $items_json ) {
		$decoded = json_decode( $items_json, true );
		if ( ! is_array( $decoded ) ) {
			return 0;
		}

		$candidate_arrays = array( $decoded );
		foreach ( array( 'items', 'domains', 'selected_domains', 'selectedDomains', 'domain_list' ) as $candidate_key ) {
			if ( isset( $decoded[ $candidate_key ] ) && is_array( $decoded[ $candidate_key ] ) ) {
				$candidate_arrays[] = $decoded[ $candidate_key ];
			}
		}
		foreach ( $candidate_arrays as $candidate_array ) {
			if ( $this->is_list_array( $candidate_array ) ) {
				$filtered = array_filter(
					$candidate_array,
					static function ( $value ) {
						if ( is_array( $value ) ) {
							return ! empty( $value );
						}
						return '' !== trim( (string) $value );
					}
				);
				return count( $filtered );
			}
		}

		if ( isset( $decoded['count'] ) && is_numeric( $decoded['count'] ) ) {
			return max( 0, (int) $decoded['count'] );
		}

		return max( 0, count( $decoded ) );
	}

	/**
	 * Internationalised names (münchen.de, тест.рф, भारत.in) reach us as
	 * unicode. The ASCII filter below would quietly drop the accents and
	 * store a domain nobody searched for, so convert to punycode first and
	 * let the existing allowlist check the ASCII form.
	 *
	 * Pure ASCII input, which is nearly every search, returns untouched.
	 */
	private function to_punycode( $host ) {
		if ( '' === $host || ! preg_match( '/[^\x20-\x7E]/', $host ) ) {
			return $host;
		}

		if ( ! class_exists( '\WpOrg\Requests\IdnaEncoder' ) ) {
			return $host;
		}

		try {
			$encoded = \WpOrg\Requests\IdnaEncoder::encode( $host );
		} catch ( \Throwable $e ) {
			// Not encodable (over-long label, ACE prefix). Reject it rather
			// than let the ASCII filter below strip the accents and store a
			// domain nobody searched for.
			return '';
		}

		return is_string( $encoded ) ? $encoded : $host;
	}

	private function normalize_domain_query( $domain_query ) {
		$domain_query = trim( (string) $domain_query );
		$normalized   = function_exists( 'mb_strtolower' )
			? mb_strtolower( $domain_query, 'UTF-8' )
			: strtolower( $domain_query );

		if ( '' === $normalized ) {
			return '';
		}

		$normalized = preg_replace( '/^https?:\/\//i', '', $normalized );
		$normalized = (string) preg_replace( '/^www\./i', '', $normalized );
		$normalized = strtok( $normalized, '/?#' );
		$normalized = trim( (string) $normalized, ". \t\n\r\0\x0B" );
		$normalized = preg_replace( '/\s+/', '', (string) $normalized );
		$normalized = $this->to_punycode( (string) $normalized );
		$normalized = preg_replace( '/[^a-z0-9\.-]/', '', (string) $normalized );
		$normalized = preg_replace( '/\.{2,}/', '.', (string) $normalized );
		$normalized = trim( (string) $normalized, '.-' );

		if ( ! is_string( $normalized ) || '' === $normalized ) {
			return '';
		}
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]{0,189}[a-z0-9])?$/', $normalized ) ) {
			return '';
		}

		return substr( $normalized, 0, 191 );
	}

	/**
	 * Country, privacy-safe, in priority order, no IP is ever read:
	 *
	 * 1. Edge/proxy geo headers (Cloudflare, Vercel, generic), exact.
	 * 2. Server geo variables some hosts set (mod_geoip / LiteSpeed).
	 * 3. The visitor's browser timezone (sent by tracker.js), mapped to a
	 *    country with PHP's native timezone_location_get(), works on any
	 *    plain hosting with no CDN and no geo database. Approximate but
	 *    right for the vast majority of visitors.
	 *
	 * Empty when nothing usable is present.
	 */
	private function get_country_code() {
		$headers = array(
			'HTTP_CF_IPCOUNTRY',
			'HTTP_X_VERCEL_IP_COUNTRY',
			'HTTP_X_COUNTRY_CODE',
			'GEOIP_COUNTRY_CODE',
			'HTTP_X_GEOIP_COUNTRY',
		);

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );

			if ( preg_match( '/^[A-Z]{2}$/', $code ) && ! in_array( $code, array( 'XX', 'T1' ), true ) ) {
				return $code;
			}
		}

		return $this->country_from_timezone( isset( $_POST['tz'] ) ? sanitize_text_field( wp_unslash( $_POST['tz'] ) ) : '' );
	}

	private function country_from_timezone( $timezone ) {
		return Reseller_Intent_TZ::country_for( $timezone );
	}

	/**
	 * Owner-defined ignore list (Settings): one pattern per line, matched
	 * against the normalized domain query. `*` wildcards supported.
	 */
	private function is_blocklisted( $domain_query ) {
		if ( '' === $domain_query ) {
			return false;
		}

		$patterns = Reseller_Intent_Settings::get( 'blocklist' );

		if ( ! is_array( $patterns ) || empty( $patterns ) ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( trim( (string) $pattern ) );

			if ( '' === $pattern ) {
				continue;
			}

			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';

			if ( preg_match( $regex, $domain_query ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Server-side bot filter, always on. The widget is JS-driven so most
	 * crawlers never reach here, but headless browsers do. The
	 * rintent_track_bots filter exists for the rare debugging session
	 * that needs bot traffic recorded.
	 */
	private function is_bot_request() {
		if ( apply_filters( 'rintent_track_bots', false ) ) {
			return false;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === trim( $user_agent ) ) {
			return true;
		}

		return (bool) preg_match(
			'/bot|crawl|spider|slurp|headless|lighthouse|pingdom|gtmetrix|pagespeed|prerender|scrapy|python-requests|curl\/|wget\//i',
			$user_agent
		);
	}

	/**
	 * Reject only on a clear cross-origin signal. Origin (sent on all
	 * modern POSTs) is checked first, then Referer. Both absent = allow,
	 * privacy tools strip these and that should not break tracking.
	 */
	private function is_same_origin() {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$host = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), PHP_URL_HOST );

			return is_string( $host ) && 0 === strcasecmp( $host, (string) $home_host );
		}

		return true;
	}

	/**
	 * One transient per client IP, so the key space stays bounded no
	 * matter what payloads are thrown at the endpoint. The value carries
	 * a rolling event count (burst cap) and the hash of the previous
	 * payload (double-fire dedupe), replacing the old per-payload keys.
	 */
	private function is_rate_limited( $event_type, $domain_query, $items_count ) {
		$key   = 'rintent_rl_' . md5( (string) $this->get_client_ip() );
		$hash  = md5( $event_type . '|' . $domain_query . '|' . (int) $items_count );
		$now   = time();
		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : array(
			'n'    => 0,
			'last' => '',
			't'    => $now,
		);

		$dedupe_window = 'continue_to_cart' === $event_type ? 2 : 3;

		if ( $state['last'] === $hash && ( $now - (int) $state['t'] ) < $dedupe_window ) {
			return true;
		}

		if ( (int) $state['n'] >= 12 ) {
			return true;
		}

		set_transient(
			$key,
			array(
				'n'    => (int) $state['n'] + 1,
				'last' => $hash,
				't'    => $now,
			),
			5
		);

		return false;
	}

	private function get_client_ip() {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! is_string( $remote_addr ) || '' === $remote_addr ) {
			return '';
		}

		$remote_addr = trim( $remote_addr );
		if ( false === filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $remote_addr;
	}

	private function is_list_array( $candidate ) {
		return is_array( $candidate ) && array_values( $candidate ) === $candidate;
	}
}
