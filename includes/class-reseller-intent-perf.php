<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional performance trim for GoDaddy Reseller Store assets.
 *
 * Reseller Store enqueues React, ReactDOM, jQuery, js-cookie, its store
 * scripts, dashicons and its stylesheet on EVERY page, whether the page
 * shows any store element or not. With this opt-in setting on, those
 * assets load only on pages that actually use them.
 *
 * Detection is deliberately conservative, assets stay loaded when:
 * - the page content contains any Reseller Store shortcode
 * - it is a reseller_product single or archive page
 * - any Reseller Store widget is active in any sidebar (per-page
 *   knowledge is impossible there, so nothing is trimmed at all)
 * - the page is in the owner's keep-list (builders, popups, headers)
 * - the visitor is on search, 404 or a non-singular view we cannot read
 */
final class Reseller_Intent_Perf {

	public function register() {
		// After Reseller Store enqueues (10), before our own gate (99).
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_trim' ), 50 );
	}

	public function maybe_trim() {
		if ( ! Reseller_Intent_Settings::get( 'trim_gd_assets' ) ) {
			return;
		}

		if ( $this->page_needs_store() ) {
			return;
		}

		wp_dequeue_script( 'reseller-store-js' );
		wp_dequeue_script( 'reseller-store-domain-js' );
		wp_dequeue_script( 'js-cookie' );
		wp_dequeue_style( 'reseller-store-css' );

		// Dashicons rides in as the store stylesheet's dependency. Keep it
		// for logged-in visitors, the admin bar needs it.
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	private function page_needs_store() {
		if ( is_singular( 'reseller_product' ) || is_post_type_archive( 'reseller_product' ) || is_tax( 'reseller_product_category' ) ) {
			return true;
		}

		if ( $this->store_widget_active() ) {
			return true;
		}

		if ( ! is_singular() ) {
			// Archives, search, 404: content unknown, do not gamble.
			return true;
		}

		$post = get_post();

		if ( $post && in_array( (int) $post->ID, $this->keep_ids(), true ) ) {
			return true;
		}

		if ( $post && $this->mentions_store( (string) $post->post_content ) ) {
			return true;
		}

		if ( $post && $this->builder_data_mentions_store( (int) $post->ID ) ) {
			return true;
		}

		/**
		 * Builders and theme parts can render the widget outside post
		 * content. Return true to keep Reseller Store assets on this page.
		 *
		 * @param bool    $needs Whether the page needs store assets.
		 * @param WP_Post $post  Current post.
		 */
		return (bool) apply_filters( 'rintent_page_needs_store', false, $post );
	}

	/**
	 * Any mention keeps the assets, over-keeping is always safe:
	 * - "rstore" covers every Reseller Store shortcode ([rstore_domain_search]
	 *   etc.) in the classic editor, Gutenberg shortcode blocks, WPBakery
	 *   and Divi (both store shortcodes in post_content)
	 * - "reseller-store" covers the Gutenberg blocks
	 *   (wp:reseller-store/domain-search, wp:reseller-store/product)
	 */
	private function mentions_store( $content ) {
		if ( '' === $content ) {
			return false;
		}

		return false !== strpos( $content, 'rstore' ) || false !== strpos( $content, 'reseller-store' );
	}

	/**
	 * Page builders that keep their layout in post meta instead of
	 * post_content: Elementor, Bricks, Beaver Builder, Oxygen. Templates
	 * rendered from OTHER posts (Elementor theme builder headers, popups)
	 * are invisible here, that is what the keep-list and the
	 * rintent_page_needs_store filter are for.
	 */
	private function builder_data_mentions_store( $post_id ) {
		$meta_keys = array(
			'_elementor_data',
			'_bricks_page_content_2',
			'_fl_builder_data',
			'ct_builder_shortcodes',
			'ct_builder_json',
		);

		foreach ( $meta_keys as $meta_key ) {
			$data = get_post_meta( $post_id, $meta_key, true );

			if ( ! is_string( $data ) ) {
				$data = maybe_serialize( $data );
			}

			if ( is_string( $data ) && $this->mentions_store( $data ) ) {
				return true;
			}
		}

		return false;
	}

	private function store_widget_active() {
		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $sidebar => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar || 'array_version' === $sidebar ) {
				continue;
			}

			foreach ( (array) $widgets as $widget_id ) {
				if ( 0 === strpos( (string) $widget_id, 'rstore' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function keep_ids() {
		return array_map( 'intval', (array) Reseller_Intent_Settings::get( 'gd_asset_pages' ) );
	}
}
