<?php
/**
 * Plugin Name: DPS DNS Lookup Widget
 * Plugin URI: https://dps.media/
 * Description: Adds a fast, cached bulk DNS and server health lookup widget with a shortcode.
 * Version: 1.1.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: DPS.MEDIA
 * Author URI: https://dps.media/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dps-dns-lookup-widget
 *
 * @package DPS_DNS_Lookup_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DPS_DNS_LOOKUP_WIDGET_VERSION', '1.1.3' );
define( 'DPS_DNS_LOOKUP_WIDGET_FILE', __FILE__ );
define( 'DPS_DNS_LOOKUP_WIDGET_DIR', plugin_dir_path( __FILE__ ) );
define( 'DPS_DNS_LOOKUP_WIDGET_URL', plugin_dir_url( __FILE__ ) );

require_once DPS_DNS_LOOKUP_WIDGET_DIR . 'includes/class-dps-dns-lookup-settings.php';
require_once DPS_DNS_LOOKUP_WIDGET_DIR . 'includes/class-dps-dns-lookup-rest.php';
require_once DPS_DNS_LOOKUP_WIDGET_DIR . 'includes/class-dps-dns-lookup-admin.php';
require_once DPS_DNS_LOOKUP_WIDGET_DIR . 'includes/class-dps-dns-lookup-plugin.php';

register_activation_hook( __FILE__, array( 'DPS_DNS_Lookup_Admin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		DPS_DNS_Lookup_Plugin::instance();
	}
);
