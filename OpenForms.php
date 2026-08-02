<?php
/**
 * Plugin Name: OpenForms
 * Description: Add modular forms to WooCommerce products and variations. Form data and file uploads are stored against the order for easy review.
 * Version:     1.0.2
 * Author:      Evan
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: openforms
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPENFORMS_VERSION', '1.0.2' );
define( 'OPENFORMS_FILE', __FILE__ );
define( 'OPENFORMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'OPENFORMS_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4 style autoloader for the OpenForms\ namespace.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'OpenForms\\';

		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$path = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file = OPENFORMS_PATH . 'src/' . $path . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

new OpenForms\Init();
