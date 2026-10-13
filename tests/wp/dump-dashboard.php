<?php
/**
 * Dumps the dashboard payload for a fixed set of ranges and page filters as
 * one JSON document, via `wp eval-file`. Time-relative text is stripped and
 * every ranked list is re-sorted with a full tiebreak, so two dumps compare
 * on content rather than on the order MySQL happened to return equal
 * counts in. Used to prove a refactor of the query layer changed nothing
 * the dashboard shows.
 */

$admin  = new Reseller_Intent_Admin();
$method = new ReflectionMethod( 'Reseller_Intent_Admin', 'get_dashboard_data' );
$method->setAccessible( true );

$today = wp_date( 'Y-m-d' );
$from  = wp_date( 'Y-m-d', strtotime( $today . ' -45 days' ) );
$to    = wp_date( 'Y-m-d', strtotime( $today . ' -10 days' ) );

$cases = array(
	array( '7', '', '', '' ),
	array( '30', '', '', '' ),
	array( '90', '', '', '' ),
	array( 'all', '', '', '' ),
	array( 'custom', $from, $to, '' ),
	array( '30', '', '', '/' ),
	array( '90', '', '', '/domains/' ),
	array( 'all', '', '', '/products/hosting/' ),
);

/**
 * Sort a list of rows by a numeric field descending, then a string field
 * ascending, so ties land in one order no matter who produced the list.
 */
function rintent_canon( array $rows, $num, $str ) {
	usort(
		$rows,
		static function ( $a, $b ) use ( $num, $str ) {
			if ( $a[ $num ] !== $b[ $num ] ) {
				return $b[ $num ] <=> $a[ $num ];
			}
			return strcmp( (string) $a[ $str ], (string) $b[ $str ] );
		}
	);
	return $rows;
}

$out = array();
foreach ( $cases as $case ) {
	$data = $method->invoke( $admin, $case[0], $case[1], $case[2], $case[3] );
	unset( $data['lastEvent']['ago'] );

	$data['tlds']['items']      = rintent_canon( $data['tlds']['items'], 'count', 'label' );
	$data['repeats']            = rintent_canon( $data['repeats'], 'hits', 'domain' );
	$data['carted']['domains']  = rintent_canon( $data['carted']['domains'], 'count', 'domain' );
	$data['countries']['items'] = rintent_canon( $data['countries']['items'], 'hits', 'code' );
	$data['products']['items']  = rintent_canon( $data['products']['items'], 'count', 'label' );
	$data['transfers']['items'] = rintent_canon( $data['transfers']['items'], 'count', 'label' );
	$data['pages']              = rintent_canon( $data['pages'], 'searches', 'path' );
	usort(
		$data['pageOptions'],
		static function ( $a, $b ) {
			return strcmp( $a['path'], $b['path'] );
		}
	);

	$out[ implode( '|', $case ) ] = $data;
}

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
