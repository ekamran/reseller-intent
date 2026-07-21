<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [rintent_phone], geo-aware support phone number.
 *
 * GoDaddy resellers get white-label support numbers per market; configure
 * them under Settings → Support numbers (label, number, target countries).
 * The shortcode renders the default number server-side, page-cache safe -
 * and a tiny script swaps in the visitor's regional number client-side
 * using the browser timezone (no IP, no external calls).
 *
 * Always renders one thing: a tel: link with the region's flag and
 * number, like a phone line in a site header. The default renders
 * server-side (page-cache safe) and a tiny script swaps flag, number
 * and href to the visitor's region; a short CSS reveal removes the
 * default-then-swap flash.
 *
 * Attributes:
 *   class   Extra CSS class(es) for the element.
 *   prefix  Optional text before the flag.               Default: ""
 *
 * Theming: .rintent-phone / .rintent-phone-flag / .rintent-phone-number
 */
final class Reseller_Intent_Phone {

	public function register() {
		add_shortcode( 'rintent_phone', array( $this, 'render' ) );
	}

	/**
	 * Built-in defaults: GoDaddy's public support numbers per country
	 * (godaddy.com/contact-us) plus a global fallback. These live in code,
	 * so plugin updates can refresh them. The moment a site owner saves
	 * their own list, their list wins and updates never touch it.
	 *
	 * @return array[] Each: ['label' => '', 'number' => '', 'countries' => ['IN', ...]], empty countries = default.
	 */
	public static function default_numbers() {
		return array(
			array(
				'label'     => 'Global Support',
				'number'    => '+1 480 366 3549',
				'countries' => array(),
			),
			array(
				'label'     => 'India',
				'number'    => '+91 40 6760 7600',
				'countries' => array( 'IN' ),
			),
			array(
				'label'     => 'United States',
				'number'    => '+1 480 366 3549',
				'countries' => array( 'US' ),
			),
			array(
				'label'     => 'Canada',
				'number'    => '+1 866 938 1119',
				'countries' => array( 'CA' ),
			),
			array(
				'label'     => 'United Kingdom',
				'number'    => '+44 20 7084 1810',
				'countries' => array( 'GB' ),
			),
			array(
				'label'     => 'Ireland',
				'number'    => '+353 1 653 5976',
				'countries' => array( 'IE' ),
			),
			array(
				'label'     => 'Australia',
				'number'    => '+61 1300 351 076',
				'countries' => array( 'AU', 'NZ' ),
			),
			array(
				'label'     => 'United Arab Emirates',
				'number'    => '800 032 0329',
				'countries' => array( 'AE' ),
			),
			array(
				'label'     => 'Argentina',
				'number'    => '+54 11 5235 3894',
				'countries' => array( 'AR' ),
			),
			array(
				'label'     => 'Austria',
				'number'    => '+43 800 300 248',
				'countries' => array( 'AT' ),
			),
			array(
				'label'     => 'Belgium',
				'number'    => '+32 78 48 03 73',
				'countries' => array( 'BE' ),
			),
			array(
				'label'     => 'Brazil',
				'number'    => '+55 4003 3329',
				'countries' => array( 'BR' ),
			),
			array(
				'label'     => 'Chile',
				'number'    => '+56 44 8909 402',
				'countries' => array( 'CL' ),
			),
			array(
				'label'     => 'Denmark',
				'number'    => '+45 78 72 57 85',
				'countries' => array( 'DK' ),
			),
			array(
				'label'     => 'France',
				'number'    => '+33 9 70 01 93 53',
				'countries' => array( 'FR' ),
			),
			array(
				'label'     => 'Germany',
				'number'    => '+49 89 21 094 807',
				'countries' => array( 'DE' ),
			),
			array(
				'label'     => 'Hong Kong',
				'number'    => '+852 3008 5887',
				'countries' => array( 'HK' ),
			),
			array(
				'label'     => 'Italy',
				'number'    => '+39 800 934 119',
				'countries' => array( 'IT' ),
			),
			array(
				'label'     => 'Mexico',
				'number'    => '+52 55 8877 3680',
				'countries' => array( 'MX' ),
			),
			array(
				'label'     => 'Netherlands',
				'number'    => '+31 9701 026 5160',
				'countries' => array( 'NL' ),
			),
			array(
				'label'     => 'Norway',
				'number'    => '+47 235 02 160',
				'countries' => array( 'NO' ),
			),
			array(
				'label'     => 'Peru',
				'number'    => '+51 1 709 7939',
				'countries' => array( 'PE' ),
			),
			array(
				'label'     => 'Poland',
				'number'    => '+48 22 292 26 69',
				'countries' => array( 'PL' ),
			),
			array(
				'label'     => 'Portugal',
				'number'    => '+351 300 609 032',
				'countries' => array( 'PT' ),
			),
			array(
				'label'     => 'Spain',
				'number'    => '+34 91 198 05 24',
				'countries' => array( 'ES' ),
			),
			array(
				'label'     => 'Sweden',
				'number'    => '+46 077 588 89 68',
				'countries' => array( 'SE' ),
			),
			array(
				'label'     => 'Switzerland',
				'number'    => '+41 44 511 1274',
				'countries' => array( 'CH' ),
			),
			array(
				'label'     => 'Taiwan',
				'number'    => '+886 02 7703 9087',
				'countries' => array( 'TW' ),
			),
			array(
				'label'     => 'Turkiye',
				'number'    => '+90 850 390 75 46',
				'countries' => array( 'TR' ),
			),
			array(
				'label'     => 'Ukraine',
				'number'    => '+380 89 324 0205',
				'countries' => array( 'UA' ),
			),
		);
	}

