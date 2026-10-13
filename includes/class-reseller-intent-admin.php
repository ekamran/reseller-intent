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

	// The query layer lives in Reseller_Intent_Reports; these stay for the
	// export code and anyone who referenced them here.
	const RANGE_MIN = Reseller_Intent_Reports::RANGE_MIN;
	const RANGE_MAX = Reseller_Intent_Reports::RANGE_MAX;

	/**
	 * Sanitized page filter from the request, '' = all pages.
	 */
	private function sanitize_page_filter( $raw ) {
		return Reseller_Intent_Reports::sanitize_page( $raw );
	}

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

	/**
	 * Who can see the dashboard/exports. Filterable so agencies can open it
	 * to editors etc.: add_filter( 'rintent_dashboard_capability', fn() => 'edit_pages' );
	 */
	public static function capability() {
		return (string) apply_filters( 'rintent_dashboard_capability', 'manage_options' );
	}

	/**
	 * The brand mark's path from the design file: the hexagon shield with
	 * the asterisk cut. Never redrawn by hand, only recolored per context.
	 */
	const MARK_PATH = 'M0.000,40.172 L0.000,19.578 C0.000,16.136 1.836,12.956 4.817,11.235 C9.510,8.526 16.603,4.430 21.295,1.721 C24.276,0.000 27.949,0.000 30.930,1.721 C35.622,4.430 42.716,8.526 47.408,11.235 C50.389,12.956 52.225,16.136 52.225,19.578 L52.225,38.606 C52.225,42.048 50.389,45.228 47.408,46.949 C42.716,49.658 35.622,53.754 30.930,56.463 C27.949,58.184 24.276,58.184 21.295,56.463 C14.645,52.623 3.461,46.166 3.461,46.166 L22.652,35.086 L22.652,44.991 L29.573,44.991 L29.573,35.086 L38.151,40.038 L41.612,34.044 L33.034,29.092 L41.612,24.140 L38.151,18.146 L29.573,23.098 L29.573,13.193 L22.652,13.193 L22.652,23.098 L14.074,18.146 L10.614,24.140 L19.191,29.092 L0.000,40.172 Z';

	/**
	 * Menu glyph: the brand mark in a neutral fill-only path so WordPress
	 * repaints it to match the active admin color scheme (svg-painter
	 * skips strokes and gradients).
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52.225 58.184">'
			. '<path fill="#a7aaad" fill-rule="evenodd" d="' . self::MARK_PATH . '"/>'
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
	 * The brand mark, white on the accent field, shared by the page
	 * headers. Same design-file path as the menu icon.
	 */
	private static function brand_mark() {
		return '<span class="rintent-mark" aria-hidden="true">'
			. '<svg viewBox="0 0 52.225 58.184" width="16" height="18">'
			. '<path fill="#fff" fill-rule="evenodd" d="' . self::MARK_PATH . '"/>'
			. '</svg></span>';
	}

	/**
	 * Shortcode generator: build [rintent_tld_strip] and [rintent_price]
	 * visually, copy the result. Price is family-first: pick a family,
	 * every plan is included automatically.
	 */
	public function render_shortcodes_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$families = Reseller_Intent_Price::families();

		$tld_last = Reseller_Intent_TLD_Strip::last_refresh_ts();
		$tld_next = Reseller_Intent_TLD_Strip::next_refresh_ts();
		if ( ! $tld_last ) {
			$tld_status = __( 'Prices are cached after the strip first renders.', 'reseller-intent' );
		} elseif ( $tld_next > time() ) {
			$tld_status = sprintf(
				/* translators: 1: time since the last price refresh, 2: time until the next one */
				__( 'Prices refreshed %1$s ago · next auto-refresh in %2$s', 'reseller-intent' ),
				human_time_diff( $tld_last ),
				human_time_diff( time(), $tld_next )
			);
		} else {
			/* translators: %s: time since the last price refresh */
			$tld_status = sprintf( __( 'Prices refreshed %s ago', 'reseller-intent' ), human_time_diff( $tld_last ) );
		}
		?>
		<?php $notice = isset( $_GET['rintent_notice'] ) ? sanitize_key( wp_unslash( $_GET['rintent_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="wrap rintent-pages rintent-shortcodes">
			<div class="rintent-topbar">
				<?php echo self::brand_mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup built above. ?>
				<h1><?php esc_html_e( 'Shortcodes', 'reseller-intent' ); ?></h1>
				<p><?php esc_html_e( 'Everything for each shortcode lives on its card: options, live preview, and the code to copy.', 'reseller-intent' ); ?></p>
			</div>
			<hr class="wp-header-end" />

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
				<div class="rintent-card-head">
					<h2><?php esc_html_e( 'TLD price strip', 'reseller-intent' ); ?></h2>
					<p><?php esc_html_e( 'Live TLD price pills that always match checkout. Cached 12 hours, refreshed in the background.', 'reseller-intent' ); ?></p>
				</div>
				<div class="rintent-card-body">
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-gen-tlds"><?php esc_html_e( 'TLDs', 'reseller-intent' ); ?></label></span>
						<span>
							<input type="text" id="rintent-gen-tlds" class="rintent-wide" value=".com,.in,.org,.net,.io" />
							<p class="description"><?php esc_html_e( 'Comma-separated, dots optional. Order here is display order.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-gen-theme"><?php esc_html_e( 'Theme', 'reseller-intent' ); ?></label></span>
						<span>
							<select id="rintent-gen-theme"><option value="light"><?php esc_html_e( 'Light', 'reseller-intent' ); ?></option><option value="dark"><?php esc_html_e( 'Dark', 'reseller-intent' ); ?></option></select>
							<p class="description"><?php esc_html_e( 'Dark is for dark page sections on your site. Prices there use the Dark accent from Settings.', 'reseller-intent' ); ?></p>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><label for="rintent-gen-more-label"><?php esc_html_e( '"More" pill', 'reseller-intent' ); ?></label></span>
						<span>
							<span class="rintent-inline">
								<input type="text" id="rintent-gen-more-label" class="rintent-narrow" aria-label="<?php esc_attr_e( 'Text on the more pill', 'reseller-intent' ); ?>" placeholder="<?php esc_attr_e( 'More TLDs', 'reseller-intent' ); ?>" />
								<input type="url" id="rintent-gen-more-url" aria-label="<?php esc_attr_e( 'Link for the more pill', 'reseller-intent' ); ?>" placeholder="https://example.com/domains/" />
							</span>
							<p class="description"><?php esc_html_e( 'Optional link pill at the end. Leave the link empty to hide it.', 'reseller-intent' ); ?></p>
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
				</div>
				<div class="rintent-card-foot">
					<span><?php echo esc_html( $tld_status ); ?></span>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rintent-inline-form">
						<input type="hidden" name="action" value="rintent_refresh_tld" />
						<?php wp_nonce_field( 'rintent_refresh_tld' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Refresh prices now', 'reseller-intent' ); ?></button>
					</form>
				</div>
			</div>

			<div class="rintent-card">
				<div class="rintent-card-head">
					<h2><?php esc_html_e( 'Live product price', 'reseller-intent' ); ?></h2>
					<p><?php esc_html_e( 'Pick a product family, print its live price. Families come from your imported GoDaddy catalog. Every plan is included automatically, so the number stays correct when prices change.', 'reseller-intent' ); ?></p>
				</div>
				<div class="rintent-card-body">
					<?php if ( empty( $families ) ) : ?>
						<p class="rintent-empty"><em><?php esc_html_e( 'No product families yet. Import your Reseller Store products first, plans group into families automatically.', 'reseller-intent' ); ?></em></p>
					<?php else : ?>
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-gen-family"><?php esc_html_e( 'Product family', 'reseller-intent' ); ?></label></span>
							<span>
								<select id="rintent-gen-family">
									<?php foreach ( $families as $family ) : ?>
										<option value="<?php echo esc_attr( $family['slug'] ); ?>" data-label="<?php echo esc_attr( $family['label'] ); ?>">
											<?php
											/* translators: 1: family name, 2: number of plans */
											echo esc_html( sprintf( _n( '%1$s (%2$s plan)', '%1$s (%2$s plans)', count( $family['ids'] ), 'reseller-intent' ), $family['label'], number_format_i18n( count( $family['ids'] ) ) ) );
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'No individual product picking. A family is priced as a whole.', 'reseller-intent' ); ?></p>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-gen-mode"><?php esc_html_e( 'Show', 'reseller-intent' ); ?></label></span>
							<span>
								<select id="rintent-gen-mode">
									<option value="range" selected><?php esc_html_e( 'Price range, cheapest to highest', 'reseller-intent' ); ?></option>
									<option value="min"><?php esc_html_e( 'Cheapest price', 'reseller-intent' ); ?></option>
									<option value="max"><?php esc_html_e( 'Highest price', 'reseller-intent' ); ?></option>
								</select>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-gen-before"><?php esc_html_e( 'Text around it', 'reseller-intent' ); ?></label></span>
							<span>
								<span class="rintent-inline">
									<input type="text" id="rintent-gen-before" aria-label="<?php esc_attr_e( 'Text before the price', 'reseller-intent' ); ?>" />
									<input type="text" id="rintent-gen-after" class="rintent-narrow" aria-label="<?php esc_attr_e( 'Text after the price', 'reseller-intent' ); ?>" />
								</span>
								<p class="description"><?php esc_html_e( 'Pre-filled from the family. Click and type to change. Spacing is handled for you.', 'reseller-intent' ); ?></p>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><?php esc_html_e( 'Shortcode', 'reseller-intent' ); ?></span>
							<span class="rintent-output">
								<code id="rintent-gen-price-out"></code>
								<button type="button" class="button" id="rintent-gen-price-copy"><?php esc_html_e( 'Copy', 'reseller-intent' ); ?></button>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><?php esc_html_e( 'Live preview', 'reseller-intent' ); ?></span>
							<span>
								<div id="rintent-preview-price" class="rintent-preview rintent-preview--inline" data-empty="<?php esc_attr_e( 'Rendering...', 'reseller-intent' ); ?>"></div>
							</span>
						</div>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $families ) ) : ?>
					<div class="rintent-card-foot">
						<span>
							<?php esc_html_e( 'Style hooks:', 'reseller-intent' ); ?>
							<code>.rintent-price</code> <code>.rintent-price-amount</code>
							&middot; <?php esc_html_e( 'old ids="" embeds keep working', 'reseller-intent' ); ?>
						</span>
					</div>
				<?php endif; ?>
			</div>

			<div class="rintent-card">
				<div class="rintent-card-head">
					<h2><?php esc_html_e( 'Support phone number', 'reseller-intent' ); ?></h2>
					<p><?php esc_html_e( 'Shows each visitor the right regional number from their browser timezone. No lookup service, page-cache safe. Flag and number as a call link, nothing else.', 'reseller-intent' ); ?></p>
				</div>
				<div class="rintent-card-body">
					<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Numbers', 'reseller-intent' ); ?></span>
						<span>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="rintent_save_numbers" />
								<?php wp_nonce_field( 'rintent_save_numbers' ); ?>
								<div class="rintent-numbers-scroll">
								<table id="rintent-support-rows">
									<thead><tr><th><?php esc_html_e( 'Label', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'Phone number', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'Countries', 'reseller-intent' ); ?></th><th></th></tr></thead>
									<tbody>
										<?php foreach ( Reseller_Intent_Phone::numbers() as $support_entry ) : ?>
											<tr>
												<td><input type="text" name="support_label[]" aria-label="<?php esc_attr_e( 'Support entry label', 'reseller-intent' ); ?>" value="<?php echo esc_attr( $support_entry['label'] ); ?>" placeholder="<?php esc_attr_e( 'US Support', 'reseller-intent' ); ?>" /></td>
												<td><input type="text" name="support_number[]" aria-label="<?php esc_attr_e( 'Support phone number', 'reseller-intent' ); ?>" value="<?php echo esc_attr( $support_entry['number'] ); ?>" placeholder="+1-480-000-0000" /></td>
												<td><input type="text" name="support_countries[]" aria-label="<?php esc_attr_e( 'Country codes for this number', 'reseller-intent' ); ?>" value="<?php echo esc_attr( implode( ',', (array) $support_entry['countries'] ) ); ?>" placeholder="<?php esc_attr_e( 'empty = default', 'reseller-intent' ); ?>" /></td>
												<td><button type="button" class="rintent-support-remove" aria-label="<?php esc_attr_e( 'Remove row', 'reseller-intent' ); ?>">&times;</button></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								</div>
								<p class="description">
									<?php esc_html_e( 'Countries: 2-letter codes like IN, US, AE. One row with empty countries is the default for everyone else. Your list is saved; plugin updates never touch it.', 'reseller-intent' ); ?>
									<a href="https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2#Officially_assigned_code_elements" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Full country code list', 'reseller-intent' ); ?></a>
								</p>
								<p class="rintent-inline-actions">
									<button type="button" class="button" id="rintent-support-add"><?php esc_html_e( 'Add number', 'reseller-intent' ); ?></button>
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Save numbers', 'reseller-intent' ); ?></button>
									<?php if ( Reseller_Intent_Phone::is_customized() ) : ?>
										<a class="button rintent-numbers-reset" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rintent_reset_numbers' ), 'rintent_reset_numbers' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Replace your list with the GoDaddy default numbers? Your custom entries will be removed.', 'reseller-intent' ) ); ?>');"><?php esc_html_e( 'Reset to GoDaddy defaults', 'reseller-intent' ); ?></a>
										<span class="rintent-badge"><?php esc_html_e( 'Your own list active', 'reseller-intent' ); ?></span>
									<?php else : ?>
										<span class="rintent-badge"><?php esc_html_e( 'GoDaddy defaults active', 'reseller-intent' ); ?></span>
									<?php endif; ?>
								</p>
							</form>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Shortcode', 'reseller-intent' ); ?></span>
						<span class="rintent-output">
							<code id="rintent-gen-phone-out">[rintent_phone]</code>
							<button type="button" class="button" id="rintent-gen-phone-copy"><?php esc_html_e( 'Copy', 'reseller-intent' ); ?></button>
						</span>
					</div>
					<div class="rintent-field">
						<span class="rintent-label"><?php esc_html_e( 'Live preview', 'reseller-intent' ); ?></span>
						<span>
							<div class="rintent-preview rintent-preview--inline">
								<?php echo do_shortcode( '[rintent_phone]' ); ?>
							</div>
							<p class="description"><?php esc_html_e( 'Localized with your browser timezone, using the same script visitors get. Each visitor sees their own regional number.', 'reseller-intent' ); ?></p>
						</span>
					</div>
				</div>
				<div class="rintent-card-foot">
					<span>
						<?php esc_html_e( 'Style hooks:', 'reseller-intent' ); ?>
						<code>.rintent-phone</code> <code>.rintent-phone-flag</code> <code>.rintent-phone-number</code>
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
		$notice    = isset( $_GET['rintent_notice'] ) ? sanitize_key( wp_unslash( $_GET['rintent_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rintent-pages rintent-settings">
			<div class="rintent-topbar">
				<?php echo self::brand_mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup built above. ?>
				<h1><?php esc_html_e( 'Settings', 'reseller-intent' ); ?></h1>
				<p><?php esc_html_e( 'Everything is optional. Tracking works out of the box.', 'reseller-intent' ); ?></p>
			</div>
			<hr class="wp-header-end" />

			<?php if ( 'settings_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'reseller-intent' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rintent_save_settings" />
				<?php wp_nonce_field( 'rintent_save_settings' ); ?>

				<div class="rintent-card">
					<div class="rintent-card-head">
						<h2><?php esc_html_e( 'Store widgets', 'reseller-intent' ); ?></h2>
						<p><?php esc_html_e( 'Styling for the Reseller Store elements on your site. Turn the style pack off if it fights with your theme. Tracking is never affected.', 'reseller-intent' ); ?></p>
					</div>
					<div class="rintent-card-body">
						<div class="rintent-field">
							<span class="rintent-label"><?php esc_html_e( 'Style pack', 'reseller-intent' ); ?></span>
							<span class="rintent-check-group">
								<label for="rintent-style-widget">
									<input type="checkbox" id="rintent-style-widget" name="style_widget" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'style_widget' ) ); ?> />
									<?php esc_html_e( 'Style the Reseller Store widgets', 'reseller-intent' ); ?>
								</label>
								<small><?php esc_html_e( 'Accent buttons, aligned rows, skeleton loading, mobile layout. Covers the domain search, simple search, transfer, Add to cart, cart and sign in, whether you place them as shortcodes, widgets or blocks. Dark page sections are detected automatically; force either way with a .rintent-dark or .rintent-light wrapper class.', 'reseller-intent' ); ?></small>
								<span class="rintent-children" id="rintent-style-children" <?php echo Reseller_Intent_Settings::get( 'style_widget' ) ? '' : 'style="display:none;"'; ?>>
									<label for="rintent-clear-all">
										<input type="checkbox" id="rintent-clear-all" name="widget_clear_all" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'widget_clear_all' ) ); ?> />
										<?php esc_html_e( 'Floating "Clear All" button under the search bar', 'reseller-intent' ); ?>
									</label>
									<span id="rintent-clear-label-row" <?php echo Reseller_Intent_Settings::get( 'widget_clear_all' ) ? '' : 'style="display:none;"'; ?>>
										<p style="margin:6px 0 0;">
											<input type="text" name="clear_all_label" maxlength="40" aria-label="<?php esc_attr_e( 'Clear All button text', 'reseller-intent' ); ?>" value="<?php echo esc_attr( (string) Reseller_Intent_Settings::get( 'clear_all_label' ) ); ?>" placeholder="<?php esc_attr_e( 'Clear All', 'reseller-intent' ); ?>" />
										</p>
										<p class="description"><?php esc_html_e( 'Its button text, any wording or language. Leave empty for the default.', 'reseller-intent' ); ?></p>
									</span>
								</span>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-accent"><?php esc_html_e( 'Accent color', 'reseller-intent' ); ?></label></span>
							<span>
								<input type="text" id="rintent-accent" name="accent_color" class="rintent-colorpicker" value="<?php echo esc_attr( $accent ); ?>" />
								<p class="description"><?php esc_html_e( 'Used by the styled store widgets and the TLD price strip.', 'reseller-intent' ); ?></p>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-corners"><?php esc_html_e( 'Corners', 'reseller-intent' ); ?></label></span>
							<span>
								<?php $radius = (string) Reseller_Intent_Settings::get( 'widget_radius' ); ?>
								<select id="rintent-corners" name="widget_radius">
									<option value="rounded" <?php selected( 'rounded', $radius ); ?>><?php esc_html_e( 'Rounded', 'reseller-intent' ); ?></option>
									<option value="square" <?php selected( 'square', $radius ); ?>><?php esc_html_e( 'Square', 'reseller-intent' ); ?></option>
									<option value="pill" <?php selected( 'pill', $radius ); ?>><?php esc_html_e( 'Pill', 'reseller-intent' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Rounding on the search bar, result rows, product cards and every button.', 'reseller-intent' ); ?></p>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-domain-size"><?php esc_html_e( 'Domain name size', 'reseller-intent' ); ?></label></span>
							<span>
								<?php $domain_size = (int) Reseller_Intent_Settings::get( 'domain_size' ); ?>
								<input type="number" id="rintent-domain-size" name="domain_size" min="10" max="48" step="1" value="<?php echo $domain_size > 0 ? esc_attr( $domain_size ) : ''; ?>" placeholder="<?php esc_attr_e( 'Theme', 'reseller-intent' ); ?>" />
								<p class="description"><?php esc_html_e( 'Size in pixels for the domain name in search results. Leave empty to keep your theme’s size.', 'reseller-intent' ); ?></p>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-price-color"><?php esc_html_e( 'Price color', 'reseller-intent' ); ?></label></span>
							<span>
								<input type="text" id="rintent-price-color" name="price_color" class="rintent-colorpicker" value="<?php echo esc_attr( (string) Reseller_Intent_Settings::get( 'price_color' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Prices in search results. Clear it to keep your theme’s color.', 'reseller-intent' ); ?></p>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><?php esc_html_e( 'Store links', 'reseller-intent' ); ?></span>
							<span class="rintent-check-group">
								<label for="rintent-new-tab">
									<input type="checkbox" id="rintent-new-tab" name="new_tab" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'new_tab' ) ); ?> />
									<?php esc_html_e( 'Open store links in a new tab', 'reseller-intent' ); ?>
								</label>
								<small><?php esc_html_e( 'Searching, transferring and checking out all finish on GoDaddy. This leaves your site open behind them, so a visitor can come back and search again. Covers Continue to cart, the simple search and transfer boxes, Add to cart, the cart and the sign in link.', 'reseller-intent' ); ?></small>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-accent-dark"><?php esc_html_e( 'Dark accent', 'reseller-intent' ); ?></label></span>
							<span class="rintent-check-group">
								<?php $accent_dark_custom = '' !== (string) Reseller_Intent_Settings::get( 'accent_dark' ); ?>
								<label for="rintent-accent-dark-custom">
									<input type="checkbox" id="rintent-accent-dark-custom" name="accent_dark_custom" value="1" <?php checked( $accent_dark_custom ); ?> />
									<?php esc_html_e( 'Pick my own color for dark sections', 'reseller-intent' ); ?>
								</label>
								<small><?php esc_html_e( 'Otherwise your accent is auto-lightened just enough to stay readable on dark surfaces. Buttons keep the accent everywhere.', 'reseller-intent' ); ?></small>
								<input type="text" id="rintent-accent-dark" name="accent_dark" class="rintent-colorpicker" value="<?php echo esc_attr( Reseller_Intent_Settings::accent_dark_color() ); ?>" <?php disabled( ! $accent_dark_custom ); ?> />

							<details class="rintent-theming-ref">
								<summary><?php esc_html_e( 'Theming reference: CSS classes and variables', 'reseller-intent' ); ?></summary>
								<p class="description"><?php esc_html_e( 'Target these from your theme or Additional CSS to restyle any part of the widget.', 'reseller-intent' ); ?></p>
								<p class="description"><strong><?php esc_html_e( 'Set the variables, not the classes, wherever you can.', 'reseller-intent' ); ?></strong> <?php esc_html_e( 'A variable wins on its own. Targeting a class needs !important, because the style pack has to outrank themes that reach the same buttons through selectors like button:not(:hover):not(:active).', 'reseller-intent' ); ?></p>
								<p class="description"><code>body{--rintent-radius:0;--rintent-accent:#0f766e;}</code></p>
								<table>
									<thead><tr><th><?php esc_html_e( 'Element', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'CSS class / variable', 'reseller-intent' ); ?></th></tr></thead>
									<tbody>
										<tr><td><?php esc_html_e( 'Domain name in results', 'reseller-intent' ); ?></td><td><code>.rstore-domain-search .domain-name</code><br /><code>--rintent-domain-size</code> &middot; <code>--rintent-domain-color</code> &middot; <code>--rintent-domain-font</code> &middot; <code>--rintent-domain-weight</code></td></tr>
										<tr><td><?php esc_html_e( 'Price', 'reseller-intent' ); ?></td><td><code>.rstore-message .salePrice</code> / <code>.listPrice</code><br /><code>--rintent-price-size</code> &middot; <code>--rintent-price-color</code> &middot; <code>--rintent-price-font</code> &middot; <code>--rintent-price-weight</code></td></tr>
										<tr><td><?php esc_html_e( 'Clear All button', 'reseller-intent' ); ?></td><td><code>.rintent-clear-btn</code><br /><code>--rintent-clear-color</code> &middot; <code>--rintent-clear-size</code></td></tr>
										<tr><td><?php esc_html_e( 'Result row card', 'reseller-intent' ); ?></td><td><code>.rstore-domain-search .domain-result</code></td></tr>
										<tr><td><?php esc_html_e( 'Available / taken wording', 'reseller-intent' ); ?></td><td><code>.result-content p.available</code> &middot; <code>.result-content p.not-available</code></td></tr>
										<tr><td><?php esc_html_e( 'Price and button column', 'reseller-intent' ); ?></td><td><code>.rstore-domain-search .purchase-info</code></td></tr>
										<tr><td><?php esc_html_e( 'Exact match block', 'reseller-intent' ); ?></td><td><code>.rstore-exact-domain-list</code></td></tr>
										<tr><td><?php esc_html_e( 'Loading placeholder rows', 'reseller-intent' ); ?></td><td><code>.rintent-skeleton-row</code></td></tr>
										<tr><td><?php esc_html_e( 'Small print under results', 'reseller-intent' ); ?></td><td><code>.rstore-disclaimer</code></td></tr>
										<tr><td><?php esc_html_e( 'Error message', 'reseller-intent' ); ?></td><td><code>.rstore-error</code></td></tr>
										<tr><td><?php esc_html_e( 'Search button / Continue to cart', 'reseller-intent' ); ?></td><td><code>.search-form input[type=submit]</code> &middot; <code>.rstore-domain-continue-button</code></td></tr>
										<tr><td><?php esc_html_e( 'Simple search / transfer bar', 'reseller-intent' ); ?></td><td><code>.rstore-domain-form .search-field</code> &middot; <code>.rstore-domain-form .search-submit</code></td></tr>
										<tr><td><?php esc_html_e( 'Add to cart button', 'reseller-intent' ); ?></td><td><code>.rstore-add-to-cart</code></td></tr>
										<tr><td><?php esc_html_e( 'Product pod card', 'reseller-intent' ); ?></td><td><code>.rstore-product</code> / <code>.rstore-Product</code> <?php esc_html_e( '(shortcode / widget spelling)', 'reseller-intent' ); ?><br /><code>.rstore-pricing</code> &middot; <code>.rstore-product-icons svg</code> &middot; <code>.rstore-product-permalink .link</code></td></tr>
										<tr><td><?php esc_html_e( 'Cart and sign in links', 'reseller-intent' ); ?></td><td><code>.rstore-cart a</code> &middot; <code>.rstore-login .login-link</code> &middot; <code>.logout-link</code></td></tr>
										<tr><td><?php esc_html_e( 'Select / Selected links', 'reseller-intent' ); ?></td><td><code>.rstore-domain-buy-button.select</code> &middot; <code>.rstore-domain-buy-button.selected</code></td></tr>
										<tr><td><?php esc_html_e( 'Accent (buttons, focus ring)', 'reseller-intent' ); ?></td><td><code>--rintent-accent</code> <?php esc_html_e( '(set by the color picker above)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Accent as text on light surfaces', 'reseller-intent' ); ?></td><td><code>--rintent-accent-ink</code> <?php esc_html_e( '(the accent darkened only as far as it needs to stay readable, used for prices in the light TLD strip)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Accent on dark surfaces', 'reseller-intent' ); ?></td><td><code>--rintent-accent-dark</code> <?php esc_html_e( '(set by the Dark accent picker above; this variable overrides it)', 'reseller-intent' ); ?></td></tr>
										<tr><td><?php esc_html_e( 'Text on accent buttons', 'reseller-intent' ); ?></td><td><code>--rintent-accent-text</code> <?php esc_html_e( '(auto-computed for contrast; set to force your own)', 'reseller-intent' ); ?></td></tr>
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
				</div>

				<div class="rintent-card">
					<div class="rintent-card-head">
						<h2><?php esc_html_e( 'Tracking', 'reseller-intent' ); ?></h2>
						<p><?php esc_html_e( 'Anonymous by design. No cookies, no fingerprints, no IP stored. Bots are never recorded.', 'reseller-intent' ); ?></p>
					</div>
					<div class="rintent-card-body">
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-blocklist"><?php esc_html_e( 'Ignore searches', 'reseller-intent' ); ?></label></span>
							<span>
								<textarea id="rintent-blocklist" name="blocklist" rows="3" placeholder="mytestdomain.com&#10;*.internal&#10;staging*"><?php echo esc_textarea( implode( "\n", (array) Reseller_Intent_Settings::get( 'blocklist' ) ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One pattern per line, matched against searched domains. * is a wildcard. Handy for your own test searches.', 'reseller-intent' ); ?></p>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><?php esc_html_e( 'Admin bar', 'reseller-intent' ); ?></span>
							<span class="rintent-check-group">
								<label for="rintent-admin-bar">
									<input type="checkbox" id="rintent-admin-bar" name="admin_bar" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'admin_bar' ) ); ?> />
									<?php esc_html_e( 'Show today’s count in the admin bar', 'reseller-intent' ); ?>
								</label>
								<small><?php esc_html_e( 'Everything tracked today, on the site and in the admin, with the breakdown one hover away. Only people who can see this dashboard see it.', 'reseller-intent' ); ?></small>
							</span>
						</div>
					</div>
				</div>

				<div class="rintent-card">
					<div class="rintent-card-head">
						<h2><?php esc_html_e( 'Data', 'reseller-intent' ); ?></h2>
						<p><?php esc_html_e( 'Everything lives in one table in your own database. Nothing leaves the site.', 'reseller-intent' ); ?></p>
					</div>
					<div class="rintent-card-body">
						<div class="rintent-field">
							<span class="rintent-label"><label for="rintent-retention"><?php esc_html_e( 'Retention', 'reseller-intent' ); ?></label></span>
							<span>
								<span class="rintent-inline">
									<input type="number" id="rintent-retention" name="retention_days" min="0" max="3650" step="1" value="<?php echo esc_attr( $retention ); ?>" style="width:80px;" />
									<span><?php esc_html_e( 'days', 'reseller-intent' ); ?></span>
								</span>
								<p class="description"><?php esc_html_e( 'Events older than this are deleted once a day. 0 keeps everything forever. Specific windows can be cleared from the dashboard any time.', 'reseller-intent' ); ?></p>
							</span>
						</div>
						<div class="rintent-field">
							<span class="rintent-label"><?php esc_html_e( 'Uninstall', 'reseller-intent' ); ?></span>
							<span class="rintent-check-group">
								<label for="rintent-uninstall">
									<input type="checkbox" id="rintent-uninstall" name="delete_on_uninstall" value="1" <?php checked( $uninstall ); ?> />
									<?php esc_html_e( 'Delete all tracked data and settings when the plugin is uninstalled', 'reseller-intent' ); ?>
								</label>
								<small class="rintent-danger-note"><?php esc_html_e( 'Irreversible at uninstall time. Off by default so your history survives a reinstall.', 'reseller-intent' ); ?></small>
							</span>
						</div>
					</div>
				</div>

				<div class="rintent-savebar">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'reseller-intent' ); ?></button>
				</div>
			</form>

		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook_suffix ) {
		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

		// The dashboard widget's own styles, on the WordPress dashboard only
		// and only for someone who can see the widget at all.
		if ( 'index.php' === $hook_suffix && current_user_can( self::capability() ) ) {
			wp_enqueue_style(
				'rintent-glance',
				$base_url . 'assets/css/glance.css',
				array(),
				filemtime( $base_path . 'assets/css/glance.css' )
			);
		}

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
						/* translators: %s: product family name; text placed before the price for range and cheapest modes */
						'beforeTpl'    => __( '%s from', 'reseller-intent' ),
						/* translators: %s: product family name; text placed before the price for highest mode */
						'beforeMaxTpl' => __( '%s up to', 'reseller-intent' ),
						'afterTpl'     => __( 'per month', 'reseller-intent' ),
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
				// Read from the plugin header, the one place the version is
				// kept. Admin dashboard only, so the file read costs nothing
				// a visitor ever pays.
				'version'     => get_file_data( RINTENT_FILE, array( 'Version' => 'Version' ) )['Version'],
				'exportUrl'   => wp_nonce_url(
					add_query_arg( array( 'action' => 'rintent_export_csv' ), admin_url( 'admin-post.php' ) ),
					'rintent_export'
				),
				'clearUrl'    => admin_url( 'admin-post.php?action=rintent_clear_data' ),
				'actionNonce' => wp_create_nonce( 'rintent_admin_actions' ),
				'notice'      => isset( $_GET['rintent_notice'] ) ? sanitize_key( wp_unslash( $_GET['rintent_notice'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice slug from our own redirects.
				'tzLabel'     => wp_timezone_string(),
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
		$page = $this->sanitize_page_filter( isset( $_POST['page_path'] ) ? sanitize_text_field( wp_unslash( $_POST['page_path'] ) ) : '' );

		if ( 'custom' === $range_key && ! self::valid_custom_range( $from, $to ) ) {
			$range_key = '90';
		}

		wp_send_json_success( $this->get_dashboard_data( $range_key, $from, $to, $page ) );
	}

	/**
	 * @return bool True when $from/$to are valid Y-m-d, ordered, max 2 years.
	 */
	public static function valid_custom_range( $from, $to ) {
		return Reseller_Intent_Reports::valid_custom_range( $from, $to );
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
				$family = isset( $_POST['family'] ) ? sanitize_title( wp_unslash( $_POST['family'] ) ) : '';
				$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'range';
				$mode   = in_array( $mode, array( 'min', 'max', 'range' ), true ) ? $mode : 'range';
				$before = isset( $_POST['before'] ) ? sanitize_text_field( wp_unslash( $_POST['before'] ) ) : '';
				$after  = isset( $_POST['after'] ) ? sanitize_text_field( wp_unslash( $_POST['after'] ) ) : '';
				$html   = do_shortcode(
					sprintf(
						'[rintent_price family="%s" mode="%s" before="%s" after="%s"]',
						esc_attr( $family ),
						esc_attr( $mode ),
						esc_attr( $before ),
						esc_attr( $after )
					)
				);
				break;

			default:
				wp_send_json_error( array( 'message' => 'Unknown type' ), 400 );
		}

		wp_send_json_success( array( 'html' => $html ) );
	}

	private static function day_anchor_ts() {
		return Reseller_Intent_Reports::day_anchor_ts();
	}

	private function range_bounds( $range_key, $from = '', $to = '' ) {
		return Reseller_Intent_Reports::bounds( $range_key, $from, $to );
	}

	/**
	 * Deeper rows for one list panel: the dashboard ships the top slice,
	 * the pager fetches the rest 25 at a time so Lifetime views can reach
	 * every row without a giant initial payload.
	 */
	public function ajax_panel_rows() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'rintent_dashboard_data', 'nonce' );

		$panel     = isset( $_POST['panel'] ) ? sanitize_key( wp_unslash( $_POST['panel'] ) ) : '';
		$range_key = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '90';
		$from      = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to        = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$page      = $this->sanitize_page_filter( isset( $_POST['page_path'] ) ? sanitize_text_field( wp_unslash( $_POST['page_path'] ) ) : '' );
		// Deep paging stops at 500 rows; past that the export is the tool.
		$offset = isset( $_POST['offset'] ) ? min( 500, absint( $_POST['offset'] ) ) : 0;
		$limit  = 25;

		$range_key = Reseller_Intent_Reports::sanitize_range_key( $range_key, '90' );
		if ( 'custom' === $range_key && ! self::valid_custom_range( $from, $to ) ) {
			$range_key = '90';
		}

		list( $range_start, $range_end ) = $this->range_bounds( $range_key, $from, $to );

		// One extra row tells the pager whether another page exists.
		$fetch = $limit + 1;
		$items = array();

		switch ( $panel ) {
			case 'tlds':
				$items = Reseller_Intent_Reports::tlds( $range_start, $range_end, $page, $fetch, $offset );
				break;

			case 'repeats':
				$items = Reseller_Intent_Reports::repeats( $range_start, $range_end, $page, $fetch, $offset );
				break;

			case 'countries':
				foreach ( Reseller_Intent_Reports::countries( $range_start, $range_end, $page, $fetch, $offset ) as $row ) {
					$items[] = array(
						'code'  => $row['code'],
						'count' => $row['hits'],
					);
				}
				break;

			case 'carted':
				// Aggregated from items_json, so paging slices the aggregate.
				$carted = Reseller_Intent_Reports::carted( $range_start, $range_end, $page, $fetch, $offset );
				$items  = $carted['domains'];
				break;

			case 'products':
				$items = Reseller_Intent_Reports::ranking( 'product_add', $range_start, $range_end, $page, $fetch, $offset );
				break;

			case 'transfers':
				$items = Reseller_Intent_Reports::ranking( 'domain_transfer', $range_start, $range_end, $page, $fetch, $offset );
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

	/**
	 * Every panel's data for one window, assembled from the Reports class
	 * so the dashboard, WP-CLI and the Abilities API can never disagree on
	 * a number. The payload shape is the React app's contract; change it
	 * only together with admin.js.
	 */
	private function get_dashboard_data( $range_key, $from = '', $to = '', $page_path = '' ) {
		$win         = Reseller_Intent_Reports::window( $range_key, $from, $to );
		$bounded     = $win['bounded'];
		$custom      = $win['custom'];
		$len         = $win['len'];
		$range_start = $win['range_start'];
		$range_end   = $win['range_end'];
		$page        = $page_path;

		// KPIs: one aggregate query per window, compared like for like.
		$kpi_now  = Reseller_Intent_Reports::kpis( $win['start'], $win['end'], $page );
		$kpi_prev = $bounded ? Reseller_Intent_Reports::kpis( $win['prev_start'], $win['prev_end'], $page ) : null;

		// Top slices only; the pager fetches deeper rows through ajax_panel_rows.
		$tlds       = Reseller_Intent_Reports::tlds( $range_start, $range_end, $page, 25 );
		$trend      = Reseller_Intent_Reports::trend( $bounded, $len, $win['anchor_ts'], $page );
		$cart_sizes = Reseller_Intent_Reports::cart_sizes( $range_start, $range_end, $page );
		$repeats    = Reseller_Intent_Reports::repeats( $range_start, $range_end, $page, 25 );
		// The Search by Page panel stays unfiltered on purpose: it is the
		// page comparator and feeds the filter dropdown its options.
		$pages        = Reseller_Intent_Reports::pages( $range_start, $range_end );
		$carted       = Reseller_Intent_Reports::carted( $range_start, $range_end, $page, 15 );
		$availability = Reseller_Intent_Reports::availability( $range_start, $range_end, $page );
		$devices      = Reseller_Intent_Reports::devices( $range_start, $range_end, $page );
		$countries    = Reseller_Intent_Reports::countries( $range_start, $range_end, $page, 20 );
		$recent       = Reseller_Intent_Reports::recent( $range_start, $range_end, $page, 1000 );
		$products     = Reseller_Intent_Reports::ranking( 'product_add', $range_start, $range_end, $page, 15 );
		$transfers    = Reseller_Intent_Reports::ranking( 'domain_transfer', $range_start, $range_end, $page, 15 );
		$outbound     = Reseller_Intent_Reports::outbound( $range_start, $range_end, $page );
		$last         = Reseller_Intent_Reports::last_event();

		$countries_total = 0;
		foreach ( $countries as $country ) {
			$countries_total += $country['hits'];
		}

		return array(
			'range'        => $range_key,
			'bounded'      => $bounded,
			'lastEvent'    => array(
				'ago'   => $last['ts']
					/* translators: %s: human readable time difference */
					? sprintf( __( 'Last event %s ago', 'reseller-intent' ), human_time_diff( $last['ts'], strtotime( current_time( 'mysql' ) ) ) )
					: __( 'No events yet', 'reseller-intent' ),
				'stale' => $last['stale'],
			),
			'rangeLabel'   => $custom
				? sprintf(
					/* translators: 1: range start date, 2: range end date */
					__( '%1$s to %2$s', 'reseller-intent' ),
					wp_date( 'M j, Y', strtotime( $from . ' 12:00:00' ) ),
					wp_date( 'M j, Y', strtotime( $to . ' 12:00:00' ) )
				)
				: ( $bounded
					/* translators: %d: number of days */
					? sprintf( __( 'Last %d days', 'reseller-intent' ), $len )
					: __( 'Lifetime', 'reseller-intent' ) ),
			'kpis'         => array(
				'now'  => $kpi_now,
				'prev' => $kpi_prev,
			),
			'tlds'         => array(
				'items' => $tlds,
			),
			'trend'        => $trend,
			'cartSizes'    => $cart_sizes,
			'repeats'      => $repeats,
			'pages'        => array_values(
				array_filter(
					$pages,
					static function ( $page ) {
						return $page['searches'] > 0 || $page['carts'] > 0;
					}
				)
			),
			'pageOptions'  => Reseller_Intent_Reports::page_options( $pages ),
			'carted'       => $carted,
			'products'     => array(
				'items' => $products,
			),
			'transfers'    => array(
				'items' => $transfers,
			),
			'outbound'     => $outbound,
			'totals'       => array(
				'tlds'      => Reseller_Intent_Reports::count_tlds( $range_start, $range_end, $page ),
				'repeats'   => Reseller_Intent_Reports::count_repeats( $range_start, $range_end, $page ),
				'carted'    => (int) $carted['total'],
				// Only worth a round trip when the panel is on screen at all.
				'products'  => $products ? Reseller_Intent_Reports::count_ranking( 'product_add', $range_start, $range_end, $page ) : 0,
				'transfers' => $transfers ? Reseller_Intent_Reports::count_ranking( 'domain_transfer', $range_start, $range_end, $page ) : 0,
			),
			'availability' => $availability,
			'devices'      => $devices,
			'countries'    => array(
				'items' => $countries,
				'total' => $countries_total,
			),
			'recent'       => $recent,
		);
	}

	/**
	 * Pull domain names out of a continue_to_cart items_json payload.
	 */
	private function extract_carted_domains( $items_json ) {
		return Reseller_Intent_Reports::extract_carted_domains( $items_json );
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
		return Reseller_Intent_Reports::format_datetime_local( $datetime_raw );
	}

	public static function sanitize_csv_cell( $value ) {
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
			fputcsv( $output, array( 'Event', 'Domain', 'Related Search', 'Items Count', 'Items', 'Available', 'Device', 'Country', 'Page URL', 'Time (' . $tz_label . ')' ), ',', '"', '\\' );
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
					$last_id,
					$range_start,
					$range_end,
					5000
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
						),
						',',
						'"',
						'\\'
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

	/**
	 * A signed percentage change, or null when there is no honest one to
	 * show: no previous period, or nothing on either side.
	 */
	private function glance_delta( $now, $prev ) {
		if ( $prev <= 0 ) {
			return $now > 0 ? 'new' : null;
		}

		return ( ( $now - $prev ) / $prev ) * 100;
	}

	private function print_glance_delta( $now, $prev ) {
		$change = $this->glance_delta( $now, $prev );

		if ( null === $change ) {
			return;
		}

		if ( 'new' === $change ) {
			printf( '<span class="rintent-glance-delta is-up">%s</span>', esc_html__( 'new', 'reseller-intent' ) );
			return;
		}

		$class = 'is-flat';
		$text  = '±0%';

		if ( $change >= 0.05 ) {
			$class = 'is-up';
			$text  = '+' . number_format_i18n( abs( $change ), 1 ) . '%';
		} elseif ( $change <= -0.05 ) {
			$class = 'is-down';
			$text  = '-' . number_format_i18n( abs( $change ), 1 ) . '%';
		}

		printf( '<span class="rintent-glance-delta %s">%s</span>', esc_attr( $class ), esc_html( $text ) );
	}

	/**
	 * Two queries, no more: this runs on every visit to the WordPress
	 * dashboard, which is the busiest screen in the admin.
	 *
	 * Counts cover the 2.1 event types too. A storefront selling hosting
	 * through product pods was reading zeroes here while its dashboard
	 * showed the adds, because this widget only ever asked about
	 * domain_search and continue_to_cart.
	 */
	public function render_glance_widget() {
		Reseller_Intent_DB::ensure_table();

		$anchor     = self::day_anchor_ts();
		$week_start = wp_date( 'Y-m-d 00:00:00', $anchor - ( 6 * DAY_IN_SECONDS ) );
		$prev_start = wp_date( 'Y-m-d 00:00:00', $anchor - ( 13 * DAY_IN_SECONDS ) );

		$week = Reseller_Intent_Reports::glance( $week_start, $prev_start );

		$searches_now  = $week['searches_now'];
		$searches_prev = $week['searches_prev'];
		$carts_now     = $week['carts_now'];
		$carts_prev    = $week['carts_prev'];
		$events_now    = $week['events_now'];

		/*
		 * The rate is the domain funnel only: a cart click as a share of the
		 * searches that could have produced one. Product adds belong in the
		 * count above but never in this ratio, because nothing counts the
		 * product views they come from, and a numerator without its own
		 * denominator pushes the percentage past 100. Transfer searches are
		 * out for the same reason: a transfer leaves for GoDaddy instead of
		 * reaching the cart. Same definition as Search → Cart Rate on the
		 * dashboard and `wp rintent stats`, so the three never disagree.
		 */
		$rate      = $week['rate_base_now'] > 0 ? round( ( $week['cart_clicks_now'] / $week['rate_base_now'] ) * 100, 1 ) : 0;
		$rate_prev = $week['rate_base_prev'] > 0 ? ( $week['cart_clicks_prev'] / $week['rate_base_prev'] ) * 100 : 0;

		/*
		 * The one line that turns three numbers into a reason to look. A
		 * name people keep typing is the most useful thing this plugin
		 * knows, so it goes above the stats, not buried in a panel.
		 */
		$lead = $events_now > 0 ? Reseller_Intent_Reports::glance_lead( $week_start ) : null;

		// Nothing at all in fourteen days, on a table that has history: the
		// numbers below would read as a quiet week when tracking may have
		// stopped reaching the table.
		if ( 0 === $events_now && Reseller_Intent_DB::count_events_since( 0 ) > 0 ) {
			printf(
				'<p class="rintent-glance-quiet">%s</p>',
				esc_html__( 'No events in the last 7 days, though older ones are stored. Could be a quiet week. If it is not, Tools then Site Health checks the tracking for you.', 'reseller-intent' )
			);
		}

		if ( $lead && (int) $lead->hits > 1 ) {
			printf(
				'<p class="rintent-glance-lead">%s</p>',
				sprintf(
					/* translators: 1: domain or product name, 2: number of times it came up */
					esc_html__( 'Most wanted this week: %1$s, %2$s times.', 'reseller-intent' ),
					'<strong>' . esc_html( $lead->label ) . '</strong>',
					esc_html( number_format_i18n( (int) $lead->hits ) )
				)
			);
		}

		echo '<div class="rintent-glance-row">';

		echo '<div class="rintent-glance-stat">';
		printf( '<span class="rintent-glance-num">%s', esc_html( number_format_i18n( $searches_now ) ) );
		$this->print_glance_delta( $searches_now, $searches_prev );
		echo '</span>';
		printf( '<span class="rintent-glance-label">%s</span>', esc_html__( 'searches, 7 days', 'reseller-intent' ) );
		echo '</div>';

		echo '<div class="rintent-glance-stat">';
		printf( '<span class="rintent-glance-num">%s', esc_html( number_format_i18n( $carts_now ) ) );
		$this->print_glance_delta( $carts_now, $carts_prev );
		echo '</span>';
		printf( '<span class="rintent-glance-label">%s</span>', esc_html__( 'sent to cart', 'reseller-intent' ) );
		echo '</div>';

		echo '<div class="rintent-glance-stat">';
		printf( '<span class="rintent-glance-num">%s%%', esc_html( number_format_i18n( $rate, 1 ) ) );
		$this->print_glance_delta( $rate, $rate_prev );
		echo '</span>';
		printf( '<span class="rintent-glance-label">%s</span>', esc_html__( 'search to cart', 'reseller-intent' ) );
		echo '</div>';

		echo '</div>';

		printf(
			'<p class="rintent-glance-foot"><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Open the full dashboard →', 'reseller-intent' )
		);
	}
}
