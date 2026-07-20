<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reseller Intent — analytics dashboard for the Reseller Store domain search.
 *
 * The page is a React app (wp-element) fed by a single AJAX endpoint that
 * returns every panel's data for one selected range, so the whole dashboard
 * follows one time window.
 */
final class Reseller_Intent_Admin {

	const PAGE_SLUG = 'reseller-intent';

	/**
	 * Who can see the dashboard/exports. Filterable so agencies can open it
	 * to editors etc.: add_filter( 'rintent_dashboard_capability', fn() => 'edit_pages' );
	 */
	public static function capability() {
		return (string) apply_filters( 'rintent_dashboard_capability', 'manage_options' );
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'Reseller Intent', 'reseller-intent' ),
			__( 'Reseller Intent', 'reseller-intent' ),
			self::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-chart-area',
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
	 * visually, copy the result. No page reloads, no AJAX — plain JS.
	 */
	public function render_shortcodes_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$products = get_posts(
			array(
				'post_type'      => 'reseller_product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="wrap rintent-shortcodes">
			<h1><?php esc_html_e( 'Reseller Intent — Shortcodes', 'reseller-intent' ); ?></h1>

			<h2><?php esc_html_e( 'TLD price strip', 'reseller-intent' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Live TLD price pills, matching checkout prices. Cached 12h and kept warm by a background refresh.', 'reseller-intent' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rintent-gen-tlds"><?php esc_html_e( 'TLDs', 'reseller-intent' ); ?></label></th>
					<td><input type="text" id="rintent-gen-tlds" class="regular-text" value=".com,.in,.org,.net,.io" />
					<p class="description"><?php esc_html_e( 'Comma-separated, with or without dots.', 'reseller-intent' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="rintent-gen-theme"><?php esc_html_e( 'Theme', 'reseller-intent' ); ?></label></th>
					<td><select id="rintent-gen-theme"><option value="light"><?php esc_html_e( 'Light', 'reseller-intent' ); ?></option><option value="dark"><?php esc_html_e( 'Dark', 'reseller-intent' ); ?></option></select></td>
				</tr>
				<tr>
					<th scope="row"><label for="rintent-gen-more-label"><?php esc_html_e( '"More" pill', 'reseller-intent' ); ?></label></th>
					<td>
						<input type="text" id="rintent-gen-more-label" class="regular-text" placeholder="<?php esc_attr_e( 'More TLDs', 'reseller-intent' ); ?>" />
						<input type="url" id="rintent-gen-more-url" class="regular-text" placeholder="https://example.com/domains/" />
						<p class="description"><?php esc_html_e( 'Optional trailing link pill. Leave the URL empty to hide it.', 'reseller-intent' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shortcode', 'reseller-intent' ); ?></th>
					<td>
						<code id="rintent-gen-tld-out" style="display:inline-block;padding:8px 12px;user-select:all;"></code>
						<button type="button" class="button" id="rintent-gen-tld-copy"><?php esc_html_e( 'Copy', 'reseller-intent' ); ?></button>
					</td>
				</tr>
			</table>

			<hr />

			<h2><?php esc_html_e( 'Starting-at price', 'reseller-intent' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Prints the cheapest current price across the selected products, straight from Reseller Store product data. Select every plan of a family so the number stays correct when prices change.', 'reseller-intent' ); ?></p>

			<?php if ( empty( $products ) ) : ?>
				<p><em><?php esc_html_e( 'No published Reseller Store products found — import products in Reseller Store first.', 'reseller-intent' ); ?></em></p>
			<?php else : ?>
				<p><input type="search" id="rintent-gen-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter products…', 'reseller-intent' ); ?>" /></p>
				<div id="rintent-gen-products" style="max-height:260px;overflow:auto;border:1px solid #dcdcde;border-radius:4px;padding:8px 12px;max-width:640px;background:#fff;">
					<?php foreach ( $products as $product ) :
						$sale  = (string) get_post_meta( $product->ID, 'rstore_salePrice', true );
						$list  = (string) get_post_meta( $product->ID, 'rstore_listPrice', true );
						$price = '' !== trim( $sale ) ? $sale : $list;
						?>
						<label style="display:block;padding:3px 0;">
							<input type="checkbox" class="rintent-gen-product" value="<?php echo esc_attr( $product->ID ); ?>" data-title="<?php echo esc_attr( strtolower( $product->post_title ) ); ?>" />
							<?php echo esc_html( $product->post_title ); ?>
							<span style="color:#787c82;">— <?php echo esc_html( '' !== trim( $price ) ? $price : __( 'no price', 'reseller-intent' ) ); ?> · ID <?php echo esc_html( $product->ID ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rintent-gen-fallback"><?php esc_html_e( 'Fallback text', 'reseller-intent' ); ?></label></th>
						<td><input type="text" id="rintent-gen-fallback" class="regular-text" placeholder="$3.99" />
						<p class="description"><?php esc_html_e( 'Shown if no selected product has a price.', 'reseller-intent' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shortcode', 'reseller-intent' ); ?></th>
						<td>
							<code id="rintent-gen-price-out" style="display:inline-block;padding:8px 12px;user-select:all;"></code>
							<button type="button" class="button" id="rintent-gen-price-copy"><?php esc_html_e( 'Copy', 'reseller-intent' ); ?></button>
						</td>
					</tr>
				</table>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			function esc(value) {
				return String(value).replace(/"/g, '');
			}

			function buildTld() {
				var tlds = esc(document.getElementById('rintent-gen-tlds').value || '.com,.in,.org,.net,.io');
				var theme = document.getElementById('rintent-gen-theme').value;
				var label = esc(document.getElementById('rintent-gen-more-label').value);
				var url = esc(document.getElementById('rintent-gen-more-url').value);
				var out = '[rintent_tld_strip tlds="' + tlds + '"';
				if (theme !== 'light') {
					out += ' theme="' + theme + '"';
				}
				if (url) {
					out += ' more_url="' + url + '"';
					if (label) {
						out += ' more_label="' + label + '"';
					}
				}
				document.getElementById('rintent-gen-tld-out').textContent = out + ']';
			}

			function buildPrice() {
				var outEl = document.getElementById('rintent-gen-price-out');
				if (!outEl) {
					return;
				}
				var ids = Array.prototype.slice.call(document.querySelectorAll('.rintent-gen-product:checked')).map(function(cb) { return cb.value; });
				var fallback = esc((document.getElementById('rintent-gen-fallback') || { value: '' }).value);
				var out = '[rintent_price ids="' + ids.join(',') + '"';
				if (fallback) {
					out += ' fallback="' + fallback + '"';
				}
				outEl.textContent = ids.length ? out + ']' : '';
			}

			function copy(sourceId) {
				var text = document.getElementById(sourceId).textContent;
				if (text && navigator.clipboard) {
					navigator.clipboard.writeText(text);
				}
			}

			['rintent-gen-tlds', 'rintent-gen-theme', 'rintent-gen-more-label', 'rintent-gen-more-url'].forEach(function(id) {
				document.getElementById(id).addEventListener('input', buildTld);
				document.getElementById(id).addEventListener('change', buildTld);
			});
			document.getElementById('rintent-gen-tld-copy').addEventListener('click', function() { copy('rintent-gen-tld-out'); });

			var filter = document.getElementById('rintent-gen-filter');
			if (filter) {
				filter.addEventListener('input', function() {
					var q = filter.value.toLowerCase();
					document.querySelectorAll('.rintent-gen-product').forEach(function(cb) {
						cb.closest('label').style.display = cb.getAttribute('data-title').indexOf(q) === -1 ? 'none' : 'block';
					});
				});
				document.querySelectorAll('.rintent-gen-product').forEach(function(cb) {
					cb.addEventListener('change', buildPrice);
				});
				document.getElementById('rintent-gen-fallback').addEventListener('input', buildPrice);
				document.getElementById('rintent-gen-price-copy').addEventListener('click', function() { copy('rintent-gen-price-out'); });
			}

			buildTld();
			buildPrice();
		})();
		</script>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$accent    = (string) Reseller_Intent_Settings::get( 'accent_color' );
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
		$notice = isset( $_GET['rintent_notice'] ) ? sanitize_key( wp_unslash( $_GET['rintent_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reseller Intent — Settings', 'reseller-intent' ); ?></h1>

			<?php if ( 'settings_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'reseller-intent' ); ?></p></div>
			<?php elseif ( preg_match( '/^imported_(\d+)$/', $notice, $import_match ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %s: number of events */ __( '%s legacy events imported.', 'reseller-intent' ), number_format_i18n( (int) $import_match[1] ) ) ); ?></p></div>
			<?php elseif ( 'import_skipped' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Import skipped — already imported or no legacy table found.', 'reseller-intent' ); ?></p></div>
			<?php elseif ( 'digest_sent' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test digest sent.', 'reseller-intent' ); ?></p></div>
			<?php elseif ( 'digest_failed' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Sending failed — check that your site can send email (an SMTP plugin usually fixes this).', 'reseller-intent' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rintent_save_settings" />
				<?php wp_nonce_field( 'rintent_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="rintent-accent"><?php esc_html_e( 'Accent color', 'reseller-intent' ); ?></label>
						</th>
						<td>
							<input type="color" id="rintent-accent" name="accent_color" value="<?php echo esc_attr( $accent ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for charts and highlights on the dashboard.', 'reseller-intent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="rintent-retention"><?php esc_html_e( 'Data retention', 'reseller-intent' ); ?></label>
						</th>
						<td>
							<select id="rintent-retention" name="retention_days">
								<?php foreach ( $retention_choices as $days => $label ) : ?>
									<option value="<?php echo esc_attr( $days ); ?>" <?php selected( $retention, $days ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Events older than this are deleted automatically once a day. You can also clear specific windows any time from the dashboard.', 'reseller-intent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Widget styling', 'reseller-intent' ); ?></th>
						<td>
							<fieldset>
								<label for="rintent-style-widget">
									<input type="checkbox" id="rintent-style-widget" name="style_widget" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'style_widget' ) ); ?> />
									<?php esc_html_e( 'Style the domain search widget (accent buttons, aligned result rows, mobile layout)', 'reseller-intent' ); ?>
								</label>
								<br />
								<label for="rintent-skeletons">
									<input type="checkbox" id="rintent-skeletons" name="widget_skeletons" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'widget_skeletons' ) ); ?> />
									<?php esc_html_e( 'Skeleton loading rows while results load', 'reseller-intent' ); ?>
								</label>
								<br />
								<label for="rintent-clear-all">
									<input type="checkbox" id="rintent-clear-all" name="widget_clear_all" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'widget_clear_all' ) ); ?> />
									<?php esc_html_e( 'Floating "Clear All" button under the search bar', 'reseller-intent' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Wrap a dark page section in a .rintent-dark class to switch the widget to light-on-dark colors. If styling conflicts with your theme, turn the first toggle off — tracking is unaffected.', 'reseller-intent' ); ?></p>

								<details class="rintent-theming-ref" style="margin-top:12px;max-width:640px;">
									<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Theming reference — CSS classes & variables', 'reseller-intent' ); ?></summary>
									<p class="description"><?php esc_html_e( 'Target these from your theme or Appearance → Customize → Additional CSS to restyle any part of the widget.', 'reseller-intent' ); ?></p>
									<table class="widefat striped" style="margin-top:8px;">
										<thead><tr><th><?php esc_html_e( 'Element', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'CSS class / variable', 'reseller-intent' ); ?></th></tr></thead>
										<tbody>
											<tr><td><?php esc_html_e( 'Domain name in results', 'reseller-intent' ); ?></td><td><code>.rstore-domain-search .domain-name</code><br /><code>--rintent-domain-size</code> · <code>--rintent-domain-color</code> · <code>--rintent-domain-font</code> · <code>--rintent-domain-weight</code></td></tr>
											<tr><td><?php esc_html_e( 'Price', 'reseller-intent' ); ?></td><td><code>.rstore-message .salePrice</code> / <code>.listPrice</code><br /><code>--rintent-price-size</code> · <code>--rintent-price-color</code> · <code>--rintent-price-font</code> · <code>--rintent-price-weight</code></td></tr>
											<tr><td><?php esc_html_e( 'Clear All button', 'reseller-intent' ); ?></td><td><code>.rintent-clear-btn</code><br /><code>--rintent-clear-color</code> · <code>--rintent-clear-size</code></td></tr>
											<tr><td><?php esc_html_e( 'Result row card', 'reseller-intent' ); ?></td><td><code>.rstore-domain-search .domain-result</code></td></tr>
											<tr><td><?php esc_html_e( 'Search button / Continue to cart', 'reseller-intent' ); ?></td><td><code>.search-form input[type=submit]</code> · <code>.rstore-domain-continue-button</code></td></tr>
											<tr><td><?php esc_html_e( 'Select / Selected links', 'reseller-intent' ); ?></td><td><code>.rstore-domain-buy-button.select</code> · <code>.rstore-domain-buy-button.selected</code></td></tr>
											<tr><td><?php esc_html_e( 'Accent (buttons, focus ring)', 'reseller-intent' ); ?></td><td><code>--rintent-accent</code> <?php esc_html_e( '(set by the color picker above)', 'reseller-intent' ); ?></td></tr>
											<tr><td><?php esc_html_e( 'Dark section context', 'reseller-intent' ); ?></td><td><code>.rintent-dark</code> <?php esc_html_e( '(wrapper class)', 'reseller-intent' ); ?></td></tr>
										</tbody>
									</table>
									<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Example:', 'reseller-intent' ); ?> <code>body{--rintent-domain-size:18px;--rintent-price-color:#0a7d5c;}</code></p>
								</details>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Weekly digest', 'reseller-intent' ); ?></th>
						<td>
							<label for="rintent-digest">
								<input type="checkbox" id="rintent-digest" name="digest_enabled" value="1" <?php checked( (bool) Reseller_Intent_Settings::get( 'digest_enabled' ) ); ?> />
								<?php esc_html_e( 'Email a weekly summary (searches, conversion, top domains & TLDs)', 'reseller-intent' ); ?>
							</label>
							<p style="margin:8px 0 0;">
								<input type="email" name="digest_email" class="regular-text" value="<?php echo esc_attr( (string) Reseller_Intent_Settings::get( 'digest_email' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
							</p>
							<p class="description"><?php esc_html_e( 'Leave empty to use the site admin email.', 'reseller-intent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Support numbers', 'reseller-intent' ); ?></th>
						<td>
							<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Regional support phone numbers for the [rintent_phone] shortcode. Countries = comma-separated 2-letter codes (IN, US, AE…); leave empty for the default number shown to everyone else. Visitors see their region\'s number automatically — page-cache safe.', 'reseller-intent' ); ?></p>
							<table id="rintent-support-rows" class="widefat striped" style="max-width:640px;">
								<thead><tr><th><?php esc_html_e( 'Label', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'Phone number', 'reseller-intent' ); ?></th><th><?php esc_html_e( 'Countries', 'reseller-intent' ); ?></th><th></th></tr></thead>
								<tbody>
									<?php foreach ( (array) Reseller_Intent_Settings::get( 'support_numbers' ) as $support_entry ) : ?>
										<tr>
											<td><input type="text" name="support_label[]" value="<?php echo esc_attr( $support_entry['label'] ); ?>" placeholder="<?php esc_attr_e( 'US Support', 'reseller-intent' ); ?>" /></td>
											<td><input type="text" name="support_number[]" value="<?php echo esc_attr( $support_entry['number'] ); ?>" placeholder="+1-480-000-0000" /></td>
											<td><input type="text" name="support_countries[]" value="<?php echo esc_attr( implode( ',', (array) $support_entry['countries'] ) ); ?>" placeholder="US,CA" style="width:90px;" /></td>
											<td><button type="button" class="button-link-delete rintent-support-remove" aria-label="<?php esc_attr_e( 'Remove row', 'reseller-intent' ); ?>">&times;</button></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p><button type="button" class="button" id="rintent-support-add"><?php esc_html_e( 'Add number', 'reseller-intent' ); ?></button></p>
							<script>
							(function() {
								document.getElementById('rintent-support-add').addEventListener('click', function() {
									var tbody = document.querySelector('#rintent-support-rows tbody');
									var row = document.createElement('tr');
									row.innerHTML = '<td><input type="text" name="support_label[]" /></td>'
										+ '<td><input type="text" name="support_number[]" placeholder="+1-480-000-0000" /></td>'
										+ '<td><input type="text" name="support_countries[]" placeholder="US,CA" style="width:90px;" /></td>'
										+ '<td><button type="button" class="button-link-delete rintent-support-remove" aria-label="Remove row">&times;</button></td>';
									tbody.appendChild(row);
								});
								document.addEventListener('click', function(event) {
									if (event.target.classList && event.target.classList.contains('rintent-support-remove')) {
										event.target.closest('tr').remove();
									}
								});
							})();
							</script>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rintent-blocklist"><?php esc_html_e( 'Ignore searches', 'reseller-intent' ); ?></label></th>
						<td>
							<textarea id="rintent-blocklist" name="blocklist" rows="4" class="large-text code" placeholder="mytestdomain.com&#10;*.internal&#10;staging*"><?php echo esc_textarea( implode( "\n", (array) Reseller_Intent_Settings::get( 'blocklist' ) ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One pattern per line, matched against searched domains. Use * as a wildcard — handy for ignoring your own test searches.', 'reseller-intent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bots', 'reseller-intent' ); ?></th>
						<td>
							<label for="rintent-bots">
								<input type="checkbox" id="rintent-bots" name="track_bots" value="1" <?php checked( $bots ); ?> />
								<?php esc_html_e( 'Also record events from known bots and crawlers (off recommended)', 'reseller-intent' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'reseller-intent' ); ?></th>
						<td>
							<label for="rintent-uninstall">
								<input type="checkbox" id="rintent-uninstall" name="delete_on_uninstall" value="1" <?php checked( $uninstall ); ?> />
								<?php esc_html_e( 'Delete all tracked data and settings when the plugin is uninstalled', 'reseller-intent' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'reseller-intent' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:-8px;">
				<input type="hidden" name="action" value="rintent_send_digest_test" />
				<?php wp_nonce_field( 'rintent_send_digest_test' ); ?>
				<?php submit_button( __( 'Send test digest email', 'reseller-intent' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( Reseller_Intent_Import::legacy_table_exists() && ! Reseller_Intent_Import::already_imported() ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Import legacy data', 'reseller-intent' ); ?></h2>
				<p><?php esc_html_e( 'Events recorded by the Reseller Store Add-On were found on this site. Import them once to keep your history.', 'reseller-intent' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="rintent_import_legacy" />
					<?php wp_nonce_field( 'rintent_import_legacy' ); ?>
					<?php submit_button( __( 'Import events', 'reseller-intent' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

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
				'notice'      => isset( $_GET['rintent_notice'] ) ? sanitize_key( wp_unslash( $_GET['rintent_notice'] ) ) : '',
				'tzLabel'     => wp_timezone_string(),
				'accentColor' => (string) Reseller_Intent_Settings::get( 'accent_color' ),
			)
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
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

	private function get_dashboard_data( $range_key, $from = '', $to = '' ) {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();

		$bounded = ( 'all' !== $range_key );
		$custom  = ( 'custom' === $range_key );
		$now_ts  = time(); // NOT current_time(): wp_date() adds the site offset itself; both = double shift after 18:30 IST.
		$end     = ''; // exclusive upper bound, custom range only

		if ( $custom ) {
			// Dates are site-local calendar days; created_at is stored site-local.
			$len        = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
			$start      = $from . ' 00:00:00';
			$end        = gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00';
			$prev_start = gmdate( 'Y-m-d', strtotime( $from . ' -' . $len . ' days' ) ) . ' 00:00:00';
			$anchor_ts  = ( new DateTimeImmutable( $to . ' 12:00:00', wp_timezone() ) )->getTimestamp();
			$where      = $wpdb->prepare( ' AND created_at >= %s AND created_at < %s', $start, $end );
		} else {
			$len        = $bounded ? (int) $range_key : 0;
			$start      = $bounded ? wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( $len - 1 ) * DAY_IN_SECONDS ) ) : '';
			$prev_start = $bounded ? wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( ( 2 * $len ) - 1 ) * DAY_IN_SECONDS ) ) : '';
			$anchor_ts  = $now_ts;
			$where      = $bounded ? $wpdb->prepare( ' AND created_at >= %s', $start ) : '';
		}

		// KPIs: one aggregate query per window.
		$kpi_now  = $this->get_kpi_counts( $table_name, $start, $end );
		$kpi_prev = $bounded ? $this->get_kpi_counts( $table_name, $prev_start, $start ) : null;

		// TLD distribution.
		$tld_rows = $wpdb->get_results(
			"SELECT LOWER(SUBSTRING_INDEX(domain_query, '.', -1)) AS tld, SUM(event_count) AS hits
			FROM {$table_name}
			WHERE event_type = 'domain_search' AND domain_query LIKE '%.%'{$where}
			GROUP BY tld
			ORDER BY hits DESC
			LIMIT 40"
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
		$cart_row = $wpdb->get_row(
			"SELECT
				COALESCE(SUM(CASE WHEN items_count <= 1 THEN 1 ELSE 0 END),0) AS b1,
				COALESCE(SUM(CASE WHEN items_count = 2 THEN 1 ELSE 0 END),0) AS b2,
				COALESCE(SUM(CASE WHEN items_count = 3 THEN 1 ELSE 0 END),0) AS b3,
				COALESCE(SUM(CASE WHEN items_count >= 4 THEN 1 ELSE 0 END),0) AS b4,
				COUNT(*) AS total
			FROM {$table_name}
			WHERE event_type = 'continue_to_cart'{$where}",
			ARRAY_A
		);
		$cart_row   = is_array( $cart_row ) ? array_map( 'intval', $cart_row ) : array();
		$cart_sizes = array(
			array( 'label' => __( '1 domain', 'reseller-intent' ), 'count' => isset( $cart_row['b1'] ) ? $cart_row['b1'] : 0 ),
			array( 'label' => __( '2 domains', 'reseller-intent' ), 'count' => isset( $cart_row['b2'] ) ? $cart_row['b2'] : 0 ),
			array( 'label' => __( '3 domains', 'reseller-intent' ), 'count' => isset( $cart_row['b3'] ) ? $cart_row['b3'] : 0 ),
			array( 'label' => __( '4+ domains', 'reseller-intent' ), 'count' => isset( $cart_row['b4'] ) ? $cart_row['b4'] : 0 ),
		);

		// Repeat intent: searched 2+ times inside the window.
		$repeat_rows = $wpdb->get_results(
			"SELECT domain_query AS domain, SUM(event_count) AS hits
			FROM {$table_name}
			WHERE event_type = 'domain_search' AND domain_query <> ''{$where}
			GROUP BY domain_query
			HAVING hits >= 2
			ORDER BY hits DESC
			LIMIT 8"
		);
		$repeats = array();
		foreach ( $repeat_rows as $repeat_row ) {
			$repeats[] = array(
				'domain' => (string) $repeat_row->domain,
				'hits'   => (int) $repeat_row->hits,
			);
		}

		// Searches and cart clicks per source page.
		$pages = $this->get_page_breakdown( $table_name, $where );

		// Carted domains from items_json (bounded scan).
		$carted = $this->get_carted_breakdown( $table_name, $where );

		// Selection behavior.
		$selection = $this->get_selection_breakdown( $table_name, $where );

		// Availability + devices.
		$availability_row = $wpdb->get_row(
			"SELECT
				COALESCE(SUM(CASE WHEN is_available = 1 THEN event_count ELSE 0 END),0) AS avail,
				COALESCE(SUM(CASE WHEN is_available = 0 THEN event_count ELSE 0 END),0) AS taken
			FROM {$table_name}
			WHERE event_type = 'domain_search' AND is_available IS NOT NULL{$where}",
			ARRAY_A
		);
		$avail = isset( $availability_row['avail'] ) ? (int) $availability_row['avail'] : 0;
		$taken = isset( $availability_row['taken'] ) ? (int) $availability_row['taken'] : 0;

		$device_rows = $wpdb->get_results(
			"SELECT device, COALESCE(SUM(event_count),0) AS hits
			FROM {$table_name}
			WHERE event_type = 'domain_search' AND device IN ('mobile','desktop'){$where}
			GROUP BY device",
			ARRAY_A
		);
		$devices = array(
			'mobile'  => 0,
			'desktop' => 0,
		);
		foreach ( (array) $device_rows as $device_row ) {
			$device_key = isset( $device_row['device'] ) ? (string) $device_row['device'] : '';
			if ( isset( $devices[ $device_key ] ) ) {
				$devices[ $device_key ] = (int) $device_row['hits'];
			}
		}

		// Top countries (privacy-safe: 2-letter geo header codes, no IPs).
		$country_rows = $wpdb->get_results(
			"SELECT country, COALESCE(SUM(event_count),0) AS hits
			FROM {$table_name}
			WHERE event_type = 'domain_search' AND country <> ''{$where}
			GROUP BY country
			ORDER BY hits DESC
			LIMIT 8",
			ARRAY_A
		);
		$countries       = array();
		$countries_total = 0;
		foreach ( (array) $country_rows as $country_row ) {
			$hits              = isset( $country_row['hits'] ) ? (int) $country_row['hits'] : 0;
			$countries_total  += $hits;
			$countries[]       = array(
				'code' => isset( $country_row['country'] ) ? (string) $country_row['country'] : '',
				'hits' => $hits,
			);
		}

		// Recent search log: latest 100 in range; filtered/paged client-side.
		$recent_rows = $wpdb->get_results(
			"SELECT domain_query, created_at, is_available, device
			FROM {$table_name}
			WHERE event_type = 'domain_search' AND domain_query <> ''{$where}
			ORDER BY id DESC
			LIMIT 100"
		);
		$recent = array();
		foreach ( $recent_rows as $recent_row ) {
			$recent[] = array(
				'domain'    => (string) $recent_row->domain_query,
				'time'      => $this->format_datetime_local( (string) $recent_row->created_at ),
				'available' => ( null === $recent_row->is_available || '' === (string) $recent_row->is_available ) ? null : (bool) (int) $recent_row->is_available,
				'device'    => (string) $recent_row->device,
			);
		}

		return array(
			'range'      => $range_key,
			'bounded'    => $bounded,
			'rangeLabel' => $custom
				? sprintf( '%s – %s', wp_date( 'M j, Y', strtotime( $from . ' 12:00:00' ) ), wp_date( 'M j, Y', strtotime( $to . ' 12:00:00' ) ) )
				: ( $bounded
					/* translators: %d: number of days */
					? sprintf( __( 'Last %d days', 'reseller-intent' ), $len )
					: __( 'Lifetime', 'reseller-intent' ) ),
			'kpis'       => array(
				'now'  => $kpi_now,
				'prev' => $kpi_prev,
			),
			'tlds'       => array(
				'items' => $tlds,
				'total' => $tld_total,
			),
			'trend'      => $trend,
			'cartSizes'  => $cart_sizes,
			'repeats'    => $repeats,
			'pages'      => $pages,
			'carted'     => $carted,
			'selection'  => $selection,
			'availability' => array(
				'available' => $avail,
				'taken'     => $taken,
			),
			'devices'    => $devices,
			'countries'  => array(
				'items' => $countries,
				'total' => $countries_total,
			),
			'recent'     => $recent,
		);
	}

	private function get_trend_series( $table_name, $bounded, $len, $now_ts ) {
		global $wpdb;

		if ( $bounded ) {
			$start  = wp_date( 'Y-m-d 00:00:00', $now_ts - ( ( $len - 1 ) * DAY_IN_SECONDS ) );
			$rows   = $wpdb->get_results(
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
		$rows = $wpdb->get_results(
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
	 * grouped in SQL first, then merged by normalized path here — UTM
	 * variants of the same page collapse into one row.
	 */
	private function get_page_breakdown( $table_name, $where ) {
		global $wpdb;

		$page_rows = $wpdb->get_results(
			"SELECT page_url,
				COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN event_count ELSE 0 END),0) AS searches,
				COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS carts
			FROM {$table_name}
			WHERE event_type IN ('domain_search','continue_to_cart') AND page_url IS NOT NULL AND page_url <> ''{$where}
			GROUP BY page_url
			ORDER BY searches DESC
			LIMIT 200"
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

	private function get_carted_breakdown( $table_name, $where ) {
		global $wpdb;

		$json_rows = $wpdb->get_results(
			"SELECT items_json FROM {$table_name}
			WHERE event_type = 'continue_to_cart' AND items_json IS NOT NULL AND items_json <> ''{$where}
			ORDER BY id DESC
			LIMIT 500"
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
		foreach ( array_slice( $domain_counts, 0, 10, true ) as $domain => $count ) {
			$domains[] = array(
				'domain' => (string) $domain,
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
		);
	}

	private function get_selection_breakdown( $table_name, $where ) {
		global $wpdb;

		$totals = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
				COALESCE(SUM(CASE WHEN related_query <> '' AND ( related_query = domain_query OR domain_query LIKE CONCAT(related_query, '.%') ) THEN 1 ELSE 0 END),0) AS exact_hits
			FROM {$table_name}
			WHERE event_type = 'domain_select' AND domain_query <> ''{$where}",
			ARRAY_A
		);
		$total = isset( $totals['total'] ) ? (int) $totals['total'] : 0;
		$exact = isset( $totals['exact_hits'] ) ? (int) $totals['exact_hits'] : 0;

		$top_rows = $wpdb->get_results(
			"SELECT domain_query AS domain, SUM(event_count) AS hits
			FROM {$table_name}
			WHERE event_type = 'domain_select' AND domain_query <> ''{$where}
			GROUP BY domain_query
			ORDER BY hits DESC
			LIMIT 8"
		);
		$top = array();
		foreach ( $top_rows as $top_row ) {
			$top[] = array(
				'domain' => (string) $top_row->domain,
				'hits'   => (int) $top_row->hits,
			);
		}

		$pair_rows = $wpdb->get_results(
			"SELECT related_query AS searched, domain_query AS selected, COUNT(*) AS hits
			FROM {$table_name}
			WHERE event_type = 'domain_select' AND domain_query <> '' AND related_query <> ''
				AND related_query <> domain_query AND domain_query NOT LIKE CONCAT(related_query, '.%'){$where}
			GROUP BY related_query, domain_query
			ORDER BY hits DESC
			LIMIT 8"
		);
		$pairs = array();
		foreach ( $pair_rows as $pair_row ) {
			$pairs[] = array(
				'searched' => (string) $pair_row->searched,
				'selected' => (string) $pair_row->selected,
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
	 * Aggregate KPI counts for a window. Empty $start = lifetime.
	 */
	private function get_kpi_counts( $table_name, $start = '', $end = '' ) {
		global $wpdb;

		$where  = ' WHERE 1=1';
		$params = array();
		if ( '' !== $start ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $start;
		}
		if ( '' !== $end ) {
			$where   .= ' AND created_at < %s';
			$params[] = $end;
		}

		$sql = "SELECT
			COALESCE(SUM(CASE WHEN event_type = 'domain_search' THEN event_count ELSE 0 END),0) AS searches,
			COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN 1 ELSE 0 END),0) AS cart_clicks,
			COALESCE(SUM(CASE WHEN event_type = 'continue_to_cart' THEN items_count ELSE 0 END),0) AS domains_added,
			COUNT(DISTINCT CASE WHEN event_type = 'domain_search' AND domain_query <> '' THEN domain_query END) AS unique_searches
			FROM {$table_name}{$where}";
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'searches'        => isset( $row['searches'] ) ? (int) $row['searches'] : 0,
			'cartClicks'      => isset( $row['cart_clicks'] ) ? (int) $row['cart_clicks'] : 0,
			'domainsAdded'    => isset( $row['domains_added'] ) ? (int) $row['domains_added'] : 0,
			'uniqueSearches'  => isset( $row['unique_searches'] ) ? (int) $row['unique_searches'] : 0,
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

		$seconds = $this->resolve_clear_range( isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : '' );
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

		$seconds = $this->resolve_clear_range( isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : '' );
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

		$where = '';
		if ( 'custom' === $range_key ) {
			$end_excl = gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00';
			$where    = $wpdb->prepare( ' AND created_at >= %s AND created_at < %s', $from . ' 00:00:00', $end_excl );
		} elseif ( 'all' !== $range_key ) {
			$len   = (int) $range_key;
			$start = wp_date( 'Y-m-d 00:00:00', time() - ( ( $len - 1 ) * DAY_IN_SECONDS ) );
			$where = $wpdb->prepare( ' AND created_at >= %s', $start );
		}

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
					WHERE id < %d{$where}
					ORDER BY id DESC
					LIMIT %d",
					$last_id,
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
		printf( '<div><strong style="font-size:20px;">%s</strong><br /><span style="color:#787c82;">%s</span></div>', esc_html( number_format_i18n( $searches_today ) ), esc_html__( 'searches today', 'reseller-intent' ) );
		printf( '<div><strong style="font-size:20px;">%s</strong><br /><span style="color:#787c82;">%s</span></div>', esc_html( number_format_i18n( $searches_week ) ), esc_html__( 'searches, 7 days', 'reseller-intent' ) );
		printf( '<div><strong style="font-size:20px;">%s%%</strong><br /><span style="color:#787c82;">%s</span></div>', esc_html( number_format_i18n( $rate, 1 ) ), esc_html__( 'search → cart, 7 days', 'reseller-intent' ) );
		echo '</div>';
		printf(
			'<p style="margin-bottom:0;"><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Open the full dashboard →', 'reseller-intent' )
		);
	}
}
