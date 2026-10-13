<?php
/**
 * Standalone harness for the 2.2.1 TLD strip + Site Health fixes.
 * Loads the real class files against WordPress stubs. No database, no network.
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'RINTENT_FILE', __FILE__ );

$GLOBALS['opts']       = array();
$GLOBALS['transients'] = array();
$GLOBALS['fetches']    = 0;
$GLOBALS['enqueued']   = 0;

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function __( $s, $d = null ) { return $s; }
function wp_next_scheduled( $h ) { return $GLOBALS['next_scheduled'] ?? ( time() + 3600 ); }
function wp_schedule_event() { return true; }
function wp_clear_scheduled_hook() { return true; }
function wp_style_is( $h, $l = 'enqueued' ) { return true; }
function wp_enqueue_style() { ++$GLOBALS['enqueued']; }
function wp_add_inline_style() {}
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/reseller-intent/'; }
function plugin_dir_path( $f ) { return __DIR__ . '/'; }
function human_time_diff( $a, $b = null ) { return '4 hours'; }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function shortcode_atts( $pairs, $atts, $sc = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function wp_remote_get() {
	++$GLOBALS['fetches'];
	$mode = $GLOBALS['remote_mode'] ?? '403';
	if ( 'transport' === $mode ) { return new WP_Error_Stub(); }
	if ( '200' === $mode ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{"exactMatchDomain":{"available":true,"listPrice":"$9.99"}}' ); }
	if ( 'suggest' === $mode ) {
		// Probe name taken; suggestions carry one wrong-TLD row, one row
		// with no price, then the one that should win.
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"exactMatchDomain":{"available":false},"suggestedDomains":[{"domain":"probe-x.net","listPrice":"$4.44"},{"domain":"probe-y.com"},{"domain":"probe-z.com","listPrice":"$7.77"}]}',
		);
	}
	return array( 'response' => array( 'code' => 403 ), 'body' => 'RBAC: access denied' );
}
function is_wp_error( $t ) { return $t instanceof WP_Error_Stub || $t instanceof WP_Error; }
function wp_kses_post( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : $ts ); }
function is_admin_bar_showing() { return $GLOBALS['bar_showing'] ?? true; }
function current_user_can( $cap ) { return $GLOBALS['user_can'] ?? true; }
function _n_noop( $singular, $plural, $domain = null ) { return array( 'singular' => $singular, 'plural' => $plural ); }
function translate_nooped_plural( $nooped, $count, $domain = null ) { return 1 === $count ? $nooped['singular'] : $nooped['plural']; }
class Fake_Admin_Bar {
	public $nodes = array();
	public $groups = array();
	public function add_node( $a ) { $this->nodes[] = $a; }
	public function add_group( $a ) { $this->groups[] = $a; }
}

function is_admin() { return $GLOBALS['is_admin'] ?? false; }
function wp_doing_cron() { return $GLOBALS['doing_cron'] ?? false; }
function wp_schedule_single_event( $t, $h, $a = array() ) { $GLOBALS['single_events'][] = $h; return true; }
class WP_Error_Stub { }

function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, $dec ); }
function _n( $a, $b, $n, $d = null ) { return 1 === $n ? $a : $b; }
function admin_url( $p ) { return '/wp-admin/' . $p; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function rawurlencode_stub( $s ) { return rawurlencode( $s ); }

class Reseller_Intent_Settings {
	public static function get( $k ) {
		if ( 'admin_bar' === $k ) { return $GLOBALS['admin_bar_setting'] ?? true; }
		if ( 'style_widget' === $k ) { return $GLOBALS['style_widget_setting'] ?? true; }
		return 0;
	}
	public static function accent_color() { return '#2456C4'; }
	public static function accent_ink_color() { return '#fff'; }
	public static function accent_dark_color() { return '#1D4390'; }
}
class Reseller_Intent_Admin { const PAGE_SLUG = 'reseller-intent';
	public static function capability() { return 'manage_options'; } }
class Reseller_Intent_DB {
	public static function table_name() { return 'wp_rintent_events'; }
	public static function count_events_since( $s ) { return 100; }
	public static function ensure_table() {}
	public static function activate() { $GLOBALS['db_activated'] = true; }
}

function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $hook ][] = $cb; return true; }
function apply_filters( $hook, $value ) { return $value; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function wp_unslash( $v ) { return $v; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=stub'; }

$base = __DIR__ . '/../../includes/';
require $base . 'class-reseller-intent-tld-strip.php';
require $base . 'class-reseller-intent-health.php';
require $base . 'class-reseller-intent-adminbar.php';
require $base . 'class-reseller-intent.php';
require $base . 'class-reseller-intent-reports.php';
require $base . 'class-reseller-intent-abilities.php';
require $base . 'class-reseller-intent-cli.php';

/* ------------------------------------------------------------------ */

