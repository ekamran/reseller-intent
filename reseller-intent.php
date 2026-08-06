<?php
/**
 * Plugin Name: Reseller Intent for GoDaddy Reseller Store
 * Description: See which domains visitors search on your reseller storefront, which ones they pick, and which ones they take to cart.
 * Version: 2.2.0
 * Author: Kamran Abdul Aziz
 * Author URI: https://shifteq.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: reseller-store
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: reseller-intent
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'RINTENT_FILE' ) ) {
	define( 'RINTENT_FILE', __FILE__ );
}

if ( ! defined( 'RINTENT_PATH' ) ) {
	define( 'RINTENT_PATH', plugin_dir_path( __FILE__ ) );
}

require_once RINTENT_PATH . 'includes/class-reseller-intent-tz.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-db.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-settings.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-assets.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-tracker.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-admin.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-tld-strip.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-price.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-phone.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-health.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-cli.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent.php';

register_activation_hook( __FILE__, array( 'Reseller_Intent', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Reseller_Intent', 'deactivate' ) );

new Reseller_Intent();
