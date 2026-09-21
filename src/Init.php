<?php
/**
 * Bootstrap: wires the plugin submodules into WooCommerce's admin.
 */

namespace OpenForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Init {

	const MODULES = array(
		Admin\ProductSettings::class,
		Frontend\ProductForm::class,
		Services\CartHandler::class,
		DTOs\OrderFormData::class,
		Services\Download::class,
		Services\Cleanup::class,
		Blocks\GetQuote::class,
		Services\QuoteHandler::class,
	);

	/**
	 * Register autoloader for src/*.php classes and boot each module.
	 */
	public static function boot(): void {
		// Register the autoloader for all files.
		self::register_autoloader();

		// Load the plugin translations from the languages directory.
		self::load_textdomain();

		// Instantiate each modular component.
		foreach ( self::MODULES as $module ) {
			new $module();
		}
	}

	/**
	 * Load the plugin translations from the languages directory.
	 *
	 * @return void
	 */
	private static function load_textdomain(): void {
		load_plugin_textdomain( 'openforms', false, dirname( plugin_basename( OPENFORMS_FILE ) ) . '/languages' );
	}

	/**
	 * Simple class autoloader for src/ classes.
	 */
	private static function register_autoloader(): void {
		spl_autoload_register( function ( string $class ): void {
			$prefix = 'OpenForms\\';

			if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
				return;
			}

			$relative_class = substr( $class, strlen( $prefix ) );

			// Map namespace to file path: Admin\ProductSettings -> src/Admin/ProductSettings.php
			$file = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		} );
	}
}