$pass = 0;
$fail = 0;
function check( $name, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	$ok ? $pass++ : $fail++;
	printf( "%s  %s\n", $ok ? ' ok ' : 'FAIL', $name );
	if ( ! $ok ) {
		echo "        got:  " . json_encode( $got ) . "\n";
		echo "        want: " . json_encode( $want ) . "\n";
	}
}

$strip = new Reseller_Intent_TLD_Strip();
$rlg   = new ReflectionMethod( $strip, 'remember_last_good' );
$rlg->setAccessible( true );
$ck    = new ReflectionMethod( $strip, 'cache_key' );
$ck->setAccessible( true );

echo "\n== A. remember_last_good merges instead of clobbering ==\n";

// A1: a null in the new fetch must not destroy a stored price.
$set = array( '.com', '.org' );
$GLOBALS['opts'] = array( 'rintent_tld_last_good' => array( md5( '.com,.org' ) => array( '.com' => '$13.99', '.org' => '$15.99' ) ) );
$merged = $rlg->invoke( $strip, $set, array( '.com' => '$13.99', '.org' => null ) );
check( 'A1 partial failure keeps the old .org price', $merged, array( '.com' => '$13.99', '.org' => '$15.99' ) );

// A2: a real new price still wins.
$merged = $rlg->invoke( $strip, $set, array( '.com' => '$14.99', '.org' => '$16.99' ) );
check( 'A2 fresh prices replace stored ones', $merged, array( '.com' => '$14.99', '.org' => '$16.99' ) );

// A3: a TLD dropped from the shortcode must not linger.
$merged = $rlg->invoke( $strip, array( '.com' ), array( '.com' => '$14.99' ) );
check( 'A3 removed TLD is not carried forward', $merged, array( '.com' => '$14.99' ) );

// A4: first ever fetch, partial: the failed key is still present, as null.
$GLOBALS['opts'] = array();
$merged = $rlg->invoke( $strip, array( '.com', '.io' ), array( '.com' => '$13.99', '.io' => null ) );
check( 'A4 first fetch keeps the failed TLD as null', $merged, array( '.com' => '$13.99', '.io' => null ) );

// A5: LAST_REFRESH is stamped.
check( 'A5 last refresh stamped', get_option( 'rintent_tld_last_refresh' ) > 0, true );

echo "\n== B. render() leaves out priceless TLDs ==\n";

function render_with( $strip, $ck, $tlds, $prices, $more = true ) {
	$GLOBALS['transients'] = array();
	$GLOBALS['fetches']    = 0;
	$GLOBALS['enqueued']   = 0;
	$GLOBALS['transients'][ $ck->invoke( $strip, $tlds ) ] = $prices;
	$atts = array( 'tlds' => implode( ',', $tlds ) );
	if ( $more ) {
		$atts['more_url']   = '/domains/';
		$atts['more_label'] = '560+ more TLDs';
	}
	return $strip->render( $atts );
}

$html = render_with( $strip, $ck, array( '.com', '.in', '.org', '.net', '.io' ), array( '.com' => '$13.99', '.in' => null, '.org' => null, '.net' => null, '.io' => null ) );
check( 'B1 only the priced pill renders', substr_count( $html, 'class="rintent-tld"' ), 1 );
check( 'B1 no bare .io in the markup', strpos( $html, '.io' ) === false, true );
check( 'B1 the more link survives', strpos( $html, '560+ more TLDs' ) !== false, true );

$html = render_with( $strip, $ck, array( '.com', '.in' ), array( '.com' => null, '.in' => null ) );
check( 'B2 nothing priced renders nothing at all', $html, '' );
check( 'B2 no CSS enqueued for an empty strip', $GLOBALS['enqueued'], 0 );
check( 'B2 no network call on the render path', $GLOBALS['fetches'], 0 );

$html = render_with( $strip, $ck, array( '.com', '.in' ), array( '.com' => '$13.99', '.in' => '$9.99' ) );
check( 'B3 fully priced set renders every pill', substr_count( $html, 'class="rintent-tld"' ), 2 );
check( 'B3 prices present', substr_count( $html, '<i>' ), 2 );

echo "\n== C. Site Health finds the missing prices ==\n";

$health = new Reseller_Intent_Health();
$tw     = new ReflectionMethod( $health, 'tlds_without_prices' );
$tw->setAccessible( true );
$cp     = new ReflectionMethod( $health, 'check_prices' );
$cp->setAccessible( true );

