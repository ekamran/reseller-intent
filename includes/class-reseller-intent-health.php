<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Site Health tests. Surfaces the silent failure modes (missing
 * table, dead tracking, unscheduled crons, missing Reseller Store setup)
 * where site owners already look when something feels off.
 */
final class Reseller_Intent_Health {

	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	public function add_tests( $tests ) {
		$tests['direct']['rintent_table']    = array(
			'label' => __( 'Reseller Intent events table', 'reseller-intent' ),
			'test'  => array( $this, 'test_table' ),
		);
		$tests['direct']['rintent_tracking'] = array(
			'label' => __( 'Reseller Intent tracking', 'reseller-intent' ),
			'test'  => array( $this, 'test_tracking' ),
		);
		$tests['direct']['rintent_store']    = array(
			'label' => __( 'Reseller Store connection', 'reseller-intent' ),
			'test'  => array( $this, 'test_store' ),
		);

		return $tests;
	}

	private function result( $test, $status, $label, $description ) {
		return array(
			'label'       => $label,
			'status'      => $status, // One of: good, recommended, critical.
			'badge'       => array(
				'label' => __( 'Reseller Intent', 'reseller-intent' ),
				'color' => 'blue',
			),
			'description' => '<p>' . $description . '</p>',
			'actions'     => '',
			'test'        => $test,
		);
	}

	public function test_table() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( $exists ) {
			return $this->result(
				'rintent_table',
				'good',
				__( 'The Reseller Intent events table exists', 'reseller-intent' ),
				esc_html__( 'Search events have somewhere to go.', 'reseller-intent' )
			);
		}

		return $this->result(
			'rintent_table',
			'critical',
			__( 'The Reseller Intent events table is missing', 'reseller-intent' ),
			esc_html__( 'Tracking cannot store anything. Open any Reseller Intent admin page, the table is recreated automatically. If it stays missing, check that the database user may create tables.', 'reseller-intent' )
		);
	}

	public function test_tracking() {
		global $wpdb;

		$table_name = Reseller_Intent_DB::table_name();
		$last       = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $last ) {
			return $this->result(
				'rintent_tracking',
				'recommended',
				__( 'No search events recorded yet', 'reseller-intent' ),
				esc_html__( 'Fine on a new site. If the domain search widget is live and this stays empty, check for JavaScript errors or a firewall blocking admin-ajax.php.', 'reseller-intent' )
			);
		}

		$age = strtotime( current_time( 'mysql' ) ) - (int) strtotime( $last );

		if ( $age > 7 * DAY_IN_SECONDS ) {
			return $this->result(
				'rintent_tracking',
				'recommended',
				__( 'No search events for over a week', 'reseller-intent' ),
				esc_html__( 'Could just be quiet traffic. Worth a quick look: is the search widget still on the page, and does a test search appear on the dashboard?', 'reseller-intent' )
			);
		}

		return $this->result(
			'rintent_tracking',
			'good',
			__( 'Tracking is receiving events', 'reseller-intent' ),
			esc_html__( 'Recent search activity is being recorded.', 'reseller-intent' )
		);
	}

	public function test_store() {
		if ( function_exists( 'rstore_is_setup' ) && rstore_is_setup() ) {
			return $this->result(
				'rintent_store',
				'good',
				__( 'Reseller Store is connected', 'reseller-intent' ),
				esc_html__( 'The GoDaddy Reseller Store plugin is active and set up.', 'reseller-intent' )
			);
		}

		return $this->result(
			'rintent_store',
			'recommended',
			__( 'Reseller Store is not set up', 'reseller-intent' ),
			esc_html__( 'Reseller Intent tracks the Reseller Store domain search widget, connect the Reseller Store plugin to your reseller account first.', 'reseller-intent' )
		);
	}
}
