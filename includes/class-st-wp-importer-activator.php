<?php

/**
 * Fired during plugin activation
 *
 * @link       https://www.snailtheme.com/
 * @since      1.0.0
 *
 * @package    St_Wp_Importer
 * @subpackage St_Wp_Importer/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    St_Wp_Importer
 * @subpackage St_Wp_Importer/includes
 * @author     SnailTheme <webmaster@snailtheme.com>
 */
class St_Wp_Importer_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {

		// Ensure mapping table exists.
		require_once plugin_dir_path( __FILE__ ) . 'class-st-wp-importer-settings.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-st-wp-importer-logger.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-st-wp-importer-map.php';

		$settings = new St_Wp_Importer_Settings();
		$logger   = new St_Wp_Importer_Logger( $settings );
		$map      = new St_Wp_Importer_Map( $logger );
		$map->maybe_create_table();

		// Seed defaults if not present.
		if ( ! get_option( St_Wp_Importer_Settings::OPTION_KEY ) ) {
			$settings->save( $settings->defaults() );
		}
	}

}
