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

	/*
	 * Per-page filter, appended verbatim to range-bound queries. Strips
	 * query string and fragment, then cuts the path off after the host
	 * (the first slash past "https://"), so the comparison is an exact
	 * path match, never a substring one: filtering /domains/ must not
	 * count /old-domains/ or /foo/domains/. Two placeholders cover the
	 * stored URL with and without its trailing slash; a URL with no path
	 * at all yields '' from SUBSTRING, which the home filter passes as
	 * its second value.
	 */
	const PAGE_FILTER_SQL = " AND LOWER(SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(page_url, '#', 1), '?', 1), LOCATE('/', SUBSTRING_INDEX(SUBSTRING_INDEX(page_url, '#', 1), '?', 1), 9))) IN (%s, %s)";

	/**
	 * Sanitized page filter from the request, '' = all pages.
	 *
	 * @param string $raw Raw request value.
	 * @return string Normalized path ('/', '/domains/', ...) or ''.
	 */
	private function sanitize_page_filter( $raw ) {
		$path = strtolower( trim( (string) $raw ) );

		if ( '' === $path || '/' === $path ) {
			return $path;
		}

		if ( strlen( $path ) > 191 || ! preg_match( '#^/[a-z0-9/._%\-]*$#', $path ) ) {
			return '';
		}

		return untrailingslashit( $path ) . '/';
	}

	/**
	 * The two IN() values for PAGE_FILTER_SQL.
	 *
	 * @param string $page_path Normalized path from sanitize_page_filter().
	 * @return string[] With-slash and without-slash variants.
	 */
	private static function page_filter_params( $page_path ) {
		return array( $page_path, '/' === $page_path ? '' : untrailingslashit( $page_path ) );
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

	/**
	 * Start and exclusive end for a range, as plain values. Every query
	 * carries the same literal "created_at >= %s AND created_at < %s"
	 * condition and passes these two through $wpdb->prepare(), so no SQL
	 * fragment is ever built from a variable. Open-ended sides use the
	 * epoch and a far-future date.
	 */
	/**
	 * Today's local midday as a real UTC timestamp. Day buckets anchor here
	 * rather than on time(), because 86400 seconds is not one local day across
	 * a DST change: plain second arithmetic slides a bucket into the next
	 * calendar day while DATE(created_at) stays on the stored wall clock.
	 * Midday absorbs any real shift. NOT current_time(), wp_date() already
	 * adds the site offset.
	 */
	private static function day_anchor_ts() {
		return ( new DateTimeImmutable( wp_date( 'Y-m-d' ) . ' 12:00:00', wp_timezone() ) )->getTimestamp();
	}

	private function range_bounds( $range_key, $from = '', $to = '' ) {
		if ( 'custom' === $range_key ) {
			return array( $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' );
		}

		if ( 'all' === $range_key ) {
			return array( self::RANGE_MIN, self::RANGE_MAX );
		}

		$len = (int) $range_key;

		return array( wp_date( 'Y-m-d 00:00:00', self::day_anchor_ts() - ( ( $len - 1 ) * DAY_IN_SECONDS ) ), self::RANGE_MAX );
	}

	/**
	 * Deeper rows for one list panel: the dashboard ships the top slice,
	 * the pager fetches the rest 25 at a time so Lifetime views can reach
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
		$page      = $this->sanitize_page_filter( isset( $_POST['page_path'] ) ? sanitize_text_field( wp_unslash( $_POST['page_path'] ) ) : '' );
		// Deep paging stops at 500 rows; past that the export is the tool.
		$offset = isset( $_POST['offset'] ) ? min( 500, absint( $_POST['offset'] ) ) : 0;
		$limit  = 25;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table_name is the fixed prefixed table; $page_sql is a class constant carrying its own placeholders.
		$page_sql    = '';
		$page_params = array();
		if ( '' !== $page ) {
			$page_sql    = self::PAGE_FILTER_SQL;
			$page_params = self::page_filter_params( $page );
		}

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
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(SUBSTRING_INDEX(domain_query, '.', -1)) AS tld, COUNT(*) AS hits FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query LIKE %s AND created_at >= %s AND created_at < %s{$page_sql} GROUP BY tld ORDER BY hits DESC LIMIT %d OFFSET %d", array_merge( array( '%' . $wpdb->esc_like( '.' ) . '%', $range_start, $range_end ), $page_params, array( $fetch, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $page_sql is a class constant carrying its own placeholders.
				foreach ( $rows as $row ) {
					$items[] = array(
						'label' => '.' . (string) $row->tld,
						'count' => (int) $row->hits,
					);
				}
				break;

			case 'repeats':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT domain_query AS domain, COUNT(*) AS hits FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql} GROUP BY domain_query HAVING hits >= 2 ORDER BY hits DESC LIMIT %d OFFSET %d", array_merge( array( $range_start, $range_end ), $page_params, array( $fetch, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $page_sql is a class constant carrying its own placeholders.
				foreach ( $rows as $row ) {
					$items[] = array(
						'domain' => self::display_domain( $row->domain ),
						'hits'   => (int) $row->hits,
					);
				}
				break;

			case 'countries':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT country, COUNT(*) AS hits FROM {$table_name} WHERE event_type = 'domain_search' AND country <> '' AND created_at >= %s AND created_at < %s{$page_sql} GROUP BY country ORDER BY hits DESC LIMIT %d OFFSET %d", array_merge( array( $range_start, $range_end ), $page_params, array( $fetch, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $page_sql is a class constant carrying its own placeholders.
				foreach ( $rows as $row ) {
					$items[] = array(
						'code'  => (string) $row->country,
						'count' => (int) $row->hits,
					);
				}
				break;

			case 'carted':
				// Aggregated from items_json, so paging slices the aggregate.
				$json_rows     = $wpdb->get_results( $wpdb->prepare( "SELECT items_json FROM {$table_name} WHERE event_type = 'continue_to_cart' AND items_json IS NOT NULL AND items_json <> '' AND created_at >= %s AND created_at < %s{$page_sql} ORDER BY id DESC LIMIT 2000", array_merge( array( $range_start, $range_end ), $page_params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

			case 'products':
				$items = $this->get_query_ranking( $table_name, 'product_add', $range_start, $range_end, $page_sql, $page_params, $fetch, $offset );
				break;

			case 'transfers':
				$items = $this->get_query_ranking( $table_name, 'domain_transfer', $range_start, $range_end, $page_sql, $page_params, $fetch, $offset );
				break;

			default:
				wp_send_json_error( array( 'message' => 'Unknown panel' ), 400 );
		}

		$has_more = count( $items ) > $limit;
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		wp_send_json_success(
			array(
				'items'   => array_slice( $items, 0, $limit ),
				'hasMore' => $has_more,
			)
		);
	}

	private function get_dashboard_data( $range_key, $from = '', $to = '', $page_path = '' ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table_name is the fixed prefixed table; $page_sql is a class constant carrying its own placeholders.
		$table_name = Reseller_Intent_DB::table_name();

		// Optional per-page filter: a constant clause with two placeholders,
		// appended to every panel query below. The Search by Page panel
		// stays unfiltered on purpose, it is the page comparator and feeds
		// the filter dropdown its options.
		$page_sql    = '';
		$page_params = array();
		if ( '' !== $page_path ) {
			$page_sql    = self::PAGE_FILTER_SQL;
			$page_params = self::page_filter_params( $page_path );
		}

		$bounded = ( 'all' !== $range_key );
		$custom  = ( 'custom' === $range_key );
		$now_ts  = self::day_anchor_ts(); // NOT current_time(): wp_date() adds the site offset itself; both = double shift after 18:30 IST.
		$end     = ''; // Exclusive upper bound, custom range only.

		if ( $custom ) {
			// Dates are site-local calendar days; created_at is stored site-local.
			$len         = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
			$start       = $from . ' 00:00:00';
			$end         = gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00';
			$prev_start  = gmdate( 'Y-m-d', strtotime( $from . ' -' . $len . ' days' ) ) . ' 00:00:00';
			$anchor_ts   = ( new DateTimeImmutable( $to . ' 12:00:00', wp_timezone() ) )->getTimestamp();
			$range_start = $start;
			$range_end   = $end;
		} else {
			$len         = $bounded ? (int) $range_key : 0;
			$start       = $bounded ? wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( $len - 1 ) * DAY_IN_SECONDS ) ) : '';
			$prev_start  = $bounded ? wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( ( 2 * $len ) - 1 ) * DAY_IN_SECONDS ) ) : '';
			$anchor_ts   = $now_ts;
			$range_start = $bounded ? $start : self::RANGE_MIN;
			$range_end   = self::RANGE_MAX;
		}

		/*
		 * KPIs: one aggregate query per window, compared like for like. Today
		 * is only partly over, so the previous window has to stop at the same
		 * clock time it did $len days ago, otherwise every badge reads as a
		 * decline all morning and recovers by midnight.
		 */
		$prev_end = ( $bounded && ( '' === $end || strtotime( $end ) > $now_ts ) )
			? wp_date( 'Y-m-d H:i:s', $now_ts - ( $len * DAY_IN_SECONDS ) )
			: $start;

		$kpi_now  = $this->get_kpi_counts( $table_name, $start, $end, $page_sql, $page_params );
		$kpi_prev = $bounded ? $this->get_kpi_counts( $table_name, $prev_start, $prev_end, $page_sql, $page_params ) : null;

		// TLD ranking: a plain top slice, the pager fetches deeper rows.
		$tld_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(SUBSTRING_INDEX(domain_query, '.', -1)) AS tld, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query LIKE %s AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY tld
				ORDER BY hits DESC
				LIMIT 25",
				array_merge( array( '%' . $wpdb->esc_like( '.' ) . '%', $range_start, $range_end ), $page_params )
			)
		);
		$tlds     = array();
		foreach ( $tld_rows as $tld_row ) {
			$tlds[] = array(
				'label' => '.' . sanitize_key( (string) $tld_row->tld ),
				'count' => (int) $tld_row->hits,
			);
		}

		// Trend: daily for bounded ranges, monthly for lifetime.
		$trend = $this->get_trend_series( $table_name, $bounded, $len, $anchor_ts, $page_sql, $page_params );

		// Cart size split: how many domains each cart click carried.
		$cart_row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN items_count <= 1 THEN 1 ELSE 0 END),0) AS b1,
					COALESCE(SUM(CASE WHEN items_count = 2 THEN 1 ELSE 0 END),0) AS b2,
					COALESCE(SUM(CASE WHEN items_count = 3 THEN 1 ELSE 0 END),0) AS b3,
					COALESCE(SUM(CASE WHEN items_count >= 4 THEN 1 ELSE 0 END),0) AS b4
				FROM {$table_name}
				WHERE event_type = 'continue_to_cart' AND created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $range_start, $range_end ), $page_params )
			),
			ARRAY_A
		);
		$cart_row   = is_array( $cart_row ) ? array_map( 'intval', $cart_row ) : array();
		$cart_sizes = array(
			array(
				'label' => '1×',
				'count' => isset( $cart_row['b1'] ) ? $cart_row['b1'] : 0,
			),
			array(
				'label' => '2×',
				'count' => isset( $cart_row['b2'] ) ? $cart_row['b2'] : 0,
			),
			array(
				'label' => '3×',
				'count' => isset( $cart_row['b3'] ) ? $cart_row['b3'] : 0,
			),
			array(
				'label' => '4+',
				'count' => isset( $cart_row['b4'] ) ? $cart_row['b4'] : 0,
			),
		);

		// Repeat intent: searched 2+ times inside the window.
		$repeat_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query AS domain, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY domain_query
				HAVING hits >= 2
				ORDER BY hits DESC
				LIMIT 25",
				array_merge( array( $range_start, $range_end ), $page_params )
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

		// Carted domains from items_json. The scan is bounded at 2000 rows and
		// that bound must match ajax_panel_rows 'carted', or the first page and
		// the Show more pages are ranked from different samples.
		$carted = $this->get_carted_breakdown( $table_name, $range_start, $range_end, $page_sql, $page_params );

		// Availability + devices.
		$availability_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END),0) AS avail,
					COALESCE(SUM(CASE WHEN is_available = 0 THEN 1 ELSE 0 END),0) AS taken
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND is_available IS NOT NULL AND created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $range_start, $range_end ), $page_params )
			),
			ARRAY_A
		);
		$avail            = isset( $availability_row['avail'] ) ? (int) $availability_row['avail'] : 0;
		$taken            = isset( $availability_row['taken'] ) ? (int) $availability_row['taken'] : 0;

		$device_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT device, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND device IN ('mobile','tablet','desktop') AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY device",
				array_merge( array( $range_start, $range_end ), $page_params )
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
				"SELECT country, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = 'domain_search' AND country <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY country
				ORDER BY hits DESC
				LIMIT 20",
				array_merge( array( $range_start, $range_end ), $page_params )
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

		/*
		 * Recent search log: latest 1000 in range; filtered/paged client-side.
		 * Transfer searches ride along, they are the same act with a different
		 * destination, and the Result column tells the two apart.
		 */
		$recent_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query, created_at, is_available, device, event_type
				FROM {$table_name}
				WHERE event_type IN ('domain_search', 'domain_transfer') AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				ORDER BY id DESC
				LIMIT 1000",
				array_merge( array( $range_start, $range_end ), $page_params )
			)
		);
		$recent      = array();
		foreach ( $recent_rows as $recent_row ) {
			$recent[] = array(
				'domain'    => self::display_domain( $recent_row->domain_query ),
				'time'      => $this->format_datetime_local( (string) $recent_row->created_at ),
				'available' => ( null === $recent_row->is_available || '' === (string) $recent_row->is_available ) ? null : (bool) (int) $recent_row->is_available,
				'device'    => (string) $recent_row->device,
				'transfer'  => 'domain_transfer' === (string) $recent_row->event_type,
			);
		}

		/*
		 * Product adds and transfer searches. Both come from Reseller Store
		 * surfaces a storefront may never place, so an empty list hides the
		 * panel rather than parking a blank one on the grid forever.
		 */
		$products  = $this->get_query_ranking( $table_name, 'product_add', $range_start, $range_end, $page_sql, $page_params );
		$transfers = $this->get_query_ranking( $table_name, 'domain_transfer', $range_start, $range_end, $page_sql, $page_params );

		/*
		 * The three links that leave the site carrying nothing to rank. They
		 * are counts, not a list, so they share one small panel.
		 */
		$outbound_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN event_type = 'cart_view' THEN 1 ELSE 0 END),0) AS cart,
					COALESCE(SUM(CASE WHEN event_type = 'login_click' THEN 1 ELSE 0 END),0) AS login,
					COALESCE(SUM(CASE WHEN event_type = 'phone_click' THEN 1 ELSE 0 END),0) AS phone
				FROM {$table_name}
				WHERE created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $range_start, $range_end ), $page_params )
			),
			ARRAY_A
		);
		$outbound     = array(
			'cart'  => isset( $outbound_row['cart'] ) ? (int) $outbound_row['cart'] : 0,
			'login' => isset( $outbound_row['login'] ) ? (int) $outbound_row['login'] : 0,
			'phone' => isset( $outbound_row['phone'] ) ? (int) $outbound_row['phone'] : 0,
		);

		// Tracking health: time since the newest event, any range. Surfaces
		// silent breakage (JS error, markup drift, blocked AJAX) at a glance.
		$last_event_at = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// created_at is stored in site-local time, so diff against local now.
		$last_event_ts  = $last_event_at ? (int) strtotime( $last_event_at ) : 0;
		$last_event_age = $last_event_ts ? max( 0, strtotime( current_time( 'mysql' ) ) - $last_event_ts ) : 0;

		return array(
			'range'        => $range_key,
			'bounded'      => $bounded,
			'lastEvent'    => array(
				'ago'   => $last_event_ts
					/* translators: %s: human readable time difference */
					? sprintf( __( 'Last event %s ago', 'reseller-intent' ), human_time_diff( $last_event_ts, strtotime( current_time( 'mysql' ) ) ) )
					: __( 'No events yet', 'reseller-intent' ),
				'stale' => $last_event_ts ? ( $last_event_age > 3 * DAY_IN_SECONDS ) : false,
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
			'pageOptions'  => $this->page_filter_options( $pages ),
			'carted'       => $carted,
			'products'     => array(
				'items' => $products,
			),
			'transfers'    => array(
				'items' => $transfers,
			),
			'outbound'     => $outbound,
			'totals'       => array(
				'tlds'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT LOWER(SUBSTRING_INDEX(domain_query, '.', -1))) FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query LIKE %s AND created_at >= %s AND created_at < %s{$page_sql}", array_merge( array( '%' . $wpdb->esc_like( '.' ) . '%', $range_start, $range_end ), $page_params ) ) ),
				'repeats'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT 1 FROM {$table_name} WHERE event_type = 'domain_search' AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql} GROUP BY domain_query HAVING COUNT(*) >= 2) grouped", array_merge( array( $range_start, $range_end ), $page_params ) ) ),
				'carted'    => (int) $carted['total'],
				// Only worth a round trip when the panel is on screen at all.
				'products'  => $products ? $this->count_query_ranking( $table_name, 'product_add', $range_start, $range_end, $page_sql, $page_params ) : 0,
				'transfers' => $transfers ? $this->count_query_ranking( $table_name, 'domain_transfer', $range_start, $range_end, $page_sql, $page_params ) : 0,
			),
			'availability' => array(
				'available' => $avail,
				'taken'     => $taken,
			),
			'devices'      => $devices,
			'countries'    => array(
				'items' => $countries,
				'total' => $countries_total,
			),
			'recent'       => $recent,
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
	}

	private function get_trend_series( $table_name, $bounded, $len, $now_ts, $page_sql = '', $page_params = array() ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table_name is the fixed prefixed table; $page_sql is a class constant carrying its own placeholders.

		if ( $bounded ) {
			$start    = wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( $len - 1 ) * DAY_IN_SECONDS ) );
			$rows     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE(created_at) AS bucket,
						COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END),0) AS searches,
						COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts
					FROM {$table_name}
					WHERE created_at >= %s{$page_sql}
					GROUP BY DATE(created_at)",
					array_merge( array( $start ), $page_params )
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
		// The epoch lower bound keeps the query shape identical to the
		// bounded one, so the page filter appends the same way.
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS bucket,
					COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END),0) AS searches,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts
				FROM {$table_name}
				WHERE created_at >= %s{$page_sql}
				GROUP BY bucket
				ORDER BY bucket ASC",
				array_merge( array( self::RANGE_MIN ), $page_params )
			)
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
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

		/*
		 * Group on the URL without its query string, not the whole URL. One
		 * page can carry hundreds of distinct URLs once campaign parameters
		 * and ?domainToCheck= links are in play, and collapsing those in PHP
		 * afterwards was too late: the LIMIT had already thrown most of them
		 * away, so a busy landing page could rank below a quiet one or drop
		 * off entirely. The limit now bounds distinct paths, of which a real
		 * site has a handful. normalize_page_path() still merges what is
		 * left, the scheme, host, casing and trailing slash.
		 */
		$page_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(page_url, '#', 1), '?', 1) AS page_url,
					COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END),0) AS searches,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts,
					COUNT(*) AS events
				FROM {$table_name}
				WHERE page_url IS NOT NULL AND page_url <> '' AND created_at >= %s AND created_at < %s
				GROUP BY 1
				ORDER BY searches DESC
				LIMIT 200",
				$range_start,
				$range_end
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
					'events'   => 0,
				);
			}
			$by_path[ $path ]['searches'] += (int) $page_row->searches;
			$by_path[ $path ]['carts']    += (int) $page_row->carts;
			$by_path[ $path ]['events']   += (int) $page_row->events;
		}

		usort(
			$by_path,
			static function ( $a, $b ) {
				return $b['searches'] <=> $a['searches'];
			}
		);

		// 50 paths = five pager pages; a real site has a handful.
		$top = array_slice( array_values( $by_path ), 0, 50 );
		foreach ( $top as &$page ) {
			$page['label'] = $this->page_label_for_path( $page['path'] );
		}
		unset( $page );

		return $top;
	}

	/**
	 * Every page carrying any tracked event, busiest first. The Search by
	 * Page panel counts searches and cart clicks only, which is what it says
	 * it does, but the filter beside it has to reach a page that only ever
	 * saw a product added or a domain transfer, so it reads this instead.
	 */
	private function page_filter_options( $rows ) {
		$rows = (array) $rows;

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['events'] <=> $a['events'];
			}
		);

		$options = array();
		foreach ( $rows as $row ) {
			$options[] = array(
				'path'  => $row['path'],
				'label' => $row['label'],
			);
		}

		return $options;
	}

	/**
	 * Label for a path: the path itself, which is short and unambiguous.
	 * Page titles can be long enough to wreck the column; only the home
	 * page gets a word, its path says nothing.
	 */
	private function page_label_for_path( $path ) {
		if ( '/' === $path ) {
			return __( 'Home', 'reseller-intent' );
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

	private function get_carted_breakdown( $table_name, $range_start, $range_end, $page_sql = '', $page_params = array() ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table_name is the fixed prefixed table; $page_sql is a class constant carrying its own placeholders.
		$json_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT items_json FROM {$table_name}
				WHERE event_type = 'continue_to_cart' AND items_json IS NOT NULL AND items_json <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				ORDER BY id DESC
				LIMIT 2000",
				array_merge( array( $range_start, $range_end ), $page_params )
			)
		);

		$domain_counts = array();
		foreach ( $json_rows as $json_row ) {
			foreach ( $this->extract_carted_domains( (string) $json_row->items_json ) as $domain ) {
				$domain_counts[ $domain ] = isset( $domain_counts[ $domain ] ) ? $domain_counts[ $domain ] + 1 : 1;
			}
		}
		arsort( $domain_counts );

		$domains = array();
		foreach ( array_slice( $domain_counts, 0, 15, true ) as $domain => $count ) {
			$domains[] = array(
				'domain' => self::display_domain( $domain ),
				'count'  => (int) $count,
			);
		}

		return array(
			'domains' => $domains,
			'total'   => count( $domain_counts ),
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
	}

	/**
	 * Aggregate KPI counts for a window. Empty bounds mean open ended.
	 */
	private function get_kpi_counts( $table_name, $start = '', $end = '', $page_sql = '', $page_params = array() ) {
		global $wpdb;

		$start = '' !== $start ? $start : self::RANGE_MIN;
		$end   = '' !== $end ? $end : self::RANGE_MAX;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table_name is the fixed prefixed table; $page_sql is a class constant carrying its own placeholders.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN 1 ELSE 0 END),0) AS searches,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS cart_clicks,
					COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN items_count ELSE 0 END),0) AS domains_added,
					COALESCE(SUM(CASE WHEN event_type = 'domain_transfer' THEN 1 ELSE 0 END),0) AS transfers,
					COALESCE(SUM(CASE WHEN event_type = 'product_add' THEN 1 ELSE 0 END),0) AS product_adds,
					COUNT(DISTINCT CASE WHEN event_type = 'domain_search' AND domain_query <> '' THEN domain_query END) AS unique_searches
				FROM {$table_name}
				WHERE created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $start, $end ), $page_params )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		$searches = isset( $row['searches'] ) ? (int) $row['searches'] : 0;
		$uniques  = isset( $row['unique_searches'] ) ? (int) $row['unique_searches'] : 0;

		return array(
			'searches'       => $searches,
			'cartClicks'     => isset( $row['cart_clicks'] ) ? (int) $row['cart_clicks'] : 0,
			'domainsAdded'   => isset( $row['domains_added'] ) ? (int) $row['domains_added'] : 0,
			// Searches beyond the first for a name: total minus distinct names.
			'repeatSearches' => max( 0, $searches - $uniques ),
			// Not KPI cards, the panels that own these events read them.
			'transfers'      => isset( $row['transfers'] ) ? (int) $row['transfers'] : 0,
			'productAdds'    => isset( $row['product_adds'] ) ? (int) $row['product_adds'] : 0,
		);
	}

	/**
	 * Top domain_query values for one event type, most hits first. Transfer
	 * searches and product adds are both plain "which name, how often"
	 * lists, so they share this instead of carrying a query each.
	 */
	private function get_query_ranking( $table_name, $event_type, $range_start, $range_end, $page_sql = '', $page_params = array(), $limit = 15, $offset = 0 ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table_name is the fixed prefixed table; $page_sql is a class constant carrying its own placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain_query, COUNT(*) AS hits
				FROM {$table_name}
				WHERE event_type = %s AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql}
				GROUP BY domain_query
				ORDER BY hits DESC, domain_query ASC
				LIMIT %d OFFSET %d",
				array_merge( array( $event_type, $range_start, $range_end ), $page_params, array( $limit, $offset ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'label' => self::display_domain( (string) $row->domain_query ),
				'count' => (int) $row->hits,
			);
		}

		return $items;
	}

	/**
	 * How many distinct names the ranking above has in total, for its pager.
	 */
	private function count_query_ranking( $table_name, $event_type, $range_start, $range_end, $page_sql = '', $page_params = array() ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table_name is the fixed prefixed table; $page_sql is a class constant carrying its own placeholders.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT domain_query)
				FROM {$table_name}
				WHERE event_type = %s AND domain_query <> '' AND created_at >= %s AND created_at < %s{$page_sql}",
				array_merge( array( $event_type, $range_start, $range_end ), $page_params )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
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
		global $wpdb;

		Reseller_Intent_DB::ensure_table();

		$table_name = Reseller_Intent_DB::table_name();
		$anchor     = self::day_anchor_ts();
		$week_start = wp_date( 'Y-m-d 00:00:00', $anchor - ( 6 * DAY_IN_SECONDS ) );
		$prev_start = wp_date( 'Y-m-d 00:00:00', $anchor - ( 13 * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is the fixed prefixed table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN created_at >= %s AND event_type IN ('domain_search','domain_transfer') THEN 1 ELSE 0 END) AS searches_now,
					SUM(CASE WHEN created_at <  %s AND event_type IN ('domain_search','domain_transfer') THEN 1 ELSE 0 END) AS searches_prev,
					SUM(CASE WHEN created_at >= %s AND event_type IN ('continue_to_cart','product_add') THEN 1 ELSE 0 END) AS carts_now,
					SUM(CASE WHEN created_at <  %s AND event_type IN ('continue_to_cart','product_add') THEN 1 ELSE 0 END) AS carts_prev,
					SUM(CASE WHEN created_at >= %s AND event_type = 'domain_search' THEN 1 ELSE 0 END) AS rate_base_now,
					SUM(CASE WHEN created_at <  %s AND event_type = 'domain_search' THEN 1 ELSE 0 END) AS rate_base_prev,
					SUM(CASE WHEN created_at >= %s AND event_type = 'continue_to_cart' THEN 1 ELSE 0 END) AS cart_clicks_now,
					SUM(CASE WHEN created_at <  %s AND event_type = 'continue_to_cart' THEN 1 ELSE 0 END) AS cart_clicks_prev,
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS events_now
				FROM {$table_name}
				WHERE created_at >= %s",
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$week_start,
				$prev_start
			)
		);

		$searches_now  = $row ? (int) $row->searches_now : 0;
		$searches_prev = $row ? (int) $row->searches_prev : 0;
		$carts_now     = $row ? (int) $row->carts_now : 0;
		$carts_prev    = $row ? (int) $row->carts_prev : 0;
		$events_now    = $row ? (int) $row->events_now : 0;

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
		$rate_base_now    = $row ? (int) $row->rate_base_now : 0;
		$rate_base_prev   = $row ? (int) $row->rate_base_prev : 0;
		$cart_clicks_now  = $row ? (int) $row->cart_clicks_now : 0;
		$cart_clicks_prev = $row ? (int) $row->cart_clicks_prev : 0;

		$rate      = $rate_base_now > 0 ? round( ( $cart_clicks_now / $rate_base_now ) * 100, 1 ) : 0;
		$rate_prev = $rate_base_prev > 0 ? ( $cart_clicks_prev / $rate_base_prev ) * 100 : 0;

		/*
		 * The one line that turns three numbers into a reason to look. A
		 * name people keep typing is the most useful thing this plugin
		 * knows, so it goes above the stats, not buried in a panel.
		 */
		$lead = null;

		if ( $events_now > 0 ) {
			$lead = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT domain_query AS label, COUNT(*) AS hits
					FROM {$table_name}
					WHERE created_at >= %s
						AND domain_query <> ''
						AND event_type IN ('domain_search','domain_transfer','product_add')
					GROUP BY domain_query
					ORDER BY hits DESC, label ASC
					LIMIT 1",
					$week_start
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
