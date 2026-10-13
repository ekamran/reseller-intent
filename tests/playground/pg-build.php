<?php
/*
 * Build pg-blueprint.json: the wp.org demo seeder from the shipped blueprint,
 * run against the WORKING TREE plugin (mounted by playground-check.sh), then
 * pg-verify.php, then the CLI. Usage: php pg-build.php
 */
$src  = json_decode( file_get_contents( __DIR__ . '/../../blueprints/blueprint.json' ), true );
$seed = '';
foreach ( $src['steps'] as $s ) { if ( 'runPHP' === ( $s['step'] ?? '' ) ) { $seed = $s['code']; break; } }
if ( '' === $seed ) { fwrite( STDERR, "no runPHP seeder in blueprint\n" ); exit( 1 ); }
$bp = array(
	'$schema'           => 'https://playground.wordpress.net/blueprint-schema.json',
	'preferredVersions' => array( 'php' => '8.2', 'wp' => 'latest' ),
	'features'          => array( 'networking' => true ),
	'login'             => true,
	'steps'             => array(
		array( 'step' => 'installPlugin', 'pluginData' => array( 'resource' => 'wordpress.org/plugins', 'slug' => 'reseller-store' ), 'options' => array( 'activate' => true ) ),
		array( 'step' => 'activatePlugin', 'pluginPath' => '/wordpress/wp-content/plugins/reseller-intent' ),
		array( 'step' => 'runPHP', 'code' => $seed ),
		array( 'step' => 'runPHP', 'code' => file_get_contents( __DIR__ . '/pg-verify.php' ) ),
		array( 'step' => 'wp-cli', 'command' => "wp eval \"\$o = array(); foreach ( array( 'rintent report summary --range=30 --format=json', 'rintent report tlds --range=all --limit=5', 'rintent report pages --range=30', 'rintent abilities' ) as \$c ) { \$o[] = '\$ wp ' . \$c; \$o[] = WP_CLI::runcommand( \$c, array( 'return' => true, 'launch' => false, 'exit_error' => false ) ); } file_put_contents( '/wordpress/pg-out/cli.txt', implode( PHP_EOL, \$o ) . PHP_EOL );\"" ),
	),
);
file_put_contents( __DIR__ . '/pg-blueprint.json', json_encode( $bp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
printf( "wrote pg-blueprint.json: %d steps, seeder %d bytes\n", count( $bp['steps'] ), strlen( $seed ) );
