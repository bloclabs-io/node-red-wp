<?php
/**
 * Plugin Name:       Node-RED Live Data
 * Plugin URI:        https://github.com/bloclabs-io/node-red-wp
 * Description:       Magical things happen when you combine <strong>Node-RED</strong> and <strong>WordPress</strong>. Push live data from Node-RED flows into your site and display it in real time with a block, shortcode or widget.
 * Version:           2.0.0
 * Requires at least: 6.5
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Automattic, BlocLabs
 * Author URI:        https://github.com/bloclabs-io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       node-red-wp
 *
 * @package node-red-wp
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NRWP_VERSION', '2.0.0' );
define( 'NRWP_FILE', __FILE__ );
define( 'NRWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'NRWP_URL', plugin_dir_url( __FILE__ ) );

// Class requirements.
require_once NRWP_DIR . 'includes/class-node-red-wp.php';
require_once NRWP_DIR . 'includes/class-node-red-wp-data.php';
require_once NRWP_DIR . 'includes/class-node-red-wp-rest.php';
require_once NRWP_DIR . 'includes/class-node-red-wp-shortcodes.php';
require_once NRWP_DIR . 'includes/class-node-red-wp-data-widget.php';
require_once NRWP_DIR . 'includes/class-node-red-wp-settings.php';

// Bootstrap the plugin.
add_action( 'plugins_loaded', array( 'Node_Red_WP', 'init' ) );

// Migrate data stored by 0.x versions of the plugin.
register_activation_hook( __FILE__, array( 'Node_Red_WP_Data', 'maybe_migrate' ) );
