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
 *   family     Family slug from the Shortcodes page generator; every
 *              published plan of the family is included automatically.
 *   ids        Comma-separated reseller_product post IDs. Older embeds
 *              use this; family wins when both are set.
 *   mode       "min" (default), "max" or "range". The generator writes
 *              mode explicitly and defaults to range; the attribute
 *              default stays min so old embeds keep their output.
 *   before     Text printed before the price, e.g. "From ".      Default: ""
 *   after      Text printed after it, e.g. " per year".          Default: ""
 *   separator  Between the two range prices.                     Default: " to "
 *   fallback   Text to print if no product yields a price.       Default: ""
 *   class      Extra CSS class(es) on the wrapper.
 *
 * Markup (style from your theme, every part has a class):
 *   .rintent-price > .rintent-price-before / -amount / -sep / -after
 *
 * Usage: [rintent_price family="cpanel" mode="range" before="cPanel from" after="per year"]
 */
final class Reseller_Intent_Price {

	public function register() {
		add_shortcode( 'rintent_price', array( $this, 'render' ) );
	}

	/**
	 * Product families derived from the imported GoDaddy catalog. GoDaddy
	 * names plans as "Family + tier" (cPanel Starter/Economy/..., Web
	 * Hosting Plus Launch/Grow/...), so the family is the longest shared
	 * word prefix, cut before the first numeric token (VPS sizes, backup
	 * GBs). Only groups of two or more plans count as a family.
	 *
	 * Shared by the Shortcodes page generator and the family="" attribute,
	 * so both always agree on what a slug means. Cached per request.
	 *
	 * @return array[] Each: ['slug' => '', 'label' => '', 'ids' => [int, ...]], sorted by label.
	 */
	public static function families() {
		static $families = null;

		if ( null !== $families ) {
			return $families;
		}

		$products = get_posts(
			array(
				'post_type'      => 'reseller_product',
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded catalog scan, GoDaddy's catalog is far smaller.
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$prefix_counts = array();
		$product_meta  = array();
		foreach ( $products as $product ) {
			$words = preg_split( '/\s+/', trim( $product->post_title ) );
			$stem  = array();
			foreach ( $words as $word ) {
				if ( preg_match( '/^\(?\d/', $word ) ) {
					break;
				}
				$stem[] = $word;
			}
			if ( count( $stem ) === count( $words ) && count( $stem ) > 1 ) {
				array_pop( $stem ); // full title is never its own family.
			}
			$prefixes = array();
			for ( $k = count( $stem ); $k >= 1; $k-- ) {
				$prefix = rtrim( implode( ' ', array_slice( $stem, 0, $k ) ), ' -' );
				// Trailing connector words are naming glue, not family
				// identity ("SSL Setup Service - up to 5 sites").
				$prefix = preg_replace( '/(?:\s+(?:up|to|with|for|and))+$/i', '', $prefix );
				$prefix = rtrim( $prefix, ' -' );
				if ( '' === $prefix || in_array( $prefix, $prefixes, true ) ) {
					continue;
				}
				$prefixes[]               = $prefix;
				$prefix_counts[ $prefix ] = ( $prefix_counts[ $prefix ] ?? 0 ) + 1;
			}
			$product_meta[ $product->ID ] = $prefixes;
		}

		$grouped = array();
		foreach ( $products as $product ) {
			$family = $product->post_title;
			foreach ( $product_meta[ $product->ID ] as $prefix ) {
				if ( ( $prefix_counts[ $prefix ] ?? 0 ) >= 2 ) {
					$family = $prefix;
					break;
				}
			}
			if ( ! isset( $grouped[ $family ] ) ) {
				$grouped[ $family ] = array();
			}
			$grouped[ $family ][] = (int) $product->ID;
		}
		$grouped = array_filter(
			$grouped,
			static function ( $ids ) {
				return count( $ids ) >= 2;
			}
		);

		// Display labels: the longest word run shared by every member's
		// full title, so "Microsoft 365 ..." plans label as Microsoft 365
		// even though the numeric token was cut during grouping.
		$titles_by_id = array();
		foreach ( $products as $product ) {
			$titles_by_id[ $product->ID ] = $product->post_title;
		}
		$families = array();
		foreach ( $grouped as $family_key => $family_ids ) {
			$word_lists = array_map(
				static function ( $pid ) use ( $titles_by_id ) {
					return preg_split( '/\s+/', trim( $titles_by_id[ $pid ] ) );
				},
				$family_ids
			);
			$common     = $word_lists[0];
			foreach ( $word_lists as $word_list ) {
				$keep = array();
				foreach ( $word_list as $wi => $word ) {
					if ( isset( $common[ $wi ] ) && $common[ $wi ] === $word ) {
						$keep[] = $word;
					} else {
						break;
					}
				}
				$common = $keep;
			}

			/*
			 * A one-word head can be too thin to name a family: "Managed DV
			 * SSL Service" and "Managed SAN SSL Service" differ in the
			 * middle, so the head alone reads as plain "Managed". Borrow the
			 * shared tail in that case only, giving "Managed SSL Service".
			 * Longer heads already name themselves ("SSL Setup Service - up
			 * to 5/10 sites" must not become "...up to sites"), and families
			 * that differ only at the end have no shared tail at all.
			 */
			$tail_common = array();
			if ( 1 === count( $common ) ) {
				$tails = array();
				foreach ( $word_lists as $word_list ) {
					$tails[] = array_reverse( array_slice( $word_list, count( $common ) ) );
				}
				$tail_common = $tails[0];
				foreach ( $tails as $tail ) {
					$keep = array();
					foreach ( $tail as $ti => $word ) {
						if ( isset( $tail_common[ $ti ] ) && $tail_common[ $ti ] === $word ) {
							$keep[] = $word;
						} else {
							break;
						}
					}
					$tail_common = $keep;
				}
				// A tail that swallows a whole member's remainder
				// distinguishes nothing, so it stays out of the name.
				foreach ( $tails as $tail ) {
					if ( count( $tail_common ) >= count( $tail ) ) {
						$tail_common = array();
						break;
					}
				}
			}

			$label = rtrim( preg_replace( '/(?:\s+(?:up|to|with|for|and))+$/i', '', implode( ' ', array_merge( $common, array_reverse( $tail_common ) ) ) ), ' -' );
			$label = '' !== $label ? $label : $family_key;
			$slug  = sanitize_title( $label );

			if ( '' === $slug || isset( $families[ $slug ] ) ) {
				continue; // slug collision: first family keeps the name.
			}

			$families[ $slug ] = array(
				'slug'  => $slug,
				'label' => $label,
				'ids'   => $family_ids,
			);
		}

		$families = array_values( $families );
		usort(
			$families,
			static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $families;
	}

	/**
	 * Resolve a family slug to its product IDs.
	 *
	 * @param string $slug Family slug from the family="" attribute.
	 * @return int[] Product IDs, empty when the slug matches nothing.
	 */
	public static function family_ids( $slug ) {
		$slug = sanitize_title( (string) $slug );

		foreach ( self::families() as $family ) {
			if ( $family['slug'] === $slug ) {
				return $family['ids'];
			}
		}

		return array();
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'family'    => '',
				'ids'       => '',
				'mode'      => 'min', // attr default stays min so old ids="" embeds keep their output; the generator always writes mode= explicitly.
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

		$ids = '' !== trim( (string) $atts['family'] )
			? self::family_ids( $atts['family'] )
			: wp_parse_id_list( $atts['ids'] );

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

		$this->enqueue_style();

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

	/**
	 * Only the dark-section case needs styling, so this loads on render
	 * rather than sitewide, and carries the accent tokens the dark rule
	 * reads (a page can hold this shortcode and nothing else).
	 */
	private function enqueue_style() {
		if ( wp_style_is( 'reseller-intent-price', 'enqueued' ) ) {
			return;
		}

		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

		wp_enqueue_style(
			'reseller-intent-price',
			$base_url . 'assets/css/price.css',
			array(),
			filemtime( $base_path . 'assets/css/price.css' )
		);

		wp_add_inline_style(
			'reseller-intent-price',
			sprintf(
				'body{--rintent-accent:%s;--rintent-accent-dark:%s;}',
				Reseller_Intent_Settings::accent_color(),
				Reseller_Intent_Settings::accent_dark_color()
			)
		);
	}
}
