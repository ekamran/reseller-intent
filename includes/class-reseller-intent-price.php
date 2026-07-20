<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [rintent_price] — live "starting at" price pulled from Reseller Store
 * product meta (rstore_salePrice / rstore_listPrice), which GoDaddy's own
 * catalog sync keeps fresh. Pass every plan of a family and the cheapest one
 * is shown, so the number stays correct even if GoDaddy reprices a different
 * plan lowest.
 *
 * Attributes:
 *   ids       Comma-separated reseller_product post IDs.       Required.
 *   fallback  Text to print if no product yields a price.      Default: ""
 *
 * Usage: Starting at [rintent_price ids="116,118,119" fallback="$3.99"]/mo
 */
final class Reseller_Intent_Price {

	public function register() {
		add_shortcode( 'rintent_price', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'ids'      => '',
				'fallback' => '',
			),
			$atts,
			'rintent_price'
		);

		$lowest       = null;
		$lowest_label = '';

		foreach ( wp_parse_id_list( $atts['ids'] ) as $post_id ) {
			if ( 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}

			$label = get_post_meta( $post_id, 'rstore_salePrice', true );
			if ( '' === trim( (string) $label ) ) {
				$label = get_post_meta( $post_id, 'rstore_listPrice', true );
			}

			$value = $this->to_number( $label );
			if ( null === $value ) {
				continue;
			}

			if ( null === $lowest || $value < $lowest ) {
				$lowest       = $value;
				$lowest_label = trim( $label );
			}
		}

		if ( null === $lowest ) {
			$lowest_label = $atts['fallback'];
		}

		return esc_html( $lowest_label );
	}

	/**
	 * "$1,234.99" → 1234.99; returns null for anything non-numeric.
	 */
	private function to_number( $label ) {
		$clean = preg_replace( '/[^0-9.]/', '', (string) $label );

		return is_numeric( $clean ) ? (float) $clean : null;
	}
}
