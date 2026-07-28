<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reseller_Intent_DB {
	const TABLE_SUFFIX      = 'rintent_events';
	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = 'rintent_db_version';

	public static function table_name() {
		global $wpdb;

		return esc_sql( $wpdb->prefix . self::TABLE_SUFFIX );
	}

	public static function activate() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		/*
		 * Privacy by design: no IP address, no user id, no cookie/session id.
		 * Rows are anonymous interaction events only.
		 */
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(50) NOT NULL,
			domain_query VARCHAR(255) NULL,
			related_query VARCHAR(191) NOT NULL DEFAULT '',
			event_count INT UNSIGNED NOT NULL DEFAULT 1,
			items_count INT UNSIGNED NOT NULL DEFAULT 0,
			items_json LONGTEXT NULL,
			is_available TINYINT(1) NULL,
			device VARCHAR(16) NOT NULL DEFAULT '',
			country CHAR(2) NOT NULL DEFAULT '',
			page_url TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY domain_query (domain_query),
			KEY event_type_domain (event_type,domain_query),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		// dbDelta() reports nothing useful when the CREATE is refused (a DB
		// user without CREATE, a full disk, a quota). Only record the version
		// once the table is really there, so the next request retries instead
		// of trusting a schema that was never written.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( $exists ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	public static function maybe_create_table() {
		// Version option is autoloaded, a match means the schema is current,
		// so this is a zero-query check on normal requests.
		if ( (string) get_option( self::DB_VERSION_OPTION, '' ) === self::DB_VERSION ) {
			return;
		}

		self::activate();
	}

	/**
	 * Self-heal: recreate the table if it vanished while the version option
	 * survived (host migration that skipped custom tables, manual drop, DB
	 * restore). Called from the plugin's own admin pages only, so the extra
	 * SHOW TABLES query never runs on the frontend.
	 */
	public static function ensure_table() {
		global $wpdb;

		$table_name = self::table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		delete_option( self::DB_VERSION_OPTION );
		self::maybe_create_table();
	}

	/**
	 * Count events newer than the given cutoff (or all events).
	 *
	 * @param int $seconds Look-back window in seconds; 0 = all time.
	 * @return int
	 */
	public static function count_events_since( $seconds ) {
		global $wpdb;

		$table_name = self::table_name();

		if ( $seconds < 1 ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$cutoff = wp_date( 'Y-m-d H:i:s', time() - $seconds );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Delete events newer than the given cutoff (browser-style "clear data"),
	 * or truncate everything for all-time.
	 *
	 * @param int $seconds Look-back window in seconds; 0 = all time.
	 * @return int Rows deleted.
	 */
	public static function delete_events_since( $seconds ) {
		global $wpdb;

		$table_name = self::table_name();

		if ( $seconds < 1 ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			// TRUNCATE needs the DROP privilege. Hosts that withhold it would
			// otherwise leave every row in place while the screen reported a
			// successful clear, so fall back to a plain DELETE.
			if ( false === $wpdb->query( "TRUNCATE TABLE {$table_name}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return (int) $wpdb->query( "DELETE FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}

			return $count;
		}

		$cutoff = wp_date( 'Y-m-d H:i:s', time() - $seconds );

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table_name} WHERE created_at >= %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Retention cron: delete events OLDER than the configured window.
	 */
	public static function run_auto_purge() {
		global $wpdb;

		$days = Reseller_Intent_Settings::get( 'retention_days' );

		if ( $days < 1 ) {
			return;
		}

		$table_name = self::table_name();
		$cutoff     = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table_name} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
