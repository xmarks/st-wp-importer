<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://www.snailtheme.com/
 * @since      1.0.0
 *
 * @package    St_Wp_Importer
 * @subpackage St_Wp_Importer/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    St_Wp_Importer
 * @subpackage St_Wp_Importer/includes
 * @author     SnailTheme <webmaster@snailtheme.com>
 */
class St_Wp_Importer_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'st-wp-importer',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
