<?php
/**
 * Deterministic event seed for regression tests. Run via `wp eval-file`.
 * Wipes the events table and writes ~360 events over the last 120 site-local
 * days from a fixed PRNG seed, so two runs produce identical rows.
 */

global $wpdb;
$table = Reseller_Intent_DB::table_name();
$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore

mt_srand( 20261006 );

$domains   = array( 'codetrappers.com', 'bigger.com', 'buger.to', 'myfavhost.in', 'geoitty.com', 'colliestuff.org', 'ocandf.com', 'naija.com', 'wogo.com', 'hello-co.com', 'deeplir.co', 'xn--mnchen-hotels-wob.de', 'aveoqua.com', 'thetewew.com', 'bighuger.net', 'sdsad.ai', 'rapiteq.com', 'godaddy.in' );
$products  = array( 'cpanel-starter', 'wordpress-basic', 'website-builder-business', 'microsoft-365-email-essentials', 'ssl-standard-ucc', 'website-backup-5gb', 'titan-pro', 'vps4-linux-self-managed-4vcpu' );
$countries = array( 'US', 'IN', 'BR', 'CA', 'NG', 'GB', 'FR', '' );
$devices   = array( 'desktop', 'desktop', 'mobile', 'tablet', '' );
$pages     = array( 'https://example.test/', 'https://example.test/domains/?utm_source=x', 'https://example.test/domains/', 'https://example.test/products/hosting/', 'https://example.test/transfer/#top', '' );
$types     = array( 'domain_search', 'domain_search', 'domain_search', 'domain_search', 'domain_select', 'continue_to_cart', 'domain_transfer', 'product_add', 'cart_view', 'login_click', 'phone_click' );

$today_noon = ( new DateTimeImmutable( wp_date( 'Y-m-d' ) . ' 12:00:00', wp_timezone() ) )->getTimestamp();
$count      = 0;

for ( $i = 0; $i < 360; $i++ ) {
	$type     = $types[ mt_rand( 0, count( $types ) - 1 ) ];
	$days_ago = mt_rand( 0, 119 );
	$secs     = mt_rand( 0, 86399 );
	$ts       = $today_noon - ( $days_ago * DAY_IN_SECONDS ) - 43200 + $secs;
	$created  = wp_date( 'Y-m-d H:i:s', $ts );

	$domain  = '';
	$related = '';
	$items   = 0;
	$json    = null;
	$avail   = null;

	switch ( $type ) {
		case 'domain_search':
			$domain = $domains[ mt_rand( 0, count( $domains ) - 1 ) ];
			$avail  = mt_rand( 0, 3 ) ? mt_rand( 0, 1 ) : null;
			break;
		case 'domain_select':
			$domain  = $domains[ mt_rand( 0, count( $domains ) - 1 ) ];
			$related = substr( $domain, 0, strpos( $domain, '.' ) );
			break;
		case 'continue_to_cart':
			$items = mt_rand( 1, 5 );
			$list  = array();
			for ( $k = 0; $k < $items; $k++ ) {
				$list[] = array( 'domain' => $domains[ mt_rand( 0, count( $domains ) - 1 ) ] );
			}
			$json = wp_json_encode( $list );
			break;
		case 'domain_transfer':
			$domain = $domains[ mt_rand( 0, count( $domains ) - 1 ) ];
			break;
		case 'product_add':
			$domain = $products[ mt_rand( 0, count( $products ) - 1 ) ];
			$items  = 1;
			break;
		case 'phone_click':
			$domain = '18669381119';
			break;
	}

	$wpdb->insert(
		$table,
		array(
			'event_type'    => $type,
			'domain_query'  => $domain,
			'related_query' => $related,
			'event_count'   => 1,
			'items_count'   => $items,
			'items_json'    => $json,
			'is_available'  => $avail,
			'device'        => $devices[ mt_rand( 0, count( $devices ) - 1 ) ],
			'country'       => $countries[ mt_rand( 0, count( $countries ) - 1 ) ],
			'page_url'      => $pages[ mt_rand( 0, count( $pages ) - 1 ) ],
			'created_at'    => $created,
		)
	);
	++$count;
}

echo "SEEDED $count rows into $table\n";
