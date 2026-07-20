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
 * Attributes:
 *   format  "link" (tel: anchor, default) or "text"
 *   class   Extra CSS class(es) for the element.
 *   prefix  Text before the number, e.g. "Call ".        Default: ""
 *
 * Theming: .rintent-phone class; style it like any inline element.
 */
final class Reseller_Intent_Phone {

	public function register() {
		add_shortcode( 'rintent_phone', array( $this, 'render' ) );
	}

	/**
	 * @return array[] Each: ['label' => '', 'number' => '', 'countries' => ['IN', ...]], empty countries = default.
	 */
	public static function numbers() {
		$numbers = Reseller_Intent_Settings::get( 'support_numbers' );

		return is_array( $numbers ) ? $numbers : array();
	}

	private function default_number( array $numbers ) {
		foreach ( $numbers as $entry ) {
			if ( empty( $entry['countries'] ) ) {
				return $entry;
			}
		}

		return ! empty( $numbers ) ? $numbers[0] : null;
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'format' => 'link',
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
		$prefix  = (string) $atts['prefix'];

		if ( 'text' === $atts['format'] ) {
			return '<span class="' . esc_attr( $classes ) . '" data-rintent-phone>'
				. esc_html( $prefix ) . '<span class="rintent-phone-number">' . esc_html( $number ) . '</span></span>';
		}

		return '<a class="' . esc_attr( $classes ) . '" data-rintent-phone href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $number ) ) . '">'
			. esc_html( $prefix ) . '<span class="rintent-phone-number">' . esc_html( $number ) . '</span></a>';
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
