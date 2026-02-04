<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.snailtheme.com/
 * @since             1.0.0
 * @package           St_Wp_Importer
 *
 * @wordpress-plugin
 * Plugin Name:       ST WordPress Importer
 * Plugin URI:        https://xmarks/st-wp-importer
 * Description:       plugin connects to "source-site" database, and imports Posts / CPT alongside media and metadata (for plugins as well) into "currently-installed site"
 * Version:           1.0.5
 * Author:            SnailTheme
 * Author URI:        https://www.snailtheme.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       st-wp-importer
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'ST_WP_IMPORTER_VERSION', '1.0.5' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-st-wp-importer-activator.php
 */
function activate_st_wp_importer() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-st-wp-importer-activator.php';
	St_Wp_Importer_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-st-wp-importer-deactivator.php
 */
function deactivate_st_wp_importer() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-st-wp-importer-deactivator.php';
	St_Wp_Importer_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_st_wp_importer' );
register_deactivation_hook( __FILE__, 'deactivate_st_wp_importer' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-st-wp-importer.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_st_wp_importer() {

	$plugin = new St_Wp_Importer();
	$plugin->run();

}
run_st_wp_importer();
