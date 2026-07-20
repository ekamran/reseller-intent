<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Timezone → country helpers shared by the tracker (country stats fallback)
 * and the [rintent_phone] swapper.
 *
 * Browsers frequently report legacy timezone aliases (Asia/Calcutta,
 * US/Eastern, Europe/Kiev…) that PHP's location lookup can't resolve, so
 * everything funnels through canonicalize(): IntlTimeZone when available,
 * otherwise a curated map of the aliases browsers actually emit.
 */
final class Reseller_Intent_TZ {

	/**
	 * Legacy alias => canonical. Covers the backward-compat IDs commonly
	 * seen from real browsers/OSes; IntlTimeZone (when present) covers the
	 * long tail.
	 */
	private static $aliases = array(
		'Asia/Calcutta'        => 'Asia/Kolkata',
		'Asia/Katmandu'        => 'Asia/Kathmandu',
		'Asia/Rangoon'         => 'Asia/Yangon',
		'Asia/Saigon'          => 'Asia/Ho_Chi_Minh',
		'Asia/Dacca'           => 'Asia/Dhaka',
		'Asia/Thimbu'          => 'Asia/Thimphu',
		'Asia/Macao'           => 'Asia/Macau',
		'Asia/Ulan_Bator'      => 'Asia/Ulaanbaatar',
		'Asia/Chongqing'       => 'Asia/Shanghai',
		'Asia/Harbin'          => 'Asia/Shanghai',
		'Asia/Kashgar'         => 'Asia/Urumqi',
		'Asia/Istanbul'        => 'Europe/Istanbul',
		'Asia/Tel_Aviv'        => 'Asia/Jerusalem',
		'Europe/Kiev'          => 'Europe/Kyiv',
		'Europe/Uzhgorod'      => 'Europe/Kyiv',
		'Europe/Zaporozhye'    => 'Europe/Kyiv',
		'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
		'America/Cordoba'      => 'America/Argentina/Cordoba',
		'America/Mendoza'      => 'America/Argentina/Mendoza',
		'America/Godthab'      => 'America/Nuuk',
		'America/Indianapolis' => 'America/Indiana/Indianapolis',
		'America/Louisville'   => 'America/Kentucky/Louisville',
		'Atlantic/Faeroe'      => 'Atlantic/Faroe',
		'Africa/Asmera'        => 'Africa/Asmara',
		'Pacific/Ponape'       => 'Pacific/Pohnpei',
		'Pacific/Truk'         => 'Pacific/Chuuk',
		'US/Eastern'           => 'America/New_York',
		'US/Central'           => 'America/Chicago',
		'US/Mountain'          => 'America/Denver',
		'US/Pacific'           => 'America/Los_Angeles',
		'US/Arizona'           => 'America/Phoenix',
		'US/Alaska'            => 'America/Anchorage',
		'US/Hawaii'            => 'Pacific/Honolulu',
		'Canada/Eastern'       => 'America/Toronto',
		'Canada/Central'       => 'America/Winnipeg',
		'Canada/Mountain'      => 'America/Edmonton',
		'Canada/Pacific'       => 'America/Vancouver',
		'Canada/Atlantic'      => 'America/Halifax',
		'Australia/ACT'        => 'Australia/Sydney',
		'Australia/NSW'        => 'Australia/Sydney',
		'Australia/Queensland' => 'Australia/Brisbane',
		'Australia/South'      => 'Australia/Adelaide',
		'Australia/Tasmania'   => 'Australia/Hobart',
		'Australia/Victoria'   => 'Australia/Melbourne',
		'Australia/West'       => 'Australia/Perth',
		'Mexico/General'       => 'America/Mexico_City',
		'Brazil/East'          => 'America/Sao_Paulo',
	);

	/**
	 * @param string $timezone Raw identifier from a browser.
	 * @return string Canonical identifier, or '' if unresolvable.
	 */
	public static function canonicalize( $timezone ) {
		$timezone = (string) $timezone;

		if ( '' === $timezone || strlen( $timezone ) > 64 || ! preg_match( '#^[A-Za-z0-9_+\-/]+$#', $timezone ) ) {
			return '';
		}

		$canonical_list = timezone_identifiers_list();

		if ( in_array( $timezone, $canonical_list, true ) ) {
			return $timezone;
		}

		if ( isset( self::$aliases[ $timezone ] ) ) {
			return self::$aliases[ $timezone ];
		}

		if ( class_exists( 'IntlTimeZone' ) ) {
			$canonical = IntlTimeZone::getCanonicalID( $timezone );

			if ( is_string( $canonical ) && in_array( $canonical, $canonical_list, true ) ) {
				return $canonical;
			}
		}

		return '';
	}

	/**
	 * @return string 2-letter country code or ''.
	 */
	public static function country_for( $timezone ) {
		$canonical = self::canonicalize( $timezone );

		if ( '' === $canonical ) {
			return '';
		}

		$location = timezone_location_get( new DateTimeZone( $canonical ) );
		$code     = isset( $location['country_code'] ) ? strtoupper( (string) $location['country_code'] ) : '';

		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	/**
	 * Canonical zones for a country plus every alias that resolves into it —
	 * so client-side maps match whatever identifier the browser reports.
	 *
	 * @return string[] Timezone identifiers.
	 */
	public static function zones_for_country( $code ) {
		$zones = (array) timezone_identifiers_list( DateTimeZone::PER_COUNTRY, strtoupper( (string) $code ) );

		foreach ( self::$aliases as $alias => $canonical ) {
			if ( in_array( $canonical, $zones, true ) ) {
				$zones[] = $alias;
			}
		}

		return $zones;
	}
}