// check_prices() is private now that the six checks live behind one test.
function price_result( $cp, $health ) {
	$r = $cp->invoke( $health );
	return array( 'status' => $r['status'], 'label' => $r['label'], 'description' => $r['body'] );
}

$GLOBALS['opts'] = array(
	'rintent_tld_strip_sets' => array(
		'setA' => array( '.com', '.in', '.org', '.net', '.io' ),
		'setB' => array( '.com', '.in' ),
	),
	'rintent_tld_last_good'  => array(
		'setA' => array( '.com' => '$13.99', '.in' => null, '.org' => null, '.net' => null, '.io' => null ),
		'setB' => array( '.com' => '$13.99', '.in' => '$9.99' ),
	),
	'rintent_tld_last_refresh' => time() - 3600,
);
check( 'C1 missing TLDs listed, deduped across sets', $tw->invoke( $health ), array( '.in', '.org', '.net', '.io' ) );

$r = price_result( $cp, $health );
check( 'C1 status is recommended, not good', $r['status'], 'recommended' );
check( 'C1 label names the problem', $r['label'], 'Some TLD prices are missing' );
check( 'C1 description lists the TLDs', strpos( $r['description'], '.in, .org, .net, .io' ) !== false, true );

// C2: a set never fetched at all counts as entirely missing.
$GLOBALS['opts']['rintent_tld_last_good'] = array();
check( 'C2 an unfetched set reports every TLD', $tw->invoke( $health ), array( '.com', '.in', '.org', '.net', '.io' ) );

// C3: everything priced goes back to good.
$GLOBALS['opts']['rintent_tld_last_good'] = array(
	'setA' => array( '.com' => '$13.99', '.in' => '$9.99', '.org' => '$15.99', '.net' => '$17.99', '.io' => '$76.99' ),
	'setB' => array( '.com' => '$13.99', '.in' => '$9.99' ),
);
check( 'C3 nothing missing', $tw->invoke( $health ), array() );
$r = price_result( $cp, $health );
check( 'C3 status good', $r['status'], 'good' );
check( 'C3 label is fresh', $r['label'], 'TLD prices are fresh' );

// C4: staleness still wins over the missing-price branch.
$GLOBALS['opts']['rintent_tld_last_refresh'] = time() - ( 5 * DAY_IN_SECONDS );
$GLOBALS['opts']['rintent_tld_last_good']    = array( 'setA' => array( '.com' => '$13.99' ) );
$r = price_result( $cp, $health );
check( 'C4 stale is reported before missing', $r['label'], 'TLD prices are going stale' );

// C5: no strip on the site short-circuits to good.
$GLOBALS['opts']['rintent_tld_strip_sets'] = array();
$r = price_result( $cp, $health );
check( 'C5 no strip means nothing to check', $r['label'], 'No TLD price strip on the site' );

// C6: strip present but never a single successful fetch.
$GLOBALS['opts']['rintent_tld_strip_sets']   = array( 'setA' => array( '.com' ) );
$GLOBALS['opts']['rintent_tld_last_refresh'] = 0;
$r = price_result( $cp, $health );
check( 'C6 never refreshed still reported', $r['label'], 'TLD prices have never refreshed' );

echo "\n== D. test_all() composes the six checks ==\n";

class FakeWpdbD {
	public $show_tables_calls = 0;
	public function prepare( $q, ...$a ) { return $q; }
	public function get_var( $q ) {
		if ( false !== strpos( $q, 'SHOW TABLES' ) ) {
			++$this->show_tables_calls;
			return ( $GLOBALS['table_exists'] ?? true ) ? 'wp_rintent_events' : null;
		}
		if ( false !== strpos( $q, 'MAX(created_at)' ) ) {
			return $GLOBALS['last_event'] ?? gmdate( 'Y-m-d H:i:s' );
		}
		return null;
	}
}
$GLOBALS['wpdb']         = new FakeWpdbD();
$GLOBALS['table_exists'] = true;
function rstore_is_setup() { return $GLOBALS['rstore_setup'] ?? true; }

$all_good = array(
	'rintent_tld_strip_sets'   => array( 'setA' => array( '.com' ) ),
	'rintent_tld_last_good'    => array( 'setA' => array( '.com' => '$13.99' ) ),
	'rintent_tld_last_refresh' => time() - 3600,
);

