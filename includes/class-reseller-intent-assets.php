<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reseller_Intent_Assets {
	/**
	 * Load the tracker only on pages where the Reseller Store widget script
	 * is actually enqueued — zero footprint everywhere else.
	 */
	public function enqueue_assets() {
		if ( ! wp_script_is( 'reseller-store-js', 'enqueued' ) ) {
			return;
		}

		$base_url  = plugin_dir_url( RINTENT_FILE );
		$base_path = plugin_dir_path( RINTENT_FILE );

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
				'nonce'   => wp_create_nonce( 'rintent-track' ),
			)
		);
	}
}
