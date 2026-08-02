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
		new ProductForms();
		new ProductForm();
		new CartHandler();
		new OrderFormData();
		new Download();
		new Cleanup();
	}
}