// Fresh instances per scenario: the table-exists memo is per-request by
// design, and the harness simulates several requests.
$health = new Reseller_Intent_Health();
// D1: nothing wrong.
$GLOBALS['opts'] = $all_good;
$GLOBALS['rstore_setup'] = true;
$GLOBALS['table_exists'] = true;
$r = $health->test_all();
check( 'D1 status good when every check passes', $r['status'], 'good' );
check( 'D1 headline says healthy', $r['label'], 'Reseller Intent is healthy' );
check( 'D1 all six lines still listed', substr_count( $r['description'], '<li' ), 6 );
check( 'D1 registers under one test id', $r['test'], 'rintent_health' );
check( 'D1 SHOW TABLES ran once, not twice', $GLOBALS['wpdb']->show_tables_calls, 1 );

$health = new Reseller_Intent_Health();
// D2: exactly one problem keeps its own headline.
$GLOBALS['opts'] = $all_good;
$GLOBALS['rstore_setup'] = false;
$r = $health->test_all();
check( 'D2 one issue -> recommended', $r['status'], 'recommended' );
check( 'D2 one issue keeps its own label', $r['label'], 'Reseller Store is not set up' );
check( 'D2 no "and more" on a single issue', strpos( $r['label'], 'more' ), false );

$health = new Reseller_Intent_Health();
// D3: several problems -> worst named, rest counted.
$GLOBALS['opts'] = array(
	'rintent_tld_strip_sets'   => array( 'setA' => array( '.com', '.org' ) ),
	'rintent_tld_last_good'    => array( 'setA' => array( '.com' => '$13.99', '.org' => null ) ),
	'rintent_tld_last_refresh' => time() - 3600,
);
$GLOBALS['rstore_setup'] = false;
$r = $health->test_all();
check( 'D3 several issues -> recommended', $r['status'], 'recommended' );
check( 'D3 headline names one and counts the rest', (bool) preg_match( '/, and \d+ more$/', $r['label'] ), true );

$health = new Reseller_Intent_Health();
// D4: critical outranks recommended and is the one named.
$GLOBALS['table_exists'] = false;
$r = $health->test_all();
check( 'D4 critical wins the overall status', $r['status'], 'critical' );
check( 'D4 the critical finding is the headline', strpos( $r['label'], 'The events table is missing' ) === 0, true );
check( 'D4 the recommended ones are not promoted', strpos( $r['label'], 'Reseller Store' ), false );

// D5: same input, same headline, every time.
$labels = array();
for ( $i = 0; $i < 5; $i++ ) { $labels[] = $health->test_all()['label']; }
check( 'D5 headline is deterministic', count( array_unique( $labels ) ), 1 );

$GLOBALS['table_exists'] = true;


// D6: the panel must not use physical offsets. This plugin ships to RTL
// admins, and left/padding-left would strand every dot on the wrong side.
$GLOBALS['opts'] = $all_good;
$GLOBALS['rstore_setup'] = true;
$GLOBALS['table_exists'] = true;
$desc = $health->test_all()['description'];
check( 'D6 no physical left offset in the markup', preg_match( '/(^|[;"\s])left\s*:/', $desc ), 0 );
check( 'D6 no physical padding-left either', strpos( $desc, 'padding-left' ), false );
check( 'D6 uses inset-inline-start', strpos( $desc, 'inset-inline-start:0' ) !== false, true );
check( 'D6 uses padding-inline-start', strpos( $desc, 'padding-inline-start:' ) !== false, true );


echo "\n== E. first render never blocks a visitor ==\n";

// E1: frontend, nothing cached, nothing remembered -> zero remote calls,
// a scheduled one-off, and an empty strip rather than a hung page.
$GLOBALS['opts'] = array(); $GLOBALS['transients'] = array();
$GLOBALS['opts']['rstore_pl_id'] = 487116;
$GLOBALS['is_admin'] = false; $GLOBALS['doing_cron'] = false;
$GLOBALS['fetches'] = 0; $GLOBALS['single_events'] = array(); $GLOBALS['enqueued'] = 0;
$GLOBALS['next_scheduled'] = false;
$html = $strip->render( array( 'tlds' => '.com,.in' ) );
check( 'E1 no remote call on the frontend first render', $GLOBALS['fetches'], 0 );
check( 'E1 a one-off refresh was scheduled', $GLOBALS['single_events'], array( 'rintent_tld_prefetch' ) );
check( 'E1 renders nothing rather than waiting', $html, '' );

// E2: admin context still fetches inline (the Shortcodes preview).
$GLOBALS['opts'] = array( 'rstore_pl_id' => 487116 ); $GLOBALS['transients'] = array();
$GLOBALS['is_admin'] = true; $GLOBALS['fetches'] = 0; $GLOBALS['remote_mode'] = '200';
$html = $strip->render( array( 'tlds' => '.com,.in' ) );
check( 'E2 admin preview fetches inline', $GLOBALS['fetches'] >= 2, true );
check( 'E2 prices render', substr_count( $html, '$9.99' ), 2 );
$GLOBALS['is_admin'] = false; $GLOBALS['remote_mode'] = '403';
unset( $GLOBALS['next_scheduled'] );

