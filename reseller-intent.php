<?php
/**
 * Plugin Name: Reseller Intent
 * Plugin URI: https://github.com/ekamran/reseller-intent
 * Description: Domain search analytics and buyer intent for GoDaddy Reseller Store — see what visitors search, select, and carry to cart.
 * Version: 1.0.0
 * Author: Kamran Abdul Aziz
 * Author URI: https://shifteq.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: reseller-store
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Text Domain: reseller-intent
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

require_once RINTENT_PATH . 'includes/class-reseller-intent-db.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-settings.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-assets.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-tracker.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-admin.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent-import.php';
require_once RINTENT_PATH . 'includes/class-reseller-intent.php';

register_activation_hook( __FILE__, array( 'Reseller_Intent', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Reseller_Intent', 'deactivate' ) );

new Reseller_Intent();
