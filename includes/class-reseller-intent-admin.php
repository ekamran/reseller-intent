<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reseller Intent, analytics dashboard for the Reseller Store domain search.
 *
 * The page is a React app (wp-element) fed by a single AJAX endpoint that
 * returns every panel's data for one selected range, so the whole dashboard
 * follows one time window.
 */
final class Reseller_Intent_Admin {

	const PAGE_SLUG = 'reseller-intent';

	// Open range edges, so every query keeps the same two date placeholders.
	const RANGE_MIN = '1970-01-01 00:00:00';
	const RANGE_MAX = '9999-12-31 23:59:59';

	/**
	 * Who can see the dashboard/exports. Filterable so agencies can open it
	 * to editors etc.: add_filter( 'rintent_dashboard_capability', fn() => 'edit_pages' );
	 */
	/**
	 * Punycode is what gets stored, because that is the real domain, but
	 * "xn--mnchen-hotels-wob.de" tells a reseller nothing. Show the unicode
	 * form on screen where the host can decode it, and the stored form
	 * everywhere else. Exports keep punycode: it travels better.
	 */
	public static function display_domain( $domain ) {
		$domain = (string) $domain;

		if ( '' === $domain || false === strpos( $domain, 'xn--' ) || ! function_exists( 'idn_to_utf8' ) ) {
			return $domain;
		}

		$decoded = idn_to_utf8( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );

		return ( is_string( $decoded ) && '' !== $decoded ) ? $decoded : $domain;
	}

	public static function capability() {
		return (string) apply_filters( 'rintent_dashboard_capability', 'manage_options' );
	}