echo "\n== F. transport failure ends the sweep early ==\n";

$fs = new ReflectionMethod( $strip, 'fetch_set' );
$fs->setAccessible( true );

// F1: timeouts. Five TLDs, first request dies in transport -> one call only.
$GLOBALS['opts'] = array( 'rstore_pl_id' => 487116 );
$GLOBALS['remote_mode'] = 'transport'; $GLOBALS['fetches'] = 0;
$got = $fs->invoke( $strip, array( '.com', '.in', '.org', '.net', '.io' ) );
check( 'F1 one request, not five', $GLOBALS['fetches'], 1 );
check( 'F1 every TLD still keyed, as null', $got, array( '.com'=>null, '.in'=>null, '.org'=>null, '.net'=>null, '.io'=>null ) );

// F2: fast HTTP errors do NOT bail; each TLD gets its chance.
$GLOBALS['remote_mode'] = '403'; $GLOBALS['fetches'] = 0;
$got = $fs->invoke( $strip, array( '.com', '.in', '.org' ) );
check( 'F2 a 403 answers fast and the sweep continues', $GLOBALS['fetches'], 3 );

// F3: the flag resets between calls, a healthy sweep after a dead one runs fully.
$GLOBALS['remote_mode'] = '200'; $GLOBALS['fetches'] = 0;
$got = $fs->invoke( $strip, array( '.com', '.in' ) );
check( 'F3 healthy sweep completes after a failed one', $GLOBALS['fetches'], 2 );
check( 'F3 prices come back', $got, array( '.com'=>'$9.99', '.in'=>'$9.99' ) );

// F4: probe name taken -> the suggestion fallback picks the first priced
// suggestion on the SAME TLD, skipping wrong TLDs and unpriced rows.
// This path once carried a PHP 8-only call the matrix could not catch
// (no PHP 7.4 docker image left), so it is pinned here.
$GLOBALS['remote_mode'] = 'suggest';
$got = $fs->invoke( $strip, array( '.com' ) );
check( 'F4 suggestion fallback returns the same-TLD price', $got, array( '.com' => '$7.77' ) );

// F5: static guard. The plugin claims PHP 7.4, so PHP 8-only string
// helpers may not appear in shipped source.
$php8only = 0;
foreach ( glob( __DIR__ . '/../../includes/*.php' ) as $srcf ) {
	$s = file_get_contents( $srcf );
	$php8only += preg_match_all( '/\b(str_ends_with|str_starts_with|str_contains)\s*\(/', $s );
}
check( 'F5 no PHP 8-only string helpers in source', $php8only, 0 );


echo "\n== G. event_count stays dead ==\n";

// The column is legacy and always 1. Every surface counts rows. Any new
// aggregate that trusts the column would silently disagree with the other
// two surfaces the day the column carries anything else, so its return is
// a build failure, not a code-review hope.
$offenders = array();
foreach ( glob( __DIR__ . '/../../includes/*.php' ) as $f ) {
	$src = file_get_contents( $f );
	if ( false !== strpos( $src, 'SUM(event_count)' ) || false !== strpos( $src, 'THEN event_count' ) ) {
		$offenders[] = basename( $f );
	}
}
check( 'G1 no aggregate reads event_count', $offenders, array() );

echo "\n== H. admin bar pulse ==\n";

// FakeWpdbD grows a get_results for the per-type GROUP BY.
class FakeWpdbH extends FakeWpdbD {
	public function get_results( $q, $out = OBJECT ) {
		$rows = array();
		foreach ( ( $GLOBALS['pulse_rows'] ?? array() ) as $type => $n ) {
			$rows[] = (object) array( 'event_type' => $type, 'n' => $n );
		}
		return $rows;
	}
}
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
$GLOBALS['wpdb'] = new FakeWpdbH();
$GLOBALS['transients'] = array();
$GLOBALS['bar_showing'] = true; $GLOBALS['user_can'] = true; $GLOBALS['admin_bar_setting'] = true;
$GLOBALS['pulse_rows'] = array(
	'domain_search' => 14, 'domain_select' => 3, 'domain_transfer' => 2,
	'continue_to_cart' => 4, 'product_add' => 4,
	'cart_view' => 2, 'login_click' => 1, 'phone_click' => 1,
);

$ab  = new Reseller_Intent_Adminbar();
$bar = new Fake_Admin_Bar();
$ab->add_nodes( $bar );

