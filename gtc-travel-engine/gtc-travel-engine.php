<?php
/**
 * Plugin Name:       GTC Travel Engine
 * Plugin URI:        https://github.com/anirudhatalmale6-alt/gtc-travel-engine
 * Description:       Supplier-agnostic travel comparison and booking engine for WordPress. Implements the Search / Look / Book model across multiple distribution APIs, with offer normalisation, duplicate matching, pre-payment revalidation and on-site booking.
 * Version:           0.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Anirudha Talmale
 * License:           GPL-2.0-or-later
 * Text Domain:       gtc
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

define( 'GTC_VERSION', '0.4.0' );
define( 'GTC_FILE', __FILE__ );
define( 'GTC_PATH', plugin_dir_path( __FILE__ ) );
define( 'GTC_URL', plugin_dir_url( __FILE__ ) );

require_once GTC_PATH . 'includes/class-gtc-autoloader.php';
GTC_Autoloader::register();

register_activation_hook( __FILE__, array( 'GTC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GTC_Install', 'deactivate' ) );

/**
 * Main plugin accessor.
 *
 * @return GTC_Plugin
 */
function gtc() {
	return GTC_Plugin::instance();
}

gtc();