	/**
	 * Custom menu glyph: radar scope, ring with a sweep wedge and a blip
	 * dot. Reads as "detecting visitor intent", not another generic chart.
	 * Fill-only paths in a neutral base color so WordPress repaints it to
	 * match the active admin color scheme (svg-painter skips strokes).
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			. '<path fill="#a7aaad" fill-rule="evenodd" d="M10 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Zm0 1.7a6.3 6.3 0 1 0 0 12.6 6.3 6.3 0 0 0 0-12.6Z"/>'
			. '<path fill="#a7aaad" d="M10 10 11.08 3.89a6.2 6.2 0 0 1 4.54 3.49Z"/>'
			. '<circle fill="#a7aaad" cx="6.4" cy="12.2" r="1.5"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- standard WP menu icon data URI.
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'Reseller Intent', 'reseller-intent' ),
			__( 'Reseller Intent', 'reseller-intent' ),
			self::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' ),
			self::menu_icon(),
			58
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Dashboard', 'reseller-intent' ),
			__( 'Dashboard', 'reseller-intent' ),
			self::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Shortcodes', 'reseller-intent' ),
			__( 'Shortcodes', 'reseller-intent' ),
			self::capability(),
			self::PAGE_SLUG . '-shortcodes',
			array( $this, 'render_shortcodes_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'reseller-intent' ),
			__( 'Settings', 'reseller-intent' ),
			self::capability(),
			self::PAGE_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Shortcode generator: build [rintent_tld_strip] and [rintent_price]
	 * visually, copy the result. No page reloads, no AJAX, plain JS.
	 */
	public function render_shortcodes_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$products = get_posts(
			array(
				'post_type'      => 'reseller_product',
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded product picker list, admin only.
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		/*
		 * Product families for range mode: GoDaddy's catalog names plans as
		 * "Family + tier" (cPanel Starter/Economy/..., Web Hosting Plus
		 * Launch/Grow/...). The family is the longest shared word prefix,
		 * cut before the first numeric token (VPS sizes, backup GBs).
		 */
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
		$families = array();
		foreach ( $products as $product ) {
			$family = $product->post_title;
			foreach ( $product_meta[ $product->ID ] as $prefix ) {
				if ( ( $prefix_counts[ $prefix ] ?? 0 ) >= 2 ) {
					$family = $prefix;
					break;
				}
			}
			if ( ! isset( $families[ $family ] ) ) {
				$families[ $family ] = array();
			}
			$families[ $family ][] = (int) $product->ID;
		}
		$families = array_filter(
			$families,
			function ( $ids ) {
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
		$labeled = array();
		foreach ( $families as $family_key => $family_ids ) {
			$word_lists = array_map(
				function ( $pid ) use ( $titles_by_id ) {
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
			$label = rtrim( preg_replace( '/(?:\s+(?:up|to|with|for|and))+$/i', '', implode( ' ', $common ) ), ' -' );
			$labeled[ '' !== $label ? $label : $family_key ] = $family_ids;
		}
		$families = $labeled;
		ksort( $families );
		?>
		<?php $notice = isset( $_GET['rintent_notice'] ) ? sanitize_key( wp_unslash( $_GET['rintent_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="wrap rintent-pages rintent-shortcodes">
			<h1><?php esc_html_e( 'Shortcodes', 'reseller-intent' ); ?></h1>
			<p class="rintent-intro"><?php esc_html_e( 'Everything for each shortcode lives on its card: options, prices or numbers, and the code to copy.', 'reseller-intent' ); ?></p>

			<?php if ( 'numbers_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Support numbers saved.', 'reseller-intent' ); ?></p></div>
			<?php elseif ( 'numbers_reset' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Support numbers reset to the GoDaddy defaults.', 'reseller-intent' ); ?></p></div>
			<?php elseif ( 'tld_refreshed' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'TLD prices refreshed from the storefront API.', 'reseller-intent' ); ?></p></div>
			<?php elseif ( 'tld_refresh_none' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Nothing to refresh yet. Prices are cached after the TLD strip renders for the first time.', 'reseller-intent' ); ?></p></div>
			<?php endif; ?>

			<div class="rintent-card">
				<h2><?php esc_html_e( 'TLD price strip', 'reseller-intent' ); ?></h2>
				<p class="rintent-card-desc"><?php esc_html_e( 'Live TLD price pills that always match checkout prices. Cached for 12 hours and refreshed in the background.', 'reseller-intent' ); ?></p>

				<div class="rintent-field">
					<span class="rintent-label"><label for="rintent-gen-tlds"><?php esc_html_e( 'TLDs', 'reseller-intent' ); ?></label></span>
					<span>
						<input type="text" id="rintent-gen-tlds" class="regular-text" value=".com,.in,.org,.net,.io" />
						<p class="description"><?php esc_html_e( 'Comma-separated, with or without dots.', 'reseller-intent' ); ?></p>
					</span>
				</div>
				<div class="rintent-field">
					<span class="rintent-label"><label for="rintent-gen-theme"><?php esc_html_e( 'Theme', 'reseller-intent' ); ?></label></span>
					<span>
						<select id="rintent-gen-theme"><option value="light"><?php esc_html_e( 'Light', 'reseller-intent' ); ?></option><option value="dark"><?php esc_html_e( 'Dark', 'reseller-intent' ); ?></option></select>
						<p class="description"><?php esc_html_e( 'Dark is for dark page sections. Prices use the Dark accent color from Settings (auto-lightened from your accent unless you pick one). CSS can still override anything: --rintent-accent-dark and the --rintent-tld-* variables.', 'reseller-intent' ); ?></p>
					</span>
				</div>
				<div class="rintent-field">
					<span class="rintent-label"><label for="rintent-gen-more-label"><?php esc_html_e( '"More" pill', 'reseller-intent' ); ?></label></span>
					<span>
						<input type="text" id="rintent-gen-more-label" class="regular-text" aria-label="<?php esc_attr_e( 'Text on the more pill', 'reseller-intent' ); ?>" placeholder="<?php esc_attr_e( 'More TLDs', 'reseller-intent' ); ?>" />
						<input type="url" id="rintent-gen-more-url" class="regular-text" aria-label="<?php esc_attr_e( 'Link for the more pill', 'reseller-intent' ); ?>" placeholder="https://example.com/domains/" />
						<p class="description"><?php esc_html_e( 'Optional link pill at the end. Leave the URL empty to hide it.', 'reseller-intent' ); ?></p>
					</span>
				</div>
				<div class="rintent-field">
					<span class="rintent-label"><?php esc_html_e( 'Shortcode', 'reseller-intent' ); ?></span>
					<span class="rintent-output">
						<code id="rintent-gen-tld-out"></code>
						<button type="button" class="button" id="rintent-gen-tld-copy"><?php esc_html_e( 'Copy', 'reseller-intent' ); ?></button>
					</span>
				</div>
				<div class="rintent-field">
					<span class="rintent-label"><?php esc_html_e( 'Live preview', 'reseller-intent' ); ?></span>
					<span>
						<div id="rintent-preview-tld" class="rintent-preview" data-empty="<?php esc_attr_e( 'Rendering...', 'reseller-intent' ); ?>"></div>
						<p class="description"><?php esc_html_e( 'Exactly what visitors get, live prices included.', 'reseller-intent' ); ?></p>
					</span>
				</div>
				<div class="rintent-field">
					<span class="rintent-label"><?php esc_html_e( 'Price cache', 'reseller-intent' ); ?></span>
					<span>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rintent-inline-form">
							<input type="hidden" name="action" value="rintent_refresh_tld" />
							<?php wp_nonce_field( 'rintent_refresh_tld' ); ?>
							<?php submit_button( __( 'Refresh prices now', 'reseller-intent' ), 'secondary', 'submit', false ); ?>
						</form>
						<p class="description"><?php esc_html_e( 'Prices refresh in the background every 11 hours and after a Reseller Store product import. Use this after a price change you want live right away.', 'reseller-intent' ); ?></p>
					</span>
				</div>
			</div>

			<div class="rintent-card">
				<h2><?php esc_html_e( 'Live product price', 'reseller-intent' ); ?></h2>
				<p class="rintent-card-desc"><?php esc_html_e( 'Prints a live price from the selected products: cheapest, highest or a range. Add your own text around it. Select every plan of a family so the number stays correct when prices change.', 'reseller-intent' ); ?></p>

				<?php if ( empty( $products ) ) : ?>
					<p><em><?php esc_html_e( 'No published Reseller Store products found. Import products in Reseller Store first.', 'reseller-intent' ); ?></em></p>
				<?php else : ?>
					<div class="rintent-field" id="rintent-gen-products-row">
						<span class="rintent-label"><label for="rintent-gen-filter"><?php esc_html_e( 'Products', 'reseller-intent' ); ?></label></span>
						<span>
							<input type="search" id="rintent-gen-filter" class="regular-text" aria-label="<?php esc_attr_e( 'Filter products', 'reseller-intent' ); ?>" placeholder="<?php esc_attr_e( 'Filter products...', 'reseller-intent' ); ?>" style="margin-bottom:8px;" />
							<div class="rintent-product-picker" id="rintent-gen-products">
								<?php
								foreach ( $products as $product ) :
									$sale  = (string) get_post_meta( $product->ID, 'rstore_salePrice', true );
									$list  = (string) get_post_meta( $product->ID, 'rstore_listPrice', true );
									$price = '' !== trim( $sale ) ? $sale : $list;
									?>
									<label>
										<input type="checkbox" class="rintent-gen-product" value="<?php echo esc_attr( $product->ID ); ?>" data-title="<?php echo esc_attr( strtolower( $product->post_title ) ); ?>" data-price="<?php echo esc_attr( trim( $price ) ); ?>" />
										<?php echo esc_html( $product->post_title ); ?>
										<span class="rintent-product-meta">- <?php echo esc_html( '' !== trim( $price ) ? $price : __( 'no price', 'reseller-intent' ) ); ?> &middot; ID <?php echo esc_html( $product->ID ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-gen-mode"><?php esc_html_e( 'Show', 'reseller-intent' ); ?></label></span>
						<span>
							<select id="rintent-gen-mode">
								<option value="min"><?php esc_html_e( 'Cheapest price', 'reseller-intent' ); ?></option>
								<option value="max"><?php esc_html_e( 'Highest price', 'reseller-intent' ); ?></option>
								<option value="range"><?php esc_html_e( 'Price range (cheapest to highest)', 'reseller-intent' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Range always covers one product family, its cheapest to its highest plan.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field" id="rintent-gen-family-row" style="display:none;">
						<span class="rintent-label"><label for="rintent-gen-family"><?php esc_html_e( 'Product family', 'reseller-intent' ); ?></label></span>
						<span>
							<?php if ( empty( $families ) ) : ?>
								<p class="description"><?php esc_html_e( 'No family with two or more plans found yet. Import your Reseller Store products first.', 'reseller-intent' ); ?></p>
							<?php else : ?>
								<select id="rintent-gen-family">
									<?php foreach ( $families as $family_label => $family_ids ) : ?>
										<option value="<?php echo esc_attr( implode( ',', $family_ids ) ); ?>"><?php echo esc_html( $family_label ); ?> (<?php echo esc_html( count( $family_ids ) ); ?>)</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'All plans of the family are included automatically.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-gen-before"><?php esc_html_e( 'Text around it', 'reseller-intent' ); ?></label></span>
						<span>
							<input type="text" id="rintent-gen-before" class="regular-text" aria-label="<?php esc_attr_e( 'Text before the price', 'reseller-intent' ); ?>" placeholder="<?php esc_attr_e( 'Starting at ', 'reseller-intent' ); ?>" />
							<input type="text" id="rintent-gen-after" class="regular-text" aria-label="<?php esc_attr_e( 'Text after the price', 'reseller-intent' ); ?>" placeholder="<?php esc_attr_e( ' per year', 'reseller-intent' ); ?>" />
							<p class="description"><?php esc_html_e( 'Before and after text, both optional. Use any wording you like, spaces included.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field" id="rintent-gen-sep-row" style="display:none;">
						<span class="rintent-label"><label for="rintent-gen-separator"><?php esc_html_e( 'Range separator', 'reseller-intent' ); ?></label></span>
						<span>
							<input type="text" id="rintent-gen-separator" class="regular-text" aria-label="<?php esc_attr_e( 'Separator between the two range prices', 'reseller-intent' ); ?>" placeholder=" to " />
							<p class="description"><?php esc_html_e( 'Printed between the two prices. Default: to', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-gen-fallback"><?php esc_html_e( 'Fallback text', 'reseller-intent' ); ?></label></span>
						<span>
							<input type="text" id="rintent-gen-fallback" class="regular-text" aria-label="<?php esc_attr_e( 'Fallback text when no price is available', 'reseller-intent' ); ?>" placeholder="$3.99" />
							<p class="description"><?php esc_html_e( 'Shown if no selected product has a price.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Shortcode', 'reseller-intent' ); ?></span>
						<span class="rintent-output">
							<code id="rintent-gen-price-out" data-empty="<?php esc_attr_e( 'Select at least one product', 'reseller-intent' ); ?>"></code>
							<button type="button" class="button" id="rintent-gen-price-copy"><?php esc_html_e( 'Copy', 'reseller-intent' ); ?></button>
						</span>
					</div>
						<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Live preview', 'reseller-intent' ); ?></span>
						<span>
							<div id="rintent-preview-price" class="rintent-preview rintent-preview--inline" data-empty="<?php esc_attr_e( 'Select products to see it.', 'reseller-intent' ); ?>"></div>
						</span>
					</div>
				<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Styling', 'reseller-intent' ); ?></span>
						<span>
							<p class="description" style="margin:0;"><?php esc_html_e( 'Every part has its own class, style them from your theme:', 'reseller-intent' ); ?> <code>.rintent-price</code> <code>.rintent-price-before</code> <code>.rintent-price-amount</code> <code>.rintent-price-sep</code> <code>.rintent-price-after</code></p>
						</span>
					</div>
				<?php endif; ?>
			</div>

			<div class="rintent-card">
				<h2><?php esc_html_e( 'Support phone number', 'reseller-intent' ); ?></h2>
				<p class="rintent-card-desc"><?php esc_html_e( 'Shows the right regional support number to each visitor, based on their browser timezone. Page-cache safe. Numbers, shortcode, everything is right here.', 'reseller-intent' ); ?></p>

				<?php if ( Reseller_Intent_Phone::is_customized() ) : ?>
					<p class="rintent-numbers-status rintent-numbers-status--custom">
						<span class="dashicons dashicons-edit" aria-hidden="true"></span>
						<?php esc_html_e( 'Your own list is saved. Plugin updates will never change it.', 'reseller-intent' ); ?>
						<a class="rintent-numbers-reset" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rintent_reset_numbers' ), 'rintent_reset_numbers' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Replace your list with the GoDaddy default numbers? Your custom entries will be removed.', 'reseller-intent' ) ); ?>');"><?php esc_html_e( 'Reset to GoDaddy defaults', 'reseller-intent' ); ?></a>
					</p>
				<?php else : ?>
					<p class="rintent-numbers-status rintent-numbers-status--default">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'GoDaddy default numbers active. Edit and save to make the list yours.', 'reseller-intent' ); ?>
					</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="rintent_save_numbers" />
					<?php wp_nonce_field( 'rintent_save_numbers' ); ?>
					<table id="rintent-support-rows" class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Label', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'Phone number', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'Countries', 'reseller-intent' ); ?></th><th></th></tr></thead>
						<tbody>
							<?php foreach ( Reseller_Intent_Phone::numbers() as $support_entry ) : ?>
								<tr>
									<td><input type="text" name="support_label[]" aria-label="<?php esc_attr_e( 'Support entry label', 'reseller-intent' ); ?>" value="<?php echo esc_attr( $support_entry['label'] ); ?>" placeholder="<?php esc_attr_e( 'US Support', 'reseller-intent' ); ?>" /></td>
									<td><input type="text" name="support_number[]" aria-label="<?php esc_attr_e( 'Support phone number', 'reseller-intent' ); ?>" value="<?php echo esc_attr( $support_entry['number'] ); ?>" placeholder="+1-480-000-0000" /></td>
									<td><input type="text" name="support_countries[]" aria-label="<?php esc_attr_e( 'Country codes for this number', 'reseller-intent' ); ?>" value="<?php echo esc_attr( implode( ',', (array) $support_entry['countries'] ) ); ?>" placeholder="US,CA" /></td>
									<td><button type="button" class="button-link-delete rintent-support-remove" aria-label="<?php esc_attr_e( 'Remove row', 'reseller-intent' ); ?>">&times;</button></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description" style="margin:8px 0 12px;"><?php esc_html_e( 'Countries: comma-separated 2-letter codes like IN, US, AE. Leave empty on one row to make it the default for everyone else.', 'reseller-intent' ); ?></p>
					<p class="rintent-inline-actions">
						<button type="button" class="button" id="rintent-support-add"><?php esc_html_e( 'Add number', 'reseller-intent' ); ?></button>
						<?php submit_button( __( 'Save numbers', 'reseller-intent' ), 'primary', 'submit', false ); ?>
					</p>
				</form>

				<div class="rintent-field" style="border-top:1px solid #f0f3f8;margin-top:4px;">
					<span class="rintent-label"><?php esc_html_e( 'Shortcode', 'reseller-intent' ); ?></span>
					<span class="rintent-output">
						<code>[rintent_phone]</code>
					</span>
				</div>
				<div class="rintent-field">
					<span class="rintent-label"><?php esc_html_e( 'Live preview', 'reseller-intent' ); ?></span>
					<span>
						<div id="rintent-preview-phone" class="rintent-preview rintent-preview--inline"></div>
						<p class="description"><?php esc_html_e( 'Flag and number as a call link. Shows the default here; each visitor sees their regional one.', 'reseller-intent' ); ?></p>
					</span>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		Reseller_Intent_DB::ensure_table();

		$accent    = Reseller_Intent_Settings::accent_color();
		$retention = (int) Reseller_Intent_Settings::get( 'retention_days' );
		$uninstall = (bool) Reseller_Intent_Settings::get( 'delete_on_uninstall' );
		$bots      = (bool) Reseller_Intent_Settings::get( 'track_bots' );

		$retention_choices = array(
			0   => __( 'Keep forever (default)', 'reseller-intent' ),
			30  => __( '30 days', 'reseller-intent' ),
			90  => __( '90 days', 'reseller-intent' ),
			180 => __( '180 days', 'reseller-intent' ),
			365 => __( '1 year', 'reseller-intent' ),
			730 => __( '2 years', 'reseller-intent' ),
		);
		$notice            = isset( $_GET['rintent_notice'] ) ? sanitize_key( wp_unslash( $_GET['rintent_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rintent-pages rintent-settings">
			<h1><?php esc_html_e( 'Settings', 'reseller-intent' ); ?></h1>
			<p class="rintent-intro"><?php esc_html_e( 'Everything is optional. Tracking works out of the box.', 'reseller-intent' ); ?></p>

			<?php if ( 'settings_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'reseller-intent' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rintent_save_settings" />
				<?php wp_nonce_field( 'rintent_save_settings' ); ?>

				<div class="rintent-card">
					<h2><?php esc_html_e( 'Appearance', 'reseller-intent' ); ?></h2>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-accent"><?php esc_html_e( 'Accent color', 'reseller-intent' ); ?></label></span>
						<span>
							<input type="text" id="rintent-accent" name="accent_color" class="rintent-colorpicker" value="<?php echo esc_attr( $accent ); ?>" />
							<p class="description"><?php esc_html_e( 'Used on the dashboard, the styled widget and the TLD price strip.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-accent-dark"><?php esc_html_e( 'Dark accent color', 'reseller-intent' ); ?></label></span>
						<span>
							<?php $accent_dark_custom = '' !== (string) Reseller_Intent_Settings::get( 'accent_dark' ); ?>
							<label for="rintent-accent-dark-custom" style="display:block;margin-bottom:8px;">
								<input type="checkbox" id="rintent-accent-dark-custom" name="accent_dark_custom" value="1" <?php checked( $accent_dark_custom ); ?> />
								<?php esc_html_e( 'Pick my own color for dark sections', 'reseller-intent' ); ?>
							</label>
							<input type="text" id="rintent-accent-dark" name="accent_dark" class="rintent-colorpicker" value="<?php echo esc_attr( Reseller_Intent_Settings::accent_dark_color() ); ?>" <?php disabled( ! $accent_dark_custom ); ?> />
							<p class="description"><?php esc_html_e( 'Recolors text accents on dark surfaces, like the prices in the dark TLD strip. Auto lightens your accent just enough to stay readable and follows whenever the accent changes. Buttons are not affected, they keep the accent color everywhere.', 'reseller-intent' ); ?></p>
						</span>
					</div>
				</div>

				<div class="rintent-card">
					<h2><?php esc_html_e( 'Widget styling', 'reseller-intent' ); ?></h2>
					<p class="rintent-card-desc"><?php esc_html_e( 'Optional polish for the Reseller Store search widget. Turn the first toggle off if it fights with your theme. Tracking is not affected.', 'reseller-intent' ); ?></p>
					<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Options', 'reseller-intent' ); ?></span>
						<span class="rintent-check-group">
							<label for="rintent-style-widget">
								<input type="checkbox" id="rintent-style-widget" name="style_widget" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'style_widget' ) ); ?> />
								<?php esc_html_e( 'Style the domain search widget (accent buttons, aligned rows, mobile layout)', 'reseller-intent' ); ?>
							</label>
							<label for="rintent-skeletons">
								<input type="checkbox" id="rintent-skeletons" name="widget_skeletons" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'widget_skeletons' ) ); ?> />
								<?php esc_html_e( 'Skeleton loading rows while results load', 'reseller-intent' ); ?>
							</label>
							<label for="rintent-clear-all">
								<input type="checkbox" id="rintent-clear-all" name="widget_clear_all" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'widget_clear_all' ) ); ?> />
								<?php esc_html_e( 'Floating "Clear All" button under the search bar', 'reseller-intent' ); ?>
							</label>
							<span id="rintent-clear-label-row" <?php echo Reseller_Intent_Settings::get( 'widget_clear_all' ) ? '' : 'style="display:none;"'; ?>>
								<p style="margin:8px 0 0;">
									<input type="text" name="clear_all_label" class="regular-text" maxlength="40" aria-label="<?php esc_attr_e( 'Clear All button text', 'reseller-intent' ); ?>" value="<?php echo esc_attr( (string) Reseller_Intent_Settings::get( 'clear_all_label' ) ); ?>" placeholder="<?php esc_attr_e( 'Clear All', 'reseller-intent' ); ?>" />
								</p>
								<p class="description"><?php esc_html_e( 'Its button text, any wording or language. Leave empty for the default.', 'reseller-intent' ); ?></p>
							</span>
							<p class="description"><?php esc_html_e( 'Dark sections are detected automatically and the widget switches to light-on-dark colors on its own. To force it either way, wrap the section in a .rintent-dark or .rintent-light class.', 'reseller-intent' ); ?></p>

							<details class="rintent-theming-ref">
								<summary><?php esc_html_e( 'Theming reference: CSS classes and variables', 'reseller-intent' ); ?></summary>
								<p class="description"><?php esc_html_e( 'Target these from your theme or Additional CSS to restyle any part of the widget.', 'reseller-intent' ); ?></p>
								<table class="widefat striped">
									<thead><tr><th><?php esc_html_e( 'Element', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'CSS class / variable', 'reseller-intent' ); ?></th></tr></thead>
									<tbody>
										<tr><td><?php esc_html_e( 'Domain name in results', 'reseller-intent' ); ?></td><td><code>.rstore-domain-search .domain-name</code><br /><code>--rintent-domain-size</code> &middot; <code>--rintent-domain-color</code> &middot; <code>--rintent-domain-font</code> &middot; <code>--rintent-domain-weight</code></td></tr>
										<tr><td><?php esc_html_e( 'Price', 'reseller-intent' ); ?></td><td><code>.rstore-message .salePrice</code> / <code>.listPrice</code><br /><code>--rintent-price-size</code> &middot; <code>--rintent-price-color</code> &middot; <code>--rintent-price-font</code> &middot; <code>--rintent-price-weight</code></td></tr>
										<tr><td><?php esc_html_e( 'Clear All button', 'reseller-intent' ); ?></td><td><code>.rintent-clear-btn</code><br /><code>--rintent-clear-color</code> &middot; <code>--rintent-clear-size</code></td></tr>
										<tr><td><?php esc_html_e( 'Result row card', 'reseller-intent' ); ?></td><td><code>.rstore-domain-search .domain-result</code></td></tr>
										<tr><td><?php esc_html_e( 'Search button / Continue to cart', 'reseller-intent' ); ?></td><td><code>.search-form input[type=submit]</code> &middot; <code>.rstore-domain-continue-button</code></td></tr>
										<tr><td><?php esc_html_e( 'Select / Selected links', 'reseller-intent' ); ?></td><td><code>.rstore-domain-buy-button.select</code> &middot; <code>.rstore-domain-buy-button.selected</code></td></tr>
										<tr><td><?php esc_html_e( 'Accent (buttons, focus ring)', 'reseller-intent' ); ?></td><td><code>--rintent-accent</code> <?php esc_html_e( '(set by the color picker above)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Accent as text on light surfaces', 'reseller-intent' ); ?></td><td><code>--rintent-accent-ink</code> <?php esc_html_e( '(the accent darkened only as far as it needs to stay readable, used for prices in the light TLD strip)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Accent on dark surfaces', 'reseller-intent' ); ?></td><td><code>--rintent-accent-dark</code> <?php esc_html_e( '(set by the Dark accent picker above; this variable overrides it)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Text on accent surfaces', 'reseller-intent' ); ?></td><td><code>--rintent-accent-text</code> &middot; <code>--rintent-accent-dark-text</code> <?php esc_html_e( '(auto-computed for contrast; set to force your own)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Buttons on dark sections', 'reseller-intent' ); ?></td><td><code>--rintent-dark-button</code> &middot; <code>--rintent-dark-button-text</code> <?php esc_html_e( '(default: the accent; the Dark accent picker never recolors buttons)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Corner rounding', 'reseller-intent' ); ?></td><td><code>--rintent-radius</code> <?php esc_html_e( '(search bar, result rows and buttons; default 8px, use 0 for square or 50px for pills)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Dark section context', 'reseller-intent' ); ?></td><td><code>.rintent-dark</code> &middot; <code>.rintent-light</code> <?php esc_html_e( '(wrapper classes; auto-detected when absent)', 'reseller-intent' ); ?></td></tr>
									</tbody>
								</table>
								<p class="description"><?php esc_html_e( 'Example:', 'reseller-intent' ); ?> <code>body{--rintent-domain-size:18px;--rintent-price-color:#0a7d5c;}</code></p>
							</details>
						</span>
					</div>
				</div>

				<div class="rintent-card">
					<h2><?php esc_html_e( 'Performance', 'reseller-intent' ); ?></h2>
					<p class="rintent-card-desc"><?php esc_html_e( 'Reseller Store loads React, jQuery add-ons and its styles on every page of the site, even pages with no store element. Trim that to only the pages that need it.', 'reseller-intent' ); ?></p>
					<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Asset trim', 'reseller-intent' ); ?></span>
						<span>
							<label for="rintent-trim-gd">
								<input type="checkbox" id="rintent-trim-gd" name="trim_gd_assets" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'trim_gd_assets' ) ); ?> />
								<?php esc_html_e( 'Load Reseller Store assets only where they are used', 'reseller-intent' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Kept automatically: pages whose content has any Reseller Store shortcode, product pages, and every page when a Reseller Store widget sits in a sidebar. Tracking follows along, pages without the widget load nothing from this plugin either.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-gd-pages"><?php esc_html_e( 'Always keep on', 'reseller-intent' ); ?></label></span>
						<span>
							<input type="text" id="rintent-gd-pages" name="gd_asset_pages" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) Reseller_Intent_Settings::get( 'gd_asset_pages' ) ) ); ?>" placeholder="12, 34, 56" />
							<p class="description"><?php esc_html_e( 'Page or post IDs, comma-separated. For pages where a builder or popup renders the widget outside the content, detection cannot see those. Developers can also use the rintent_page_needs_store filter.', 'reseller-intent' ); ?></p>
						</span>
					</div>
				</div>

				<div class="rintent-card">
					<h2><?php esc_html_e( 'Tracking and privacy', 'reseller-intent' ); ?></h2>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-blocklist"><?php esc_html_e( 'Ignore searches', 'reseller-intent' ); ?></label></span>
						<span>
							<textarea id="rintent-blocklist" name="blocklist" rows="4" class="large-text code" placeholder="mytestdomain.com&#10;*.internal&#10;staging*"><?php echo esc_textarea( implode( "\n", (array) Reseller_Intent_Settings::get( 'blocklist' ) ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One pattern per line, matched against searched domains. Use * as a wildcard. Handy for ignoring your own test searches.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Bots', 'reseller-intent' ); ?></span>
						<span>
							<label for="rintent-bots">
								<input type="checkbox" id="rintent-bots" name="track_bots" value="1" <?php checked( $bots ); ?> />
								<?php esc_html_e( 'Also record events from known bots and crawlers (off recommended)', 'reseller-intent' ); ?>
							</label>
						</span>
					</div>
				</div>

				<div class="rintent-card">
					<h2><?php esc_html_e( 'Data storage', 'reseller-intent' ); ?></h2>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-retention"><?php esc_html_e( 'Retention', 'reseller-intent' ); ?></label></span>
						<span>
							<select id="rintent-retention" name="retention_days">
								<?php foreach ( $retention_choices as $days => $label ) : ?>
									<option value="<?php echo esc_attr( $days ); ?>" <?php selected( $retention, $days ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Events older than this are deleted once a day. You can also clear specific windows from the dashboard any time.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Uninstall', 'reseller-intent' ); ?></span>
						<span>
							<label for="rintent-uninstall">
								<input type="checkbox" id="rintent-uninstall" name="delete_on_uninstall" value="1" <?php checked( $uninstall ); ?> />
								<?php esc_html_e( 'Delete all tracked data and settings when the plugin is uninstalled', 'reseller-intent' ); ?>
							</label>
						</span>
					</div>
				</div>

				<?php submit_button( __( 'Save Settings', 'reseller-intent' ) ); ?>
			</form>

		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook_suffix ) {
		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

		$page_hooks = array(
			'reseller-intent_page_' . self::PAGE_SLUG . '-settings',
			'reseller-intent_page_' . self::PAGE_SLUG . '-shortcodes',
		);

		if ( in_array( $hook_suffix, $page_hooks, true ) ) {
			wp_enqueue_style(
				'rintent-admin-pages',
				$base_url . 'assets/css/admin-pages.css',
				array(),
				filemtime( $base_path . 'assets/css/admin-pages.css' )
			);

			wp_enqueue_style( 'wp-color-picker' );

			// Shortcode previews use the real frontend strip styles.
			wp_enqueue_style(
				'reseller-intent-tld-strip',
				$base_url . 'assets/css/tld-strip.css',
				array(),
				filemtime( $base_path . 'assets/css/tld-strip.css' )
			);
			wp_add_inline_style(
				'reseller-intent-tld-strip',
				sprintf(
					'body{--rintent-accent:%s;--rintent-accent-ink:%s;--rintent-accent-dark:%s;}',
					Reseller_Intent_Settings::accent_color(),
					Reseller_Intent_Settings::accent_ink_color(),
					Reseller_Intent_Settings::accent_dark_color()
				)
			);

			wp_enqueue_script(
				'rintent-admin-pages',
				$base_url . 'assets/js/admin-pages.js',
				array( 'jquery', 'wp-color-picker' ),
				filemtime( $base_path . 'assets/js/admin-pages.js' ),
				true
			);

			wp_localize_script(
				'rintent-admin-pages',
				'rintentPages',
				array(
					'copied'               => __( 'Copied', 'reseller-intent' ),
					'copyHint'             => __( 'Click to copy', 'reseller-intent' ),
					'ariaSupportLabel'     => __( 'Support entry label', 'reseller-intent' ),
					'ariaSupportNumber'    => __( 'Support phone number', 'reseller-intent' ),
					'ariaSupportCountries' => __( 'Country codes for this number', 'reseller-intent' ),
					'ariaRemoveRow'        => __( 'Remove row', 'reseller-intent' ),
				)
			);

			if ( 'reseller-intent_page_' . self::PAGE_SLUG . '-shortcodes' === $hook_suffix ) {
				wp_enqueue_script(
					'rintent-shortcodes-page',
					$base_url . 'assets/js/shortcodes-page.js',
					array(),
					filemtime( $base_path . 'assets/js/shortcodes-page.js' ),
					true
				);

				wp_localize_script(
					'rintent-shortcodes-page',
					'rintentGen',
					array(
						'copied'       => __( 'Copied!', 'reseller-intent' ),
						'emptyText'    => __( 'Nothing to show yet.', 'reseller-intent' ),
						'previewNonce' => wp_create_nonce( 'rintent_preview' ),
						'placeholders' => array(
							'min'   => array( __( 'Starting at ', 'reseller-intent' ), __( ' per year', 'reseller-intent' ) ),
							'max'   => array( __( 'Up to ', 'reseller-intent' ), __( ' per year', 'reseller-intent' ) ),
							'range' => array( __( 'Plans from ', 'reseller-intent' ), __( ' yearly', 'reseller-intent' ) ),
						),
					)
				);
			}
			return;
		}

		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'rintent-admin',
			$base_url . 'assets/css/admin.css',
			array(),
			filemtime( $base_path . 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'rintent-admin',
			$base_url . 'assets/js/admin.js',
			array( 'wp-element', 'wp-i18n' ),
			filemtime( $base_path . 'assets/js/admin.js' ),
			true
		);

		wp_set_script_translations( 'rintent-admin', 'reseller-intent' );

		wp_localize_script(
			'rintent-admin',
			'resellerIntentAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'rintent_dashboard_data' ),
				'version'     => Reseller_Intent::VERSION,
				'exportUrl'   => wp_nonce_url(
					add_query_arg( array( 'action' => 'rintent_export_csv' ), admin_url( 'admin-post.php' ) ),
					'rintent_export'
				),
				'clearUrl'    => admin_url( 'admin-post.php?action=rintent_clear_data' ),
				'actionNonce' => wp_create_nonce( 'rintent_admin_actions' ),
				'notice'      => isset( $_GET['rintent_notice'] ) ? sanitize_key( wp_unslash( $_GET['rintent_notice'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice slug from our own redirects.
				'tzLabel'     => wp_timezone_string(),
				'accentColor' => Reseller_Intent_Settings::accent_color(),
				'accentText'  => Reseller_Intent_Settings::accent_text_color(),
			)
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		Reseller_Intent_DB::ensure_table();

		echo '<div class="wrap rintent-wrap"><div id="rintent-root"></div></div>';
	}

	/**
	 * Single data endpoint: everything the dashboard shows, for one range.
	 */
	public function ajax_dashboard_data() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'rintent_dashboard_data', 'nonce' );

		$range_key = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '90';
		if ( ! in_array( $range_key, array( '7', '30', '90', 'all', 'custom' ), true ) ) {
			$range_key = '90';
		}

		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';

		if ( 'custom' === $range_key && ! self::valid_custom_range( $from, $to ) ) {
			$range_key = '90';
		}

		wp_send_json_success( $this->get_dashboard_data( $range_key, $from, $to ) );
	}

	/**
	 * @return bool True when $from/$to are valid Y-m-d, ordered, max 2 years.
	 */
	public static function valid_custom_range( $from, $to ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
			return false;
		}

		$from_ts = strtotime( $from . ' 00:00:00' );
		$to_ts   = strtotime( $to . ' 00:00:00' );

		return $from_ts && $to_ts && $from_ts <= $to_ts && ( $to_ts - $from_ts ) <= 2 * YEAR_IN_SECONDS;
	}

	/**
	 * Live preview for the shortcode builders: attrs come in structured
	 * and whitelisted, the shortcode string is built server-side and
	 * rendered with the site's real data, never from raw user markup.
	 */
	public function ajax_preview_shortcode() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'rintent_preview', 'nonce' );

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		switch ( $type ) {
			case 'tld':
				$tlds  = isset( $_POST['tlds'] ) ? sanitize_text_field( wp_unslash( $_POST['tlds'] ) ) : '';
				$theme = ( isset( $_POST['theme'] ) && 'dark' === sanitize_key( wp_unslash( $_POST['theme'] ) ) ) ? 'dark' : 'light';
				$label = isset( $_POST['more_label'] ) ? sanitize_text_field( wp_unslash( $_POST['more_label'] ) ) : '';
				$url   = isset( $_POST['more_url'] ) ? esc_url_raw( wp_unslash( $_POST['more_url'] ) ) : '';
				$html  = do_shortcode(
					sprintf(
						'[rintent_tld_strip tlds="%s" theme="%s" more_url="%s" more_label="%s"]',
						esc_attr( $tlds ),
						esc_attr( $theme ),
						esc_attr( $url ),
						esc_attr( $label )
					)
				);
				break;

			case 'price':
				$ids    = isset( $_POST['ids'] ) ? implode( ',', wp_parse_id_list( wp_unslash( $_POST['ids'] ) ) ) : '';
				$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'min';
		$mode   = in_array( $mode, array( 'min', 'max', 'range' ), true ) ? $mode : 'min';
				$before = isset( $_POST['before'] ) ? sanitize_text_field( wp_unslash( $_POST['before'] ) ) : '';
				$after  = isset( $_POST['after'] ) ? sanitize_text_field( wp_unslash( $_POST['after'] ) ) : '';
				$sep    = isset( $_POST['separator'] ) ? sanitize_text_field( wp_unslash( $_POST['separator'] ) ) : '';
				$fall   = isset( $_POST['fallback'] ) ? sanitize_text_field( wp_unslash( $_POST['fallback'] ) ) : '';
				$html   = do_shortcode(
					sprintf(
						'[rintent_price ids="%s" mode="%s" before="%s" after="%s" separator="%s" fallback="%s"]',
						esc_attr( $ids ),
						esc_attr( $mode ),
						esc_attr( $before ),
						esc_attr( $after ),
						esc_attr( '' !== $sep ? $sep : ' to ' ),
						esc_attr( $fall )
					)
				);
				break;

			case 'phone':
				$html = do_shortcode( '[rintent_phone]' );
				break;

			default:
				wp_send_json_error( array( 'message' => 'Unknown type' ), 400 );
		}

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Start and exclusive end for a range, as plain values. Every query
	 * carries the same literal "created_at >= %s AND created_at < %s"
	 * condition and passes these two through $wpdb->prepare(), so no SQL
	 * fragment is ever built from a variable. Open-ended sides use the
	 * epoch and a far-future date.
	 */
	private function range_bounds( $range_key, $from = '', $to = '' ) {
		if ( 'custom' === $range_key ) {
			return array( $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' );
		}

		if ( 'all' === $range_key ) {
			return array( self::RANGE_MIN, self::RANGE_MAX );
		}

		$len = (int) $range_key;

		return array( wp_date( 'Y-m-d 00:00:00', time() - ( ( $len - 1 ) * DAY_IN_SECONDS ) ), self::RANGE_MAX );
	}

	/**
	 * Deeper rows for one list panel: the dashboard ships the top slice,
	 * Show more pages the rest 25 at a time so Lifetime views can reach
	 * every row without a giant initial payload.
	 */
	public function ajax_panel_rows() {
		global $wpdb;

		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'rintent_dashboard_data', 'nonce' );

		$panel     = isset( $_POST['panel'] ) ? sanitize_key( wp_unslash( $_POST['panel'] ) ) : '';
		$range_key = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '90';
		$from      = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to        = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$offset    = isset( $_POST['offset'] ) ? min( 10000, absint( $_POST['offset'] ) ) : 0;
		$limit     = 25;

		if ( ! in_array( $range_key, array( '7', '30', '90', 'all', 'custom' ), true ) ) {
			$range_key = '90';
		}
		if ( 'custom' === $range_key && ! self::valid_custom_range( $from, $to ) ) {
			$range_key = '90';
		}

		list( $range_start, $range_end ) = $this->range_bounds( $range_key, $from, $to );

		$table_name = Reseller_Intent_DB::table_name();
		$fetch      = $limit + 1;
		$items      = array();

		switch ( $panel ) {
			case 'tlds':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(SUBSTRING_INDEX(domain_query, '.', -1)) AS tld, SUM(event_count) AS hits FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query LIKE %s AND created_at >= %s AND created_at < %s GROUP BY tld ORDER BY hits DESC LIMIT %d OFFSET %d", '%' . $wpdb->esc_like( '.' ) . '%', $range_start, $range_end, $fetch, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $rows as $row ) {
					$items[] = array(
						'label' => '.' . (string) $row->tld,
						'count' => (int) $row->hits,
					);
				}
				break;

			case 'repeats':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT domain_query AS domain, SUM(event_count) AS hits FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s GROUP BY domain_query HAVING hits >= 2 ORDER BY hits DESC LIMIT %d OFFSET %d", $range_start, $range_end, $fetch, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $rows as $row ) {
					$items[] = array(
						'domain' => self::display_domain( $row->domain ),
						'hits'   => (int) $row->hits,
					);
				}
				break;

			case 'selection_top':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT domain_query AS domain, SUM(event_count) AS hits FROM {$table_name} WHERE event_type = 'domain_select' AND domain_query <> '' AND created_at >= %s AND created_at < %s GROUP BY domain_query ORDER BY hits DESC LIMIT %d OFFSET %d", $range_start, $range_end, $fetch, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $rows as $row ) {
					$items[] = array(
						'domain' => self::display_domain( $row->domain ),
						'hits'   => (int) $row->hits,
					);
				}
				break;

			case 'selection_pairs':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT related_query AS searched, domain_query AS selected, COUNT(*) AS hits FROM {$table_name} WHERE event_type = 'domain_select' AND domain_query <> '' AND related_query <> '' AND related_query <> domain_query AND domain_query NOT LIKE CONCAT(related_query, '.%') AND created_at >= %s AND created_at < %s GROUP BY related_query, domain_query ORDER BY hits DESC LIMIT %d OFFSET %d", $range_start, $range_end, $fetch, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- wildcard is part of a CONCAT against a column, not user input.
				foreach ( $rows as $row ) {
					$items[] = array(
						'searched' => self::display_domain( $row->searched ),
						'selected' => self::display_domain( $row->selected ),
						'hits'     => (int) $row->hits,
					);
				}
				break;

			case 'countries':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT country, COALESCE(SUM(event_count),0) AS hits FROM {$table_name} WHERE event_type = 'domain_search' AND country <> '' AND created_at >= %s AND created_at < %s GROUP BY country ORDER BY hits DESC LIMIT %d OFFSET %d", $range_start, $range_end, $fetch, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $rows as $row ) {
					$items[] = array(
						'code'  => (string) $row->country,
						'count' => (int) $row->hits,
					);
				}
				break;

			case 'carted':
				// Aggregated from items_json, so paging slices the aggregate.
				$json_rows     = $wpdb->get_results( $wpdb->prepare( "SELECT items_json FROM {$table_name} WHERE event_type = 'continue_to_cart' AND items_json IS NOT NULL AND items_json <> '' AND created_at >= %s AND created_at < %s ORDER BY id DESC LIMIT 2000", $range_start, $range_end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$domain_counts = array();
				foreach ( $json_rows as $json_row ) {
					foreach ( $this->extract_carted_domains( (string) $json_row->items_json ) as $domain ) {
						$domain_counts[ $domain ] = isset( $domain_counts[ $domain ] ) ? $domain_counts[ $domain ] + 1 : 1;
					}
				}
				arsort( $domain_counts );
				foreach ( array_slice( $domain_counts, $offset, $fetch, true ) as $domain => $count ) {
					$items[] = array(
						'domain' => self::display_domain( $domain ),
						'count'  => (int) $count,
					);
				}
				break;

			case 'opportunities':
				$carted    = array();
				$json_rows = $wpdb->get_results( "SELECT items_json FROM {$table_name} WHERE event_type = 'continue_to_cart' AND items_json IS NOT NULL AND items_json <> '' ORDER BY id DESC LIMIT 800" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $json_rows as $json_row ) {
					foreach ( $this->extract_carted_domains( (string) $json_row->items_json ) as $domain ) {
						$carted[ strtolower( $domain ) ] = true;
					}
				}
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT domain_query, COALESCE(SUM(event_count),0) AS hits, MAX(created_at) AS last_seen FROM {$table_name} WHERE event_type = 'domain_search' AND is_available = 1 AND domain_query <> '' AND created_at >= %s AND created_at < %s GROUP BY domain_query ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d", $range_start, $range_end, $fetch + 60, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $rows as $row ) {
					if ( isset( $carted[ strtolower( (string) $row->domain_query ) ] ) ) {
						continue;
					}
					$items[] = array(
						'domain' => self::display_domain( $row->domain_query ),
						'count'  => (int) $row->hits,
						'last'   => sprintf(
							/* translators: %s: human readable time difference */
							__( '%s ago', 'reseller-intent' ),
							human_time_diff( (int) strtotime( (string) $row->last_seen ), strtotime( current_time( 'mysql' ) ) )
						),
					);
					if ( count( $items ) > $limit ) {
						break;
					}
				}
				break;

			default:
				wp_send_json_error( array( 'message' => 'Unknown panel' ), 400 );
		}

		$has_more = count( $items ) > $limit;

		wp_send_json_success(
			array(
				'items'   => array_slice( $items, 0, $limit ),
				'hasMore' => $has_more,
			)
		);
	}

	private function get_dashboard_data( $range_key, $from = '', $to = '' ) {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();

		$bounded = ( 'all' !== $range_key );
		$custom  = ( 'custom' === $range_key );
		$now_ts  = time(); // NOT current_time(): wp_date() adds the site offset itself; both = double shift after 18:30 IST.
		$end     = ''; // Exclusive upper bound, custom range only.

		if ( $custom ) {
			// Dates are site-local calendar days; created_at is stored site-local.
			$len        = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
			$start      = $from . ' 00:00:00';
			$end        = gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00';
			$prev_start = gmdate( 'Y-m-d', strtotime( $from . ' -' . $len . ' days' ) ) . ' 00:00:00';
			$anchor_ts  = ( new DateTimeImmutable( $to . ' 12:00:00', wp_timezone() ) )->getTimestamp();
			$range_start = $start;
			$range_end   = $end;
		} else {
			$len        = $bounded ? (int) $range_key : 0;
			$start      = $bounded ? wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( $len - 1 ) * DAY_IN_SECONDS ) ) : '';
			$prev_start = $bounded ? wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( ( 2 * $len ) - 1 ) * DAY_IN_SECONDS ) ) : '';
			$anchor_ts  = $now_ts;
			$range_start = $bounded ? $start : self::RANGE_MIN;
			$range_end   = self::RANGE_MAX;
		}

		// KPIs: one aggregate query per window.
		$kpi_now  = $this->get_kpi_counts( $table_name, $start, $end );
		$kpi_prev = $bounded ? $this->get_kpi_counts( $table_name, $prev_start, $start ) : null;

		// TLD distribution.
		$tld_rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(SUBSTRING_INDEX(domain_query, '.', -1)) AS tld, SUM(event_count) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query LIKE %s AND created_at >= %s AND created_at < %s
				GROUP BY tld
				ORDER BY hits DESC
				LIMIT 40",
				'%' . $wpdb->esc_like( '.' ) . '%',
				$range_start,
				$range_end
			)
		);
		$tlds      = array();
		$tld_total = 0;
		$others    = 0;
		foreach ( $tld_rows as $i => $tld_row ) {
			$hits       = (int) $tld_row->hits;
			$tld_total += $hits;
			if ( $i < 12 ) {
				$tlds[] = array(
					'label' => '.' . sanitize_key( (string) $tld_row->tld ),
					'count' => $hits,
				);
			} else {
				$others += $hits;
			}
		}
		if ( $others > 0 ) {
			$tlds[] = array(
				'label' => __( 'Others', 'reseller-intent' ),
				'count' => $others,
			);
		}

		// Trend: daily for bounded ranges, monthly for lifetime.
		$trend = $this->get_trend_series( $table_name, $bounded, $len, $anchor_ts );

		// Cart size buckets.
		$cart_row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN items_count <= 1 THEN 1 ELSE 0 END),0) AS b1,
					COALESCE(SUM(CASE WHEN items_count = 2 THEN 1 ELSE 0 END),0) AS b2,
					COALESCE(SUM(CASE WHEN items_count = 3 THEN 1 ELSE 0 END),0) AS b3,
					COALESCE(SUM(CASE WHEN items_count >= 4 THEN 1 ELSE 0 END),0) AS b4,
					COUNT(*) AS total
				FROM {$table_name}
				WHERE event_type = 'continue_to_cart' AND created_at >= %s AND created_at < %s",
				$range_start, $range_end
			),
			ARRAY_A
		);
		$cart_row   = is_array( $cart_row ) ? array_map( 'intval', $cart_row ) : array();
		$cart_sizes = array(
			array(
				'label' => __( '1 domain', 'reseller-intent' ),
				'count' => isset( $cart_row['b1'] ) ? $cart_row['b1'] : 0,
			),
			array(
				'label' => __( '2 domains', 'reseller-intent' ),
				'count' => isset( $cart_row['b2'] ) ? $cart_row['b2'] : 0,
			),
			array(
				'label' => __( '3 domains', 'reseller-intent' ),
				'count' => isset( $cart_row['b3'] ) ? $cart_row['b3'] : 0,
			),
			array(
				'label' => __( '4+ domains', 'reseller-intent' ),
				'count' => isset( $cart_row['b4'] ) ? $cart_row['b4'] : 0,
			),
		);

		// Repeat intent: searched 2+ times inside the window.
		$repeat_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query AS domain, SUM(event_count) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s
				GROUP BY domain_query
				HAVING hits >= 2
				ORDER BY hits DESC
				LIMIT 25",
				$range_start, $range_end
			)
		);
		$repeats     = array();
		foreach ( $repeat_rows as $repeat_row ) {
			$repeats[] = array(
				'domain' => self::display_domain( $repeat_row->domain ),
				'hits'   => (int) $repeat_row->hits,
			);
		}

		// Searches and cart clicks per source page.
		$pages = $this->get_page_breakdown( $table_name, $range_start, $range_end );

		// Carted domains from items_json (bounded scan).
		$carted = $this->get_carted_breakdown( $table_name, $range_start, $range_end );

		// Demand signals: searched, available, never taken to cart.
		$opportunities = $this->get_opportunities( $table_name, $range_start, $range_end );

		// Selection behavior.
		$selection = $this->get_selection_breakdown( $table_name, $range_start, $range_end );

		// Availability + devices.
		$availability_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN is_available = 1 THEN event_count ELSE 0 END),0) AS avail,
					COALESCE(SUM(CASE WHEN is_available = 0 THEN event_count ELSE 0 END),0) AS taken
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND is_available IS NOT NULL AND created_at >= %s AND created_at < %s",
				$range_start, $range_end
			),
			ARRAY_A
		);
		$avail            = isset( $availability_row['avail'] ) ? (int) $availability_row['avail'] : 0;
		$taken            = isset( $availability_row['taken'] ) ? (int) $availability_row['taken'] : 0;

		$device_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT device, COALESCE(SUM(event_count),0) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND device IN ('mobile','tablet','desktop') AND created_at >= %s AND created_at < %s
				GROUP BY device",
				$range_start, $range_end
			),
			ARRAY_A
		);
		$devices     = array(
			'mobile'  => 0,
			'tablet'  => 0,
			'desktop' => 0,
		);
		foreach ( (array) $device_rows as $device_row ) {
			$device_key = isset( $device_row['device'] ) ? (string) $device_row['device'] : '';
			if ( isset( $devices[ $device_key ] ) ) {
				$devices[ $device_key ] = (int) $device_row['hits'];
			}
		}

		// Top countries (privacy-safe: 2-letter geo header codes, no IPs).
		$country_rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, COALESCE(SUM(event_count),0) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND country <> '' AND created_at >= %s AND created_at < %s
				GROUP BY country
				ORDER BY hits DESC
				LIMIT 20",
				$range_start, $range_end
			),
			ARRAY_A
		);
		$countries       = array();
		$countries_total = 0;
		foreach ( (array) $country_rows as $country_row ) {
			$hits             = isset( $country_row['hits'] ) ? (int) $country_row['hits'] : 0;
			$countries_total += $hits;
			$countries[]      = array(
				'code' => isset( $country_row['country'] ) ? (string) $country_row['country'] : '',
				'hits' => $hits,
			);
		}

		// Recent search log: latest 100 in range; filtered/paged client-side.
		$recent_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query, created_at, is_available, device
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s
				ORDER BY id DESC
				LIMIT 100",
				$range_start, $range_end
			)
		);
		$recent      = array();
		foreach ( $recent_rows as $recent_row ) {
			$recent[] = array(
				'domain'    => self::display_domain( $recent_row->domain_query ),
				'time'      => $this->format_datetime_local( (string) $recent_row->created_at ),
				'available' => ( null === $recent_row->is_available || '' === (string) $recent_row->is_available ) ? null : (bool) (int) $recent_row->is_available,
				'device'    => (string) $recent_row->device,
			);
		}

		// Tracking health: time since the newest event, any range. Surfaces
		// silent breakage (JS error, markup drift, blocked AJAX) at a glance.
		$last_event_at = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// created_at is stored in site-local time, so diff against local now.
		$last_event_ts  = $last_event_at ? (int) strtotime( $last_event_at ) : 0;
		$last_event_age = $last_event_ts ? max( 0, strtotime( current_time( 'mysql' ) ) - $last_event_ts ) : 0;

		// KPI sparklines: fixed last-7-days daily counts, range-independent.
		$spark_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day,
				SUM(CASE WHEN event_type = 'domain_search' THEN event_count ELSE 0 END) AS searches,
				COUNT(DISTINCT CASE WHEN event_type = 'domain_search' AND domain_query <> '' THEN domain_query END) AS uniques,
				SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END) AS carts,
				SUM(CASE WHEN event_type = 'continue_to_cart' THEN items_count ELSE 0 END) AS added
			FROM {$table_name} WHERE created_at >= %s GROUP BY day ORDER BY day ASC",
				wp_date( 'Y-m-d 00:00:00', $now_ts - ( 6 * DAY_IN_SECONDS ) )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sparks     = array(
			'searches' => array(),
			'uniques'  => array(),
			'carts'    => array(),
			'added'    => array(),
		);
		$by_day     = array();
		foreach ( (array) $spark_rows as $spark_row ) {
			$by_day[ $spark_row['day'] ] = $spark_row;
		}
		for ( $d = 6; $d >= 0; $d-- ) {
			$day                  = wp_date( 'Y-m-d', $now_ts - ( $d * DAY_IN_SECONDS ) );
			$sparks['searches'][] = isset( $by_day[ $day ] ) ? (int) $by_day[ $day ]['searches'] : 0;
			$sparks['uniques'][]  = isset( $by_day[ $day ] ) ? (int) $by_day[ $day ]['uniques'] : 0;
			$sparks['carts'][]    = isset( $by_day[ $day ] ) ? (int) $by_day[ $day ]['carts'] : 0;
			$sparks['added'][]    = isset( $by_day[ $day ] ) ? (int) $by_day[ $day ]['added'] : 0;
		}

		return array(
			'range'         => $range_key,
			'bounded'       => $bounded,
			'sparks'        => $sparks,
			'lastEvent'     => array(
				'ago'   => $last_event_ts
					/* translators: %s: human readable time difference */
					? sprintf( __( 'Last event %s ago', 'reseller-intent' ), human_time_diff( $last_event_ts, strtotime( current_time( 'mysql' ) ) ) )
					: __( 'No events yet', 'reseller-intent' ),
				'stale' => $last_event_ts ? ( $last_event_age > 3 * DAY_IN_SECONDS ) : false,
			),
			'rangeLabel'    => $custom
				? sprintf( '%s to %s', wp_date( 'M j, Y', strtotime( $from . ' 12:00:00' ) ), wp_date( 'M j, Y', strtotime( $to . ' 12:00:00' ) ) )
				: ( $bounded
					/* translators: %d: number of days */
					? sprintf( __( 'Last %d days', 'reseller-intent' ), $len )
					: __( 'Lifetime', 'reseller-intent' ) ),
			'kpis'          => array(
				'now'  => $kpi_now,
				'prev' => $kpi_prev,
			),
			'tlds'          => array(
				'items' => $tlds,
				'total' => $tld_total,
			),
			'trend'         => $trend,
			'cartSizes'     => $cart_sizes,
			'repeats'       => $repeats,
			'pages'         => $pages,
			'carted'        => $carted,
			'opportunities' => $opportunities['items'],
			'totals'        => array(
				'tlds'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT LOWER(SUBSTRING_INDEX(domain_query, '.', -1))) FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query LIKE %s AND created_at >= %s AND created_at < %s", '%' . $wpdb->esc_like( '.' ) . '%', $range_start, $range_end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'repeats'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT 1 FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s GROUP BY domain_query HAVING SUM(event_count) >= 2) grouped", $range_start, $range_end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'selectionTop'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT domain_query) FROM {$table_name} WHERE event_type = 'domain_select' AND domain_query <> '' AND created_at >= %s AND created_at < %s", $range_start, $range_end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'selectionPairs' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT 1 FROM {$table_name} WHERE event_type = 'domain_select' AND domain_query <> '' AND related_query <> '' AND related_query <> domain_query AND domain_query NOT LIKE CONCAT(related_query, '.', %s) AND created_at >= %s AND created_at < %s GROUP BY related_query, domain_query) grouped", '%', $range_start, $range_end ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'carted'         => (int) $carted['total'],
				'opportunities'  => (int) $opportunities['total'],
			),
			'selection'     => $selection,
			'availability'  => array(
				'available' => $avail,
				'taken'     => $taken,
			),
			'devices'       => $devices,
			'countries'     => array(
				'items' => $countries,
				'total' => $countries_total,
			),
			'recent'        => $recent,
		);
	}

	private function get_trend_series( $table_name, $bounded, $len, $now_ts ) {
		global $wpdb;

		if ( $bounded ) {
			$start    = wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( $len - 1 ) * DAY_IN_SECONDS ) );
			$rows     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE(created_at) AS bucket,
						COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN event_count ELSE 0 END),0) AS searches,
						COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts
					FROM {$table_name}
					WHERE created_at >= %s
					GROUP BY DATE(created_at)",
					$start
				),
				OBJECT_K
			);
			$labels   = array();
			$searches = array();
			$carts    = array();
			for ( $i = $len - 1; $i >= 0; $i-- ) {
				$ts         = $now_ts - ( $i * DAY_IN_SECONDS );
				$key        = wp_date( 'Y-m-d', $ts );
				$labels[]   = wp_date( 'M j', $ts );
				$searches[] = isset( $rows[ $key ] ) ? (int) $rows[ $key ]->searches : 0;
				$carts[]    = isset( $rows[ $key ] ) ? (int) $rows[ $key ]->carts : 0;
			}

			return array(
				'labels'   => $labels,
				'searches' => $searches,
				'carts'    => $carts,
			);
		}

		// Lifetime: monthly buckets, capped at the last 24 months of data.
		$rows     = $wpdb->get_results(
			"SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket,
				COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN event_count ELSE 0 END),0) AS searches,
				COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts
			FROM {$table_name}
			GROUP BY bucket
			ORDER BY bucket ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$rows     = array_slice( (array) $rows, -24 );
		$labels   = array();
		$searches = array();
		$carts    = array();
		foreach ( $rows as $row ) {
			$month_ts   = strtotime( (string) $row->bucket . '-01' );
			$labels[]   = $month_ts ? wp_date( 'M Y', $month_ts ) : (string) $row->bucket;
			$searches[] = (int) $row->searches;
			$carts[]    = (int) $row->carts;
		}

		return array(
			'labels'   => $labels,
			'searches' => $searches,
			'carts'    => $carts,
		);
	}

	/**
	 * Searches and cart clicks grouped by the page the widget sits on.
	 *
	 * page_url stores the full URL (query strings included), so rows are
	 * grouped in SQL first, then merged by normalized path here, UTM
	 * variants of the same page collapse into one row.
	 */
	private function get_page_breakdown( $table_name, $range_start, $range_end ) {
		global $wpdb;

		$page_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_url,
					COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN event_count ELSE 0 END),0) AS searches,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts
				FROM {$table_name}
				WHERE event_type IN ('domain_search','continue_to_cart') AND page_url IS NOT NULL AND page_url <> '' AND created_at >= %s AND created_at < %s
				GROUP BY page_url
				ORDER BY searches DESC
				LIMIT 200",
				$range_start, $range_end
			)
		);

		$by_path = array();
		foreach ( (array) $page_rows as $page_row ) {
			$path = $this->normalize_page_path( (string) $page_row->page_url );
			if ( '' === $path ) {
				continue;
			}
			if ( ! isset( $by_path[ $path ] ) ) {
				$by_path[ $path ] = array(
					'path'     => $path,
					'searches' => 0,
					'carts'    => 0,
				);
			}
			$by_path[ $path ]['searches'] += (int) $page_row->searches;
			$by_path[ $path ]['carts']    += (int) $page_row->carts;
		}

		usort(
			$by_path,
			static function ( $a, $b ) {
				return $b['searches'] <=> $a['searches'];
			}
		);

		$top = array_slice( array_values( $by_path ), 0, 8 );
		foreach ( $top as &$page ) {
			$page['label'] = $this->page_label_for_path( $page['path'] );
		}
		unset( $page );

		return $top;
	}

	/**
	 * Human label for a path: the page/post title when the path resolves
	 * to content, otherwise the path itself. Works for any page the
	 * search widget gets dropped on.
	 */
	private function page_label_for_path( $path ) {
		if ( '/' === $path ) {
			return __( 'Home', 'reseller-intent' );
		}

		$post_id = url_to_postid( home_url( $path ) );
		if ( $post_id > 0 ) {
			$title = get_the_title( $post_id );
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				return trim( wp_strip_all_tags( $title ) );
			}
		}

		return $path;
	}

	private function normalize_page_path( $page_url ) {
		$path = wp_parse_url( $page_url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$path = strtolower( $path );
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path ) . '/';
		}

		return substr( $path, 0, 191 );
	}

	/**
	 * Domains that were searched, came back available, and never appeared
	 * in any cart. The carted set is checked against recent history as a
	 * whole (not just the selected range), a domain carted last month is
	 * not an opportunity today.
	 */
	private function get_opportunities( $table_name, $range_start, $range_end ) {
		global $wpdb;

		$carted = array();

		$json_rows = $wpdb->get_results(
			"SELECT items_json FROM {$table_name}
			WHERE event_type = 'continue_to_cart' AND items_json IS NOT NULL AND items_json <> ''
			ORDER BY id DESC
			LIMIT 800" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		foreach ( $json_rows as $json_row ) {
			foreach ( $this->extract_carted_domains( (string) $json_row->items_json ) as $domain ) {
				$carted[ strtolower( $domain ) ] = true;
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query, COALESCE(SUM(event_count),0) AS hits, MAX(created_at) AS last_seen
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND is_available = 1 AND domain_query <> '' AND created_at >= %s AND created_at < %s
				GROUP BY domain_query
				ORDER BY hits DESC, last_seen DESC
				LIMIT 40",
				$range_start, $range_end
			)
		);

		$distinct_avail = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT domain_query) FROM {$table_name} WHERE event_type = 'domain_search' AND is_available = 1 AND domain_query <> '' AND created_at >= %s AND created_at < %s", $range_start, $range_end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$overlap        = 0;
		if ( ! empty( $carted ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $carted ), '%s' ) );
			$overlap      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT domain_query) FROM {$table_name} WHERE event_type = 'domain_search' AND is_available = 1 AND LOWER(domain_query) IN ({$placeholders}) AND created_at >= %s AND created_at < %s", array_merge( array_keys( $carted ), array( $range_start, $range_end ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are built dynamically for the IN list.
		}

		$items = array();

		foreach ( $rows as $row ) {
			$domain = (string) $row->domain_query;

			if ( isset( $carted[ strtolower( $domain ) ] ) ) {
				continue;
			}

			$items[] = array(
				'domain' => self::display_domain( $domain ),
				'count'  => (int) $row->hits,
				'last'   => sprintf(
					/* translators: %s: human readable time difference */
					__( '%s ago', 'reseller-intent' ),
					human_time_diff( (int) strtotime( (string) $row->last_seen ), strtotime( current_time( 'mysql' ) ) )
				),
			);

			if ( count( $items ) >= 15 ) {
				break;
			}
		}

		return array(
			'items' => $items,
			'total' => max( 0, $distinct_avail - $overlap ),
		);
	}

	private function get_carted_breakdown( $table_name, $range_start, $range_end ) {
		global $wpdb;

		$json_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT items_json FROM {$table_name}
				WHERE event_type = 'continue_to_cart' AND items_json IS NOT NULL AND items_json <> '' AND created_at >= %s AND created_at < %s
				ORDER BY id DESC
				LIMIT 500",
				$range_start, $range_end
			)
		);

		$domain_counts = array();
		foreach ( $json_rows as $json_row ) {
			foreach ( $this->extract_carted_domains( (string) $json_row->items_json ) as $domain ) {
				$domain_counts[ $domain ] = isset( $domain_counts[ $domain ] ) ? $domain_counts[ $domain ] + 1 : 1;
			}
		}
		arsort( $domain_counts );

		$tld_counts = array();
		foreach ( $domain_counts as $domain => $count ) {
			$dot = strrpos( $domain, '.' );
			if ( false === $dot ) {
				continue;
			}
			$tld                = '.' . substr( $domain, $dot + 1 );
			$tld_counts[ $tld ] = isset( $tld_counts[ $tld ] ) ? $tld_counts[ $tld ] + $count : $count;
		}
		arsort( $tld_counts );

		$domains = array();
		foreach ( array_slice( $domain_counts, 0, 15, true ) as $domain => $count ) {
			$domains[] = array(
				'domain' => self::display_domain( $domain ),
				'count'  => (int) $count,
			);
		}
		$tlds = array();
		foreach ( array_slice( $tld_counts, 0, 6, true ) as $tld => $count ) {
			$tlds[] = array(
				'label' => (string) $tld,
				'count' => (int) $count,
			);
		}

		return array(
			'domains' => $domains,
			'tlds'    => $tlds,
			'total'   => count( $domain_counts ),
		);
	}

	private function get_selection_breakdown( $table_name, $range_start, $range_end ) {
		global $wpdb;

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					COALESCE(SUM(CASE WHEN related_query <> '' AND ( related_query = domain_query OR domain_query LIKE CONCAT(related_query, '.', %s) ) THEN 1 ELSE 0 END),0) AS exact_hits
				FROM {$table_name}
				WHERE event_type = 'domain_select' AND domain_query <> '' AND created_at >= %s AND created_at < %s",
				'%',
				$range_start,
				$range_end
			),
			ARRAY_A
		);
		$total  = isset( $totals['total'] ) ? (int) $totals['total'] : 0;
		$exact  = isset( $totals['exact_hits'] ) ? (int) $totals['exact_hits'] : 0;

		$top_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query AS domain, SUM(event_count) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_select' AND domain_query <> '' AND created_at >= %s AND created_at < %s
				GROUP BY domain_query
				ORDER BY hits DESC
				LIMIT 25",
				$range_start, $range_end
			)
		);
		$top      = array();
		foreach ( $top_rows as $top_row ) {
			$top[] = array(
				'domain' => self::display_domain( $top_row->domain ),
				'hits'   => (int) $top_row->hits,
			);
		}

		$pair_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT related_query AS searched, domain_query AS selected, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_select' AND domain_query <> '' AND related_query <> ''
					AND related_query <> domain_query AND domain_query NOT LIKE CONCAT(related_query, '.', %s) AND created_at >= %s AND created_at < %s
				GROUP BY related_query, domain_query
				ORDER BY hits DESC
				LIMIT 25",
				'%',
				$range_start,
				$range_end
			)
		);
		$pairs     = array();
		foreach ( $pair_rows as $pair_row ) {
			$pairs[] = array(
				'searched' => self::display_domain( $pair_row->searched ),
				'selected' => self::display_domain( $pair_row->selected ),
				'hits'     => (int) $pair_row->hits,
			);
		}

		return array(
			'total' => $total,
			'exact' => $exact,
			'top'   => $top,
			'pairs' => $pairs,
		);
	}

	/**
	 * Aggregate KPI counts for a window. Empty bounds mean open ended.
	 */
	private function get_kpi_counts( $table_name, $start = '', $end = '' ) {
		global $wpdb;

		$start = '' !== $start ? $start : self::RANGE_MIN;
		$end   = '' !== $end ? $end : self::RANGE_MAX;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN event_count ELSE 0 END),0) AS searches,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS cart_clicks,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN items_count ELSE 0 END),0) AS domains_added,
					COUNT(DISTINCT CASE WHEN event_type = 'domain_search' AND domain_query <> '' THEN domain_query END) AS unique_searches
				FROM {$table_name}
				WHERE created_at >= %s AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefixed table name.
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'searches'       => isset( $row['searches'] ) ? (int) $row['searches'] : 0,
			'cartClicks'     => isset( $row['cart_clicks'] ) ? (int) $row['cart_clicks'] : 0,
			'domainsAdded'   => isset( $row['domains_added'] ) ? (int) $row['domains_added'] : 0,
			'uniqueSearches' => isset( $row['unique_searches'] ) ? (int) $row['unique_searches'] : 0,
		);
	}

	/**
	 * Pull domain names out of a continue_to_cart items_json payload.
	 */
	private function extract_carted_domains( $items_json ) {
		$decoded = json_decode( $items_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$domains = array();
		foreach ( $decoded as $entry ) {
			$candidate = '';
			if ( is_string( $entry ) ) {
				$candidate = $entry;
			} elseif ( is_array( $entry ) ) {
				foreach ( array( 'domain', 'domainName', 'domain_name', 'name', 'label' ) as $entry_key ) {
					if ( isset( $entry[ $entry_key ] ) && is_string( $entry[ $entry_key ] ) ) {
						$candidate = $entry[ $entry_key ];
						break;
					}
				}
			}

			$candidate = strtolower( trim( (string) $candidate ) );
			if ( '' === $candidate || strlen( $candidate ) > 191 ) {
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9][a-z0-9.-]*$/', $candidate ) ) {
				continue;
			}
			$domains[] = $candidate;
		}

		return $domains;
	}

	public function handle_export_domain_searches() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to export data.', 'reseller-intent' ), 403 );
		}
		check_admin_referer( 'rintent_export' );

		$range_key = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'all';
		if ( ! in_array( $range_key, array( '7', '30', '90', 'all', 'custom' ), true ) ) {
			$range_key = 'all';
		}

		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		if ( 'custom' === $range_key && ! self::valid_custom_range( $from, $to ) ) {
			$range_key = 'all';
		}

		$format = isset( $_GET['format'] ) && 'json' === sanitize_key( wp_unslash( $_GET['format'] ) ) ? 'json' : 'csv';

		$this->export_domain_search_csv( Reseller_Intent_DB::table_name(), $range_key, $format, $from, $to );
	}

	/**
	 * Browser-style "clear data" ranges, shared by preview and delete.
	 *
	 * @return array<string,int> range key => seconds (0 = all time).
	 */
	public static function clear_ranges() {
		return array(
			'hour'      => HOUR_IN_SECONDS,
			'day'       => DAY_IN_SECONDS,
			'week'      => WEEK_IN_SECONDS,
			'month'     => 30 * DAY_IN_SECONDS,
			'half_year' => 182 * DAY_IN_SECONDS,
			'year'      => 365 * DAY_IN_SECONDS,
			'all'       => 0,
		);
	}

	private function resolve_clear_range( $raw ) {
		$ranges = self::clear_ranges();
		$key    = sanitize_key( (string) $raw );

		return array_key_exists( $key, $ranges ) ? $ranges[ $key ] : null;
	}

	/**
	 * Preview endpoint: how many events would the selected range delete?
	 */
	public function ajax_clear_preview() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'rintent_admin_actions', 'nonce' );

		$seconds = $this->resolve_clear_range( isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '' );
		if ( null === $seconds ) {
			wp_send_json_error( array( 'message' => 'Invalid range' ), 400 );
		}

		wp_send_json_success( array( 'count' => Reseller_Intent_DB::count_events_since( $seconds ) ) );
	}

	public function handle_clear_data() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to clear data.', 'reseller-intent' ), 403 );
		}
		check_admin_referer( 'rintent_admin_actions' );

		$seconds = $this->resolve_clear_range( isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '' );
		$notice  = 'clear_error';

		if ( null !== $seconds ) {
			$deleted = Reseller_Intent_DB::delete_events_since( $seconds );
			$notice  = 'cleared_' . max( 0, (int) $deleted );
		}

		$page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		wp_safe_redirect( add_query_arg( 'rintent_notice', $notice, $page_url ) );
		exit;
	}

	private function format_datetime_local( $datetime_raw ) {
		$datetime_raw = trim( (string) $datetime_raw );
		if ( '' === $datetime_raw ) {
			return '';
		}

		// created_at is stored in the site's local timezone already.
		return substr( $datetime_raw, 0, 16 );
	}

	private function sanitize_csv_cell( $value ) {
		$value           = (string) $value;
		$trimmed_leading = ltrim( $value );
		if ( '' !== $trimmed_leading && preg_match( '/^[=\-+@]/', $trimmed_leading ) ) {
			return "'" . $value;
		}
		return $value;
	}

	private function export_domain_search_csv( $table_name, $range_key = 'all', $format = 'csv', $from = '', $to = '' ) {
		global $wpdb;

		list( $range_start, $range_end ) = $this->range_bounds( $range_key, $from, $to );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();

		$is_json  = ( 'json' === $format );
		$tz_label = wp_timezone_string();

		header( 'Content-Type: ' . ( $is_json ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reseller-intent-' . gmdate( 'Ymd-His' ) . ( $is_json ? '.json' : '.csv' ) . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming download response; WP_Filesystem cannot stream to php://output.
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		if ( $is_json ) {
			fwrite( $output, '{"generated":' . wp_json_encode( gmdate( 'c' ) ) . ',"timezone":' . wp_json_encode( $tz_label ) . ',"events":[' );
		} else {
			// UTF-8 BOM improves CSV compatibility with spreadsheet apps.
			fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
			fputcsv( $output, array( 'Event', 'Domain', 'Related Search', 'Items Count', 'Items', 'Available', 'Device', 'Country', 'Page URL', 'Time (' . $tz_label . ')' ) );
		}

		$json_first = true;

		/*
		 * Chunked export: batches keyed by id so memory stays flat no matter
		 * how large the events table grows.
		 */
		$last_id = PHP_INT_MAX;

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, event_type, domain_query, related_query, items_count, items_json, is_available, device, country, page_url, created_at
					FROM {$table_name}
					WHERE id < %d AND created_at >= %s AND created_at < %s
					ORDER BY id DESC
					LIMIT %d",
					$last_id, $range_start, $range_end, 5000
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$avail_cell = '';
				if ( null !== $row->is_available && '' !== (string) $row->is_available ) {
					$avail_cell = ( (int) $row->is_available ) ? 'yes' : 'no';
				}

				$items_cell = '';
				if ( 'continue_to_cart' === (string) $row->event_type && '' !== (string) $row->items_json ) {
					$items_cell = implode( ' ', $this->extract_carted_domains( (string) $row->items_json ) );
				}

				if ( $is_json ) {
					fwrite(
						$output,
						( $json_first ? '' : ',' ) . wp_json_encode(
							array(
								'event'     => (string) $row->event_type,
								'domain'    => (string) $row->domain_query,
								'related'   => (string) $row->related_query,
								'items'     => (int) $row->items_count,
								'carted'    => $items_cell,
								'available' => '' === $avail_cell ? null : ( 'yes' === $avail_cell ),
								'device'    => (string) $row->device,
								'country'   => (string) $row->country,
								'page'      => (string) $row->page_url,
								'time'      => $this->format_datetime_local( (string) $row->created_at ),
							)
						)
					);
					$json_first = false;
				} else {
					fputcsv(
						$output,
						array(
							$this->sanitize_csv_cell( (string) $row->event_type ),
							$this->sanitize_csv_cell( (string) $row->domain_query ),
							$this->sanitize_csv_cell( (string) $row->related_query ),
							(int) $row->items_count,
							$this->sanitize_csv_cell( $items_cell ),
							$avail_cell,
							$this->sanitize_csv_cell( (string) $row->device ),
							$this->sanitize_csv_cell( (string) $row->country ),
							$this->sanitize_csv_cell( (string) $row->page_url ),
							$this->sanitize_csv_cell( $this->format_datetime_local( (string) $row->created_at ) ),
						)
					);
				}

				$last_id = (int) $row->id;
			}

			flush();
		}

		if ( $is_json ) {
			fwrite( $output, ']}' );
		}

		fclose( $output );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * WP Dashboard "At a Glance"-style widget: today + 7 days.
	 */
	public function register_glance_widget() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'rintent_glance',
			__( 'Reseller Intent', 'reseller-intent' ),
			array( $this, 'render_glance_widget' )
		);
	}

	public function render_glance_widget() {
		global $wpdb;

		$table_name  = Reseller_Intent_DB::table_name();
		$today_start = wp_date( 'Y-m-d 00:00:00' );
		$week_start  = wp_date( 'Y-m-d 00:00:00', time() - ( 6 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN event_type = 'domain_search' AND created_at >= %s THEN 1 ELSE 0 END) AS searches_today,
					SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END) AS searches_week,
					SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END) AS carts_week
				FROM {$table_name}
				WHERE created_at >= %s",
				$today_start,
				$week_start
			)
		);

		$searches_today = $row ? (int) $row->searches_today : 0;
		$searches_week  = $row ? (int) $row->searches_week : 0;
		$carts_week     = $row ? (int) $row->carts_week : 0;
		$rate           = $searches_week > 0 ? round( ( $carts_week / $searches_week ) * 100, 1 ) : 0;

		echo '<div class="rintent-glance" style="display:flex;gap:18px;flex-wrap:wrap;">';
		printf( '<div><strong style="font-size:20px;">%s</strong><br /><span style="color:#646970;">%s</span></div>', esc_html( number_format_i18n( $searches_today ) ), esc_html__( 'searches today', 'reseller-intent' ) );
		printf( '<div><strong style="font-size:20px;">%s</strong><br /><span style="color:#646970;">%s</span></div>', esc_html( number_format_i18n( $searches_week ) ), esc_html__( 'searches, 7 days', 'reseller-intent' ) );
		printf( '<div><strong style="font-size:20px;">%s%%</strong><br /><span style="color:#646970;">%s</span></div>', esc_html( number_format_i18n( $rate, 1 ) ), esc_html__( 'search → cart, 7 days', 'reseller-intent' ) );
		echo '</div>';
		printf(
			'<p style="margin-bottom:0;"><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Open the full dashboard →', 'reseller-intent' )
		);
	}
}