// H1: the chip total is the sum of every tracked type.
$parent = $bar->nodes[0];
check( 'H1 chip counts every tracked event', strpos( $parent['title'], '>31<' ) !== false, true );
check( 'H1 chip uses core ab-icon and ab-label', ( strpos( $parent['title'], 'ab-icon' ) !== false ) && ( strpos( $parent['title'], 'ab-label' ) !== false ), true );

// H2: the menu lines decompose that exact number.
$titles = array_map( function ( $n ) { return wp_strip_all_tags( $n['title'] ); }, $bar->nodes );
$sum = 0;
foreach ( $titles as $t ) {
	if ( preg_match( '/^(\d+) /', $t, $m ) ) { $sum += (int) $m[1]; }
}
check( 'H2 menu lines sum to the chip', $sum, 31 );
check( 'H2 outbound folds three types into one line', in_array( '4 outbound clicks', $titles, true ), true );
check( 'H2 last node is the dashboard link', end( $bar->nodes )['title'], 'Reseller Intent' );
check( 'H2 foot group uses the native secondary class', $bar->groups[0]['meta']['class'], 'ab-sub-secondary' );

// H3: no em-dash anywhere in what the bar renders.
$all = implode( ' ', $titles );
check( 'H3 no em-dash in any bar string', strpos( $all, "\xE2\x80\x94" ), false );

// H4: singular forms.
$GLOBALS['transients'] = array();
$GLOBALS['pulse_rows'] = array( 'domain_search' => 1, 'continue_to_cart' => 1 );
$bar = new Fake_Admin_Bar();
$ab->add_nodes( $bar );
$titles = array_map( function ( $n ) { return wp_strip_all_tags( $n['title'] ); }, $bar->nodes );
check( 'H4 singular lines read singular', in_array( '1 domain search', $titles, true ) && in_array( '1 cart click', $titles, true ), true );
check( 'H4 exact node count: chip, header, 2 lines, link', count( $bar->nodes ), 5 );

// H5: the day key invalidates at midnight.
$GLOBALS['transients'] = array();
$GLOBALS['pulse_rows'] = array( 'domain_search' => 7 );
$bar = new Fake_Admin_Bar(); $ab->add_nodes( $bar );          // builds and caches
$stale = $GLOBALS['transients']['rintent_pulse'];
$stale['day'] = '2001-01-01';                                  // yesterday's cache
$GLOBALS['transients']['rintent_pulse'] = $stale;
$GLOBALS['pulse_rows'] = array( 'domain_search' => 9 );
$bar = new Fake_Admin_Bar(); $ab->add_nodes( $bar );
check( 'H5 a stale day is rebuilt, not served', strpos( $bar->nodes[0]['title'], '>9<' ) !== false, true );

// H6: gates. Setting off, capability off, bar hidden: no nodes at all.
foreach ( array( 'admin_bar_setting', 'user_can', 'bar_showing' ) as $gate ) {
	$GLOBALS[ $gate ] = false;
	$bar = new Fake_Admin_Bar(); $ab->add_nodes( $bar );
	check( "H6 gate {$gate} off means no nodes", count( $bar->nodes ), 0 );
	$GLOBALS[ $gate ] = true;
}

// H7: every data-changing path drops the pulse cache. Static guard, like G1.
$tracker = file_get_contents( __DIR__ . '/../../includes/class-reseller-intent-tracker.php' );
$dbsrc   = file_get_contents( __DIR__ . '/../../includes/class-reseller-intent-db.php' );
check( 'H7 tracker insert invalidates the pulse', substr_count( $tracker, 'Reseller_Intent_Adminbar::TRANSIENT' ) >= 1, true );
check( 'H7 both delete paths invalidate the pulse', substr_count( $dbsrc, 'Reseller_Intent_Adminbar::TRANSIENT' ) >= 3, true );

/* ------------------------------------------------------------------ */
/* N: activation notice for the widget styling default.               */
/* ------------------------------------------------------------------ */

$ri = ( new ReflectionClass( 'Reseller_Intent' ) )->newInstanceWithoutConstructor();

function n_render( $ri ) {
	ob_start();
	$ri->show_style_notice();
	return ob_get_clean();
}

// N1: activation raises the flag (and still creates the table).
$GLOBALS['opts'] = array();
$GLOBALS['db_activated'] = false;
Reseller_Intent::activate();
check( 'N1 activation sets the notice flag', get_option( 'rintent_style_notice' ), '1' );
check( 'N1 activation still creates the table', $GLOBALS['db_activated'], true );