	/**
	 * @return bool True when the site owner saved their own list.
	 */
	public static function is_customized() {
		return is_array( Reseller_Intent_Settings::get( 'support_numbers' ) );
	}

	public static function numbers() {
		$numbers = Reseller_Intent_Settings::get( 'support_numbers' );

		return is_array( $numbers ) ? $numbers : self::default_numbers();
	}

	private function default_number( array $numbers ) {
		foreach ( $numbers as $entry ) {
			if ( empty( $entry['countries'] ) ) {
				return $entry;
			}
		}

		return ! empty( $numbers ) ? $numbers[0] : null;
	}

	/**
	 * Flag emoji for a 2-letter country code; globe for the global line.
	 */
	public static function flag_for( $country ) {
		$country = strtoupper( (string) $country );

		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return "\u{1F310}";
		}

		return mb_chr( 0x1F1E6 + ord( $country[0] ) - 65, 'UTF-8' )
			. mb_chr( 0x1F1E6 + ord( $country[1] ) - 65, 'UTF-8' );
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'class'  => '',
				'prefix' => '',
			),
			$atts,
			'rintent_phone'
		);

		$numbers = self::numbers();
		$default = $this->default_number( $numbers );

		if ( null === $default ) {
			return '';
		}

		$this->enqueue_swapper( $numbers );

		$classes = trim( 'rintent-phone ' . preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $atts['class'] ) );
		$number  = (string) $default['number'];
		$flag    = self::flag_for( empty( $default['countries'] ) ? '' : $default['countries'][0] );
		$prefix  = (string) $atts['prefix'];

		return '<a class="' . esc_attr( $classes ) . '" data-rintent-phone href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $number ) ) . '">'
			. ( '' !== $prefix ? '<span class="rintent-phone-prefix">' . esc_html( $prefix ) . '</span>' : '' )
			. '<span class="rintent-phone-flag" aria-hidden="true">' . esc_html( $flag ) . '</span>'
			. '<span class="rintent-phone-number">' . esc_html( $number ) . '</span></a>';
	}

	/**
	 * Localize the configured numbers plus a timezone→country map covering
	 * ONLY the configured countries (built natively, a few dozen entries at
	 * most) so the client can resolve its region without any lookup service.
	 */
	private function enqueue_swapper( array $numbers ) {
		if ( wp_script_is( 'reseller-intent-phone', 'enqueued' ) ) {
			return;
		}

		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

		wp_enqueue_style(
			'reseller-intent-phone',
			$base_url . 'assets/css/phone.css',
			array(),
			filemtime( $base_path . 'assets/css/phone.css' )
		);

		wp_enqueue_script(
			'reseller-intent-phone',
			$base_url . 'assets/js/phone.js',
			array(),
			filemtime( $base_path . 'assets/js/phone.js' ),
			true
		);

		$tz_map = array();

		foreach ( $numbers as $entry ) {
			foreach ( (array) ( $entry['countries'] ?? array() ) as $code ) {
				foreach ( Reseller_Intent_TZ::zones_for_country( $code ) as $zone ) {
					$tz_map[ $zone ] = $code;
				}
			}
		}

		wp_localize_script(
			'reseller-intent-phone',
			'resellerIntentPhone',
			array(
				'numbers' => array_values(
					array_map(
						static function ( $entry ) {
							return array(
								'number'    => (string) $entry['number'],
								'countries' => array_values( (array) ( $entry['countries'] ?? array() ) ),
								'flag'      => self::flag_for( empty( $entry['countries'] ) ? '' : $entry['countries'][0] ),
							);
						},
						$numbers
					)
				),
				'tzMap'   => $tz_map,
			)
		);
	}
}
