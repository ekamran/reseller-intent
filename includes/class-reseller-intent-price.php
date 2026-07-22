<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [rintent_price], live price pulled from Reseller Store product meta
 * (rstore_salePrice / rstore_listPrice), which GoDaddy's own catalog sync
 * keeps fresh. Pass every plan of a family so the number stays correct
 * even if GoDaddy reprices a different plan lowest.
 *
 * Attributes:
 *   ids        Comma-separated reseller_product post IDs.        Required.
 *   mode       "min" (cheapest, default), "max" or "range".
 *   before     Text printed before the price, e.g. "From ".      Default: ""
 *   after      Text printed after it, e.g. " per year".          Default: ""
 *   separator  Between the two range prices.                     Default: " to "
 *   fallback   Text to print if no product yields a price.       Default: ""
 *   class      Extra CSS class(es) on the wrapper.
 *
 * Markup (style from your theme, every part has a class):
 *   .rintent-price > .rintent-price-before / -amount / -sep / -after
 *
 * Usage: [rintent_price ids="116,118" before="Starting at " after="/mo"]
 */
final class Reseller_Intent_Price {

	public function register() {
		add_shortcode( 'rintent_price', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'ids'       => '',
				'mode'      => 'min',
				'before'    => '',
				'after'     => '',
				'separator' => ' to ',
				'fallback'  => '',
				'class'     => '',
			),
			$atts,
			'rintent_price'
		);

		$mode = in_array( $atts['mode'], array( 'min', 'max', 'range' ), true ) ? $atts['mode'] : 'min';

		/*
		 * Spacing guards: "From$4.99per year" is never what anyone means.
		 * If the wording does not carry its own spacing, add it.
		 */
		if ( '' !== $atts['before'] && ' ' !== substr( $atts['before'], -1 ) ) {
			$atts['before'] .= ' ';
		}
		if ( '' !== $atts['after'] && ' ' !== substr( $atts['after'], 0, 1 ) && '/' !== substr( $atts['after'], 0, 1 ) ) {
			$atts['after'] = ' ' . $atts['after'];
		}

		$lowest        = null;
		$highest       = null;
		$lowest_label  = '';
		$highest_label = '';

		$ids = wp_parse_id_list( $atts['ids'] );

		// One cache prime instead of a post + meta query per plan.
		if ( count( $ids ) > 1 && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
		}

		foreach ( $ids as $post_id ) {
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

			if ( null === $highest || $value > $highest ) {
				$highest       = $value;
				$highest_label = trim( $label );
			}
		}

		$classes = trim( 'rintent-price ' . preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) ( $atts['class'] ?? '' ) ) );

		if ( null === $lowest ) {
			if ( '' === (string) $atts['fallback'] ) {
				return '';
			}

			return '<span class="' . esc_attr( $classes ) . '">'
				. $this->part( 'before', $atts['before'] )
				. '<span class="rintent-price-amount">' . esc_html( $atts['fallback'] ) . '</span>'
				. $this->part( 'after', $atts['after'] )
				. '</span>';
		}

		$primary = 'max' === $mode ? $highest_label : $lowest_label;

		$html  = '<span class="' . esc_attr( $classes ) . '">';
		$html .= $this->part( 'before', $atts['before'] );
		$html .= '<span class="rintent-price-amount">' . esc_html( $primary ) . '</span>';

		// Range collapses to one price when every product costs the same.
		if ( 'range' === $mode && $highest > $lowest ) {
			$html .= '<span class="rintent-price-sep">' . esc_html( $atts['separator'] ) . '</span>';
			$html .= '<span class="rintent-price-amount rintent-price-amount--max">' . esc_html( $highest_label ) . '</span>';
		}

		$html .= $this->part( 'after', $atts['after'] );

		return $html . '</span>';
	}

	private function part( $name, $text ) {
		if ( '' === (string) $text ) {
			return '';
		}

		return '<span class="rintent-price-' . $name . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * "$1,234.99" → 1234.99; returns null for anything non-numeric.
	 */
	private function to_number( $label ) {
		$clean = preg_replace( '/[^0-9.]/', '', (string) $label );

		return is_numeric( $clean ) ? (float) $clean : null;
	}
}