// N2: flag + styling on + capability = the notice renders with both links.
$GLOBALS['user_can'] = true;
$GLOBALS['style_widget_setting'] = true;
unset( $_GET['page'] );
$html = n_render( $ri );
check( 'N2 notice renders when armed', false !== strpos( $html, 'notice-info' ), true );
check( 'N2 notice links to settings', false !== strpos( $html, 'page=reseller-intent-settings' ), true );
check( 'N2 notice carries the dismiss action', false !== strpos( $html, 'rintent_ack_style' ), true );
check( 'N2 no em-dash in the notice', false === strpos( $html, "\u{2014}" ), true );

// N3: gates. No flag, or no capability, means no output.
$GLOBALS['opts'] = array();
check( 'N3 silent without the flag', n_render( $ri ), '' );
update_option( 'rintent_style_notice', '1' );
$GLOBALS['user_can'] = false;
check( 'N3 silent without capability', n_render( $ri ), '' );
$GLOBALS['user_can'] = true;

// N4: styling off = nothing to warn about; the flag cleans itself up.
$GLOBALS['style_widget_setting'] = false;
check( 'N4 silent when styling is off', n_render( $ri ), '' );
check( 'N4 flag removed when styling is off', get_option( 'rintent_style_notice' ), false );
$GLOBALS['style_widget_setting'] = true;

// N5: reaching the settings page retires the notice.
update_option( 'rintent_style_notice', '1' );
$_GET['page'] = 'reseller-intent-settings';
check( 'N5 silent on the settings page', n_render( $ri ), '' );
check( 'N5 flag removed on the settings page', get_option( 'rintent_style_notice' ), false );
unset( $_GET['page'] );

// N6: static guards. The dismiss handler is wired, nonce-checked, and
// actually deletes the flag; uninstall removes the option too.
$main = file_get_contents( __DIR__ . '/../../includes/class-reseller-intent.php' );
$uninst = file_get_contents( __DIR__ . '/../../uninstall.php' );
check( 'N6 dismiss action registered', false !== strpos( $main, "admin_post_rintent_ack_style" ), true );
check( 'N6 dismiss handler checks the nonce', substr_count( $main, "check_admin_referer( 'rintent_ack_style' )" ) >= 1, true );
check( 'N6 dismiss handler deletes the flag', substr_count( $main, "delete_option( 'rintent_style_notice' )" ) >= 3, true );
check( 'N6 uninstall removes the flag', false !== strpos( $uninst, "rintent_style_notice" ), true );

/* ------------------------------------------------------------------ */
/* J: stuck Add to cart button. Behaviour is proven in the browser by */
/* cart-reset-check.js; these guards keep the wiring from vanishing.   */
/* ------------------------------------------------------------------ */

$trk = file_get_contents( __DIR__ . '/../../assets/js/tracker.js' );
check( 'J1 the cart loading reset is bound at init', substr_count( $trk, 'bindCartLoadingReset()' ) >= 2, true );
check( 'J1 the reset clears the Reseller Store loading flag', false !== strpos( $trk, "removeAttr('data-loading')" ), true );
check( 'J1 the reset only acts on new-tab submits', false !== strpos( $trk, "'_blank' !==" ), true );
check( 'J1 a back/forward cache restore is handled', false !== strpos( $trk, 'event.persisted' ), true );

/* ------------------------------------------------------------------ */
/* Q: Abilities API + the single query layer behind every surface.    */
/* ------------------------------------------------------------------ */

$defs = Reseller_Intent_Abilities::definitions();
$expected_slugs = array( 'get-summary', 'get-top-searches', 'get-top-tlds', 'get-countries', 'get-carted-domains', 'get-product-adds', 'get-transfer-searches', 'get-pages', 'get-trend', 'get-recent-searches', 'get-tld-prices', 'get-tracking-status', 'refresh-tld-prices' );
check( 'Q1 thirteen abilities, the agreed list', array_keys( $defs ), $expected_slugs );

$shape_ok = true; $readonly_ok = true; $input_ok = true; $names_ok = true;
foreach ( $defs as $slug => $def ) {
	$shape_ok = $shape_ok && ! empty( $def['label'] ) && ! empty( $def['description'] ) && 'reseller-intent' === $def['category']
		&& is_callable( $def['execute_callback'] ) && is_callable( $def['permission_callback'] ) && is_array( $def['output_schema'] )
		&& 'object' === $def['output_schema']['type'] && ! empty( $def['meta']['public'] ) && ! empty( $def['meta']['show_in_rest'] );
	$is_read     = 0 === strpos( $slug, 'get-' );
	$readonly_ok = $readonly_ok && ( $def['meta']['annotations']['readonly'] === $is_read ) && false === $def['meta']['annotations']['destructive'];
	if ( isset( $def['input_schema'] ) ) {
		$input_ok = $input_ok && false === $def['input_schema']['additionalProperties']
			&& $def['input_schema']['properties']['range']['enum'] === Reseller_Intent_Reports::RANGE_KEYS;
	}
	$names_ok = $names_ok && (bool) preg_match( '/^[a-z0-9-]+$/', $slug );
}
check( 'Q2 every ability has label, description, category, callbacks, object output schema, public + rest', $shape_ok, true );
check( 'A3 get-* are readonly, the refresh is not, nothing is destructive', $readonly_ok, true );
check( 'Q4 inputs reject unknown keys and share the dashboard range enum', $input_ok, true );
check( 'Q4 slugs are lowercase-dash only', $names_ok, true );

