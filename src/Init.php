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
		$this->load_modules();
	}

	/**
	 * Instantiate each modular component.
	 */
	private function load_modules() {
		new ProductForms();
		new ProductForm();
		new CartHandler();
		new OrderFormData();
	}
}
