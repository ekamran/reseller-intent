<?php
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
/*
 * Playground verification: the working tree plugin on the SQLite runtime.
 * Every line is CHECK ok|FAIL. Written to /wordpress/pg-out/result.txt
 * (a host mount) and echoed.
 */
$out     = array();
$notices = array();
set_error_handler( function ( $no, $str, $file, $line ) use ( &$notices ) { $notices[] = $str . ' @ ' . basename( $file ) . ':' . $line; return true; } );
function pg( $name, $ok, $detail = '' ) { global $out; $out[] = sprintf( 'CHECK %s %s%s', $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : '  [' . $detail . ']' ); }
function err( $v ) { return is_wp_error( $v ) ? $v->get_error_code() . ': ' . $v->get_error_message() : ''; }

global $wpdb;
$pd = get_plugin_data( WP_PLUGIN_DIR . '/reseller-intent/reseller-intent.php', false, false );
pg( 'runtime WP ' . get_bloginfo( 'version' ) . ' PHP ' . PHP_VERSION . ' DB ' . ( defined( 'DB_ENGINE' ) ? DB_ENGINE : 'mysql' ), true );
pg( 'plugin active, version ' . $pd['Version'], is_plugin_active( 'reseller-intent/reseller-intent.php' ) );
pg( 'reseller store active', is_plugin_active( 'reseller-store/reseller-store.php' ) );
$events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rintent_events" );
pg( 'seeded events ' . $events, $events > 1000 );

pg( 'Abilities API present', function_exists( 'wp_register_ability' ) );
pg( 'category registered', function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'reseller-intent' ) );
$slugs = Reseller_Intent_Abilities::slugs();
$all   = true;
foreach ( $slugs as $s ) { if ( ! wp_has_ability( 'reseller-intent/' . $s ) ) { $all = false; pg( 'missing ' . $s, false ); } }
pg( 'all ' . count( $slugs ) . ' abilities registered', $all );

wp_set_current_user( 1 );
$inputs = array(
	'get-summary'           => array( 'range' => '30' ),
	'get-top-searches'      => array( 'range' => '90', 'limit' => 5 ),
	'get-top-tlds'          => array( 'range' => 'all' ),
	'get-countries'         => array( 'range' => '30' ),
	'get-carted-domains'    => array( 'range' => '90' ),
	'get-product-adds'      => array( 'range' => '30' ),
	'get-transfer-searches' => array( 'range' => '7' ),
	'get-pages'             => array( 'range' => 'all' ),
	'get-trend'             => array( 'range' => '7' ),
	'get-recent-searches'   => array( 'range' => '30', 'limit' => 10 ),
	'get-tld-prices'        => null,
	'get-tracking-status'   => null,
);
$results = array();
foreach ( $inputs as $slug => $input ) {
	$a   = wp_get_ability( 'reseller-intent/' . $slug );
	$r   = $a ? $a->execute( $input ) : new WP_Error( 'missing', 'no ability' );
	$sqlerr = substr( trim( preg_replace( "/\\s+/", " ", wp_strip_all_tags( $wpdb->last_error ) ) ), 0, 260 );
	$wpdb->last_error = '';
	pg( 'executes through core: ' . $slug, ! is_wp_error( $r ) && is_array( $r ), err( $r ) );
	pg( 'no SQL error: ' . $slug, '' === $sqlerr, $sqlerr );
	if ( isset( $r['items'] ) ) { pg( 'returns rows: ' . $slug, count( $r['items'] ) > 0, 'empty items' ); }
	if ( 'get-trend' === $slug && is_array( $r ) ) { pg( 'trend has searches', array_sum( $r['searches'] ) > 0 ); }
	$results[ $slug ] = $r;
}

try {
	$admin = new Reseller_Intent_Admin();
	$m     = new ReflectionMethod( 'Reseller_Intent_Admin', 'get_dashboard_data' );
	$m->setAccessible( true );
	$d30 = $m->invoke( $admin, '30' );
	pg( 'dashboard payload renders (30)', is_array( $d30 ) && isset( $d30['kpis']['now']['searches'] ) );
	pg( 'summary kpis equal dashboard', ! is_wp_error( $results['get-summary'] ) && $results['get-summary']['kpis'] === $d30['kpis'] );
	$dall = $m->invoke( $admin, 'all' );
	pg( 'dashboard payload renders (all)', is_array( $dall ) && isset( $dall['tlds']['items'] ) );
	$ab = array(); foreach ( $results['get-top-tlds']['items'] as $row ) { $ab[ $row['tld'] ] = $row['searches']; }
	$da = array(); foreach ( $dall['tlds']['items'] as $row ) { $da[ $row['label'] ] = $row['count']; }
	pg( 'top TLDs equal dashboard', $ab === $da );
	$d7 = $m->invoke( $admin, '7' );
	pg( 'trend equals dashboard', $results['get-trend']['searches'] === $d7['trend']['searches'] && count( $results['get-trend']['labels'] ) === 7 );
	$dp = $m->invoke( $admin, '30', '', '', '/find-a-domain/' );
	$ps = is_array( $dp ) ? (int) $dp['kpis']['now']['searches'] : -1;
	$sqlerr = $wpdb->last_error;
	$wpdb->last_error = '';
	$like = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rintent_events WHERE event_type = 'domain_search' AND page_url LIKE '%find-a-domain%'" );
	pg( 'dashboard with page filter renders', $ps > 0 && $ps < $d30['kpis']['now']['searches'], 'page=' . $ps . ' all=' . $d30['kpis']['now']['searches'] . ' likeRows=' . $like . ' lastError=' . substr( trim( preg_replace( "/\\s+/", " ", wp_strip_all_tags( $sqlerr ) ) ), 0, 700 ) . ' options=' . wp_json_encode( array_slice( $d30['pageOptions'] ?? array(), 0, 3 ) ) );
} catch ( Throwable $e ) { pg( 'dashboard path threw', false, get_class( $e ) . ': ' . $e->getMessage() ); }

$custom = Reseller_Intent_Abilities::run( 'get-top-searches', array( 'range' => 'custom', 'from' => gmdate( 'Y-m-d', strtotime( '-20 days' ) ), 'to' => gmdate( 'Y-m-d' ), 'page' => '/find-a-domain/', 'limit' => 5 ) );
pg( 'custom range with page filter', ! is_wp_error( $custom ) && isset( $custom['items'] ) && count( $custom['items'] ) <= 5, err( $custom ) );
$bad = Reseller_Intent_Abilities::run( 'get-summary', array( 'range' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-01' ) );
pg( 'bad custom range refused with 400', is_wp_error( $bad ) && 400 === ( $bad->get_error_data()['status'] ?? 0 ) );

try {
	ob_start();
	$admin->render_glance_widget();
	$glance = ob_get_clean();
	pg( 'glance widget renders', strlen( $glance ) > 200 && false === stripos( $glance, 'fatal' ) );
} catch ( Throwable $e ) { ob_end_clean(); pg( 'glance widget threw', false, get_class( $e ) . ': ' . $e->getMessage() ); }

$sub = wp_insert_user( array( 'user_login' => 'pg_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
$denied = wp_get_ability( 'reseller-intent/get-summary' )->execute( array( 'range' => '7' ) );
pg( 'subscriber refused', is_wp_error( $denied ) );
wp_set_current_user( 1 );

pg( 'no PHP notices or warnings during checks', empty( $notices ), implode( ' | ', array_slice( $notices, 0, 3 ) ) );
$text = implode( "\n", $out ) . "\n";
@file_put_contents( '/wordpress/pg-out/result.txt', $text );
echo $text;
