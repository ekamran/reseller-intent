<?php
/**
 * Abilities API functional checks, run inside a seeded WordPress via
 * `wp eval-file abilities-check.php --user=admin`. Prints one CHECK line
 * per assertion; the runner greps for FAIL.
 *
 * Proves: the category and every ability register through core, each one
 * executes through WP_Ability (so input/output schemas validate), the
 * numbers agree with the dashboard payload for the same window, and the
 * direct run() path the CLI uses gives the same answer as the registry.
 */

function acheck( $name, $ok, $detail = '' ) {
	printf( "CHECK %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : '  [' . $detail . ']' );
}

$has_api = function_exists( 'wp_register_ability' );

if ( ! $has_api ) {
	// Older WordPress: the API is absent by design, the plugin must stay
	// silent about it, and the direct path the CLI uses must still answer.
	acheck( 'no Abilities API on WP ' . get_bloginfo( 'version' ) . ', floor path', true );
	acheck( 'nothing registered, no fatals', ! function_exists( 'wp_has_ability' ) );
	$r = Reseller_Intent_Abilities::run( 'get-summary', array( 'range' => '30' ), false );
	acheck( 'run() works without the API', is_array( $r ) && isset( $r['kpis']['now']['searches'] ) );
	return;
}

acheck( 'core has the Abilities API (WP ' . get_bloginfo( 'version' ) . ')', true );

acheck( 'category registered', wp_has_ability_category( 'reseller-intent' ) );

$slugs = Reseller_Intent_Abilities::slugs();
$all   = true;
foreach ( $slugs as $slug ) {
	if ( ! wp_has_ability( 'reseller-intent/' . $slug ) ) {
		$all = false;
		acheck( 'registered: ' . $slug, false );
	}
}
acheck( 'all ' . count( $slugs ) . ' abilities registered', $all );

// Execute every read ability through core, with schema validation on.
$inputs = array(
	'get-summary'           => array( 'range' => '30' ),
	'get-top-searches'      => array( 'range' => '90', 'limit' => 5 ),
	'get-top-tlds'          => array( 'range' => 'all' ),
	'get-countries'         => array( 'range' => '30' ),
	'get-carted-domains'    => array( 'range' => '90' ),
	'get-product-adds'      => array( 'range' => '30', 'page' => '/' ),
	'get-transfer-searches' => array( 'range' => '7' ),
	'get-pages'             => array( 'range' => 'all' ),
	'get-trend'             => array( 'range' => '7' ),
	'get-recent-searches'   => array( 'range' => '30', 'limit' => 10 ),
	'get-tld-prices'        => null,
	'get-tracking-status'   => null,
);

$results = array();
foreach ( $inputs as $slug => $input ) {
	$ability = wp_get_ability( 'reseller-intent/' . $slug );
	$out     = $ability ? $ability->execute( $input ) : new WP_Error( 'missing', 'no ability' );
	$ok      = ! is_wp_error( $out ) && is_array( $out );
	acheck( 'executes through core: ' . $slug, $ok, is_wp_error( $out ) ? $out->get_error_code() . ': ' . $out->get_error_message() : '' );
	$results[ $slug ] = $out;
}

// Cross-check against the dashboard for the same window.
$admin  = new Reseller_Intent_Admin();
$method = new ReflectionMethod( 'Reseller_Intent_Admin', 'get_dashboard_data' );
$method->setAccessible( true );
$dash30 = $method->invoke( $admin, '30' );
$sum    = $results['get-summary'];

acheck( 'summary searches match the dashboard', $sum['kpis']['now']['searches'] === $dash30['kpis']['now']['searches'], $sum['kpis']['now']['searches'] . ' vs ' . $dash30['kpis']['now']['searches'] );
acheck( 'summary cart clicks match the dashboard', $sum['kpis']['now']['cartClicks'] === $dash30['kpis']['now']['cartClicks'] );
acheck( 'summary previous window matches the dashboard', $sum['kpis']['prev'] === $dash30['kpis']['prev'] );
acheck( 'summary outbound matches the dashboard', $sum['outbound'] === $dash30['outbound'] );
acheck( 'summary devices match the dashboard', $sum['devices'] === $dash30['devices'] );
acheck( 'summary availability matches the dashboard', $sum['availability'] === $dash30['availability'] );

$dash_all = $method->invoke( $admin, 'all' );
$tlds     = $results['get-top-tlds'];
$dash_tld = array();
foreach ( $dash_all['tlds']['items'] as $row ) {
	$dash_tld[ $row['label'] ] = $row['count'];
}
$ab_tld = array();
foreach ( $tlds['items'] as $row ) {
	$ab_tld[ $row['tld'] ] = $row['searches'];
}
acheck( 'top TLDs match the dashboard', $ab_tld === $dash_tld, wp_json_encode( $ab_tld ) . ' vs ' . wp_json_encode( $dash_tld ) );
acheck( 'top TLDs total matches the dashboard', $tlds['total'] === $dash_all['totals']['tlds'] );

$trend7 = $results['get-trend'];
$dash7  = $method->invoke( $admin, '7' );
acheck( 'trend matches the dashboard', $trend7['searches'] === $dash7['trend']['searches'] && $trend7['cart_clicks'] === $dash7['trend']['carts'] && count( $trend7['labels'] ) === 7 );

$recent = $results['get-recent-searches'];
acheck( 'recent searches honour the limit', count( $recent['items'] ) <= 10 && count( $recent['items'] ) > 0 );
acheck( 'recent rows carry no identifying fields', ! isset( $recent['items'][0]['ip'] ) && ! isset( $recent['items'][0]['user'] ) && isset( $recent['items'][0]['domain'] ) );

$status = $results['get-tracking-status'];
acheck( 'status reports events and version', $status['events'] > 0 && '' !== $status['version'] );

// The CLI path and the registry path agree.
$direct = Reseller_Intent_Abilities::run( 'get-top-tlds', array( 'range' => 'all' ) );
acheck( 'run() equals execute()', $direct === $tlds );

// Permission: a subscriber gets nothing.
$sub = wp_insert_user( array( 'user_login' => 'rintent_sub_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
$denied = wp_get_ability( 'reseller-intent/get-summary' )->execute( array( 'range' => '7' ) );
acheck( 'subscriber is refused', is_wp_error( $denied ), is_wp_error( $denied ) ? '' : 'got data' );
wp_set_current_user( 1 );
wp_delete_user( $sub );

// Input validation through core: a bad range key must be rejected by the schema.
$bad = wp_get_ability( 'reseller-intent/get-top-tlds' )->execute( array( 'range' => '14' ) );
acheck( 'schema rejects an unknown range key', is_wp_error( $bad ) );
$bad2 = wp_get_ability( 'reseller-intent/get-top-tlds' )->execute( array( 'range' => '30', 'bogus' => 1 ) );
acheck( 'schema rejects unknown input keys', is_wp_error( $bad2 ) );

// The write ability is marked as such and runs.
$refresh = wp_get_ability( 'reseller-intent/refresh-tld-prices' );
acheck( 'refresh is not readonly', false === $refresh->get_meta_item( 'annotations' )['readonly'] );
$rr = $refresh->execute();
acheck( 'refresh executes', ! is_wp_error( $rr ) && isset( $rr['refreshed_sets'] ), is_wp_error( $rr ) ? $rr->get_error_message() : '' );
