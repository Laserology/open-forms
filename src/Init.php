<?php
/**
 * Plugin bootstrap. Loads each modular component.
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

/**
 * Kicks off the plugin by registering every module.
 */
final class Init {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_textdomain();
		$this->load_modules();
	}

	/**
	 * Load the plugin translations from the languages directory.
	 */
	private function load_textdomain() {
		load_plugin_textdomain( 'openforms', false, dirname( plugin_basename( OPENFORMS_FILE ) ) . '/languages' );
	}

	/**
	 * Instantiate each modular component.
	 */
	private function load_modules() {
		foreach ( array( Admin\ProductSettings::class, Frontend\ProductForm::class, Services\CartHandler::class, DTOs\OrderFormData::class, Services\Download::class, Services\Cleanup::class ) as $module ) {
			new $module();
		}
	}
}