$GLOBALS['user_can'] = false;
$denied = Reseller_Intent_Abilities::run( 'get-summary' );
check( 'Q5 run() refuses without the dashboard capability', is_wp_error( $denied ) && 'rintent_forbidden' === $denied->get_error_code(), true );
$GLOBALS['user_can'] = true;
$GLOBALS['user_can'] = false;
$cli_path = Reseller_Intent_Abilities::run( 'get-summary', array( 'range' => 'custom', 'from' => 'bad', 'to' => 'bad' ), false );
check( 'Q5 the CLI path skips the capability gate and reaches the callback', is_wp_error( $cli_path ) && 'rintent_bad_range' === $cli_path->get_error_code(), true );
$GLOBALS['user_can'] = true;
$unknown = Reseller_Intent_Abilities::run( 'nope' );
check( 'Q6 run() rejects an unknown slug', is_wp_error( $unknown ) && 'rintent_unknown_ability' === $unknown->get_error_code(), true );
$bad = Reseller_Intent_Abilities::run( 'get-summary', array( 'range' => 'custom', 'from' => 'yesterday', 'to' => '2026-01-01' ) );
check( 'Q7 a broken custom range is refused before any query', is_wp_error( $bad ) && 'rintent_bad_range' === $bad->get_error_code(), true );
check( 'Q7 the refusal carries HTTP 400 so REST never answers 500', is_wp_error( $bad ) && 400 === ( $bad->get_error_data()['status'] ?? 0 ), true );
check( 'Q6 an unknown slug carries HTTP 404', 404 === ( $unknown->get_error_data()['status'] ?? 0 ), true );
check( 'Q5 a capability refusal carries HTTP 403', 403 === ( $denied->get_error_data()['status'] ?? 0 ), true );

$cli_targets_ok = true;
foreach ( Reseller_Intent_CLI::REPORTS as $name => $slug ) {
	$cli_targets_ok = $cli_targets_ok && isset( $defs[ $slug ] );
}
check( 'Q8 every wp rintent report maps to a registered ability', $cli_targets_ok, true );

// A9: aggregates live in Reports and nowhere else. The dashboard and the
// CLI used to carry their own copies of this SQL.
$adm = file_get_contents( __DIR__ . '/../../includes/class-reseller-intent-admin.php' );
$cli = file_get_contents( __DIR__ . '/../../includes/class-reseller-intent-cli.php' );
$abl = file_get_contents( __DIR__ . '/../../includes/class-reseller-intent-abilities.php' );
$rep = file_get_contents( __DIR__ . '/../../includes/class-reseller-intent-reports.php' );
check( 'Q9 no aggregate SQL left in the dashboard', substr_count( $adm, 'SUM(CASE WHEN' ), 0 );
check( 'Q9 no aggregate SQL left in the CLI', substr_count( $cli, 'SUM(CASE WHEN' ), 0 );
check( 'Q9 no SQL at all in the abilities', substr_count( $abl, '$wpdb' ), 0 );
check( 'Q9 the aggregates live in Reports', substr_count( $rep, 'SUM(CASE WHEN' ) >= 10, true );
check( 'Q9 no MySQL-only SQL functions, SQLite runs the same queries', preg_match( '/SUBSTRING_INDEX|LOCATE\(|REVERSE\(/', $rep . $adm . $cli . $abl ), 0 );
$mainf = file_get_contents( __DIR__ . '/../../reseller-intent.php' );
check( 'Q9 Reports loads before the dashboard', strpos( $mainf, 'class-reseller-intent-reports.php' ) < strpos( $mainf, 'class-reseller-intent-admin.php' ), true );
check( 'Q9 abilities are registered at bootstrap', false !== strpos( $main, '$this->abilities->register()' ), true );
check( 'Q10 no em-dash in the new surfaces', false === strpos( $abl . $rep . $cli, "\u{2014}" ), true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
