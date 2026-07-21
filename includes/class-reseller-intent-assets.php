<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reseller_Intent_Assets {
	/**
	 * Load frontend assets only on pages where the Reseller Store widget
	 * script is actually enqueued, zero footprint everywhere else.
	 */
	public function enqueue_assets() {
		if ( ! wp_script_is( 'reseller-store-js', 'enqueued' ) ) {
			return;
		}

		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

		// Tracking (always on while the plugin is active).
		wp_enqueue_script(
			'reseller-intent-tracker',
			$base_url . 'assets/js/tracker.js',
			array( 'jquery', 'reseller-store-js' ),
			filemtime( $base_path . 'assets/js/tracker.js' ),
			true
		);

		wp_localize_script(
			'reseller-intent-tracker',
			'resellerIntent',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);

		// Optional widget styling + enhancements.
		if ( ! Reseller_Intent_Settings::get( 'style_widget' ) ) {
			return;
		}

		wp_enqueue_style(
			'reseller-intent-widget',
			$base_url . 'assets/css/widget.css',
			array( 'reseller-store-css' ),
			filemtime( $base_path . 'assets/css/widget.css' )
		);

		$accent = (string) Reseller_Intent_Settings::get( 'accent_color' );

		wp_add_inline_style(
			'reseller-intent-widget',
			sprintf(
				'body{--rintent-accent:%1$s;--rintent-accent-hover:color-mix(in srgb, %1$s 78%%, #000);--rintent-accent-text:%2$s;--rintent-accent-dark:%3$s;--rintent-accent-dark-text:%4$s;}',
				$accent,
				Reseller_Intent_Settings::accent_text_color(),
				Reseller_Intent_Settings::accent_dark_color(),
				Reseller_Intent_Settings::accent_dark_text_color()
			)
		);

		wp_enqueue_script(
			'reseller-intent-widget',
			$base_url . 'assets/js/widget.js',
			array( 'jquery', 'reseller-store-js' ),
			filemtime( $base_path . 'assets/js/widget.js' ),
			true
		);

		wp_localize_script(
			'reseller-intent-widget',
			'resellerIntentWidget',
			array(
				'skeletons'  => (bool) Reseller_Intent_Settings::get( 'widget_skeletons' ),
				'clearAll'   => (bool) Reseller_Intent_Settings::get( 'widget_clear_all' ),
				'clearLabel' => '' !== (string) Reseller_Intent_Settings::get( 'clear_all_label' )
					? (string) Reseller_Intent_Settings::get( 'clear_all_label' )
					: __( 'Clear All', 'reseller-intent' ),
			)
		);
	}
}
