<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time importer for sites that previously collected events with the
 * private "Reseller Store Add-On" (table {prefix}rstore_domain_events).
 * The importer copies rows into the Reseller Intent table, dropping the
 * legacy user_id column (Reseller Intent stores anonymous events only).
 */
final class Reseller_Intent_Import {
	const IMPORTED_OPTION = 'rintent_import_done';
	const BATCH_SIZE      = 5000;

	public function register() {
		add_action( 'admin_post_rintent_import_legacy', array( $this, 'handle_import' ) );
	}

	public static function legacy_table_exists() {
		global $wpdb;

		$legacy = $wpdb->prefix . 'rstore_domain_events';

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) === $legacy;
	}

	public static function already_imported() {
		return (bool) get_option( self::IMPORTED_OPTION );
	}

	public function handle_import() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'reseller-intent' ) );
		}

		check_admin_referer( 'rintent_import_legacy' );

		if ( self::already_imported() || ! self::legacy_table_exists() ) {
			$this->redirect( 'import_skipped' );
		}

		$legacy  = esc_sql( $wpdb->prefix . 'rstore_domain_events' );
		$target  = Reseller_Intent_DB::table_name();
		$total   = 0;
		$last_id = 0;

		while ( true ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$legacy} WHERE id > %d ORDER BY id ASC LIMIT %d", $last_id, self::BATCH_SIZE ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			if ( empty( $ids ) ) {
				break;
			}

			$batch_min = (int) $ids[0];
			$batch_max = (int) end( $ids );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$copied = (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$target}
						(event_type, domain_query, related_query, event_count, items_count, items_json, is_available, device, page_url, created_at)
					SELECT event_type, domain_query, related_query, event_count, items_count, items_json, is_available, device, page_url, created_at
					FROM {$legacy}
					WHERE id BETWEEN %d AND %d",
					$batch_min,
					$batch_max
				)
			);

			$total  += max( 0, $copied );
			$last_id = $batch_max;
		}

		update_option( self::IMPORTED_OPTION, $total, false );

		$this->redirect( 'imported_' . $total );
	}

	private function redirect( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'reseller-intent-settings',
					'rintent_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
