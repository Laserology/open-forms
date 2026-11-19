<?php
/**
 * Plugin Name: OpenForms
 * Plugin URI: https://github.com/Laserology/open-forms/
 * Description: Add modular quote forms to any WordPress site. Product option forms for WooCommerce are available through the OpenForms for WooCommerce add-on.
 * License: GPL v2 or later
 * Version: 1.2.0
 * Author: Laserology
 * Author URI: https://laserology.net/
 * Requires PHP: 8.0
 * Text Domain: open-forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action( 'admin_notices', function() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			sprintf(
				/* translators: %s: The current PHP version running on the server. */
				esc_html__( 'OpenForms requires PHP 8.0 or later. Your server is running PHP %s', 'open-forms' ),
				esc_html( PHP_VERSION )
			)
		);
	} );
	return;
}

class OpenForms {

	/**
	 * Init and hook in the integration.
	 * This boots the plugin submodules.
	 */
	public function __construct() {
		require_once OPENFORMS_PATH . 'src/Init.php';
		\OpenForms\Init::boot();
	}
}

// Initialize the plugin once all plugins have loaded.
add_action( 'plugins_loaded', function() {
	if ( ! defined( 'OPENFORMS_VERSION' ) ) {
		define( 'OPENFORMS_VERSION', '1.2.0' );
	}
	if ( ! defined( 'OPENFORMS_FILE' ) ) {
		define( 'OPENFORMS_FILE', __FILE__ );
	}
	if ( ! defined( 'OPENFORMS_PATH' ) ) {
		define( 'OPENFORMS_PATH', plugin_dir_path( __FILE__ ) );
	}
	if ( ! defined( 'OPENFORMS_URL' ) ) {
		define( 'OPENFORMS_URL', plugin_dir_url( __FILE__ ) );
	}
	new OpenForms();
} );
