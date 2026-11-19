<?php
/**
 * Plugin Name: OpenForms for WooCommerce
 * Plugin URI: https://github.com/Laserology/open-forms/
 * Description: Product option forms for WooCommerce. Adds configurable fields to products and variations, carries the answers through the cart, and stores them on the order. Requires OpenForms. Not affiliated with or endorsed by WooCommerce or Automattic.
 * License: GPL v2 or later
 * Version: 1.0.0
 * Author: Laserology
 * Author URI: https://laserology.net/
 * Requires Plugins: woocommerce, open-forms
 * Requires PHP: 8.0
 * Text Domain: open-forms-for-wc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'OPENFORMS_WC_VERSION' ) ) {
	define( 'OPENFORMS_WC_VERSION', '1.0.0' );
}
if ( ! defined( 'OPENFORMS_WC_FILE' ) ) {
	define( 'OPENFORMS_WC_FILE', __FILE__ );
}
if ( ! defined( 'OPENFORMS_WC_PATH' ) ) {
	define( 'OPENFORMS_WC_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'OPENFORMS_WC_URL' ) ) {
	define( 'OPENFORMS_WC_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Boot the WooCommerce integration once OpenForms and WooCommerce are loaded.
 *
 * OpenForms boots on plugins_loaded priority 10, so this runs later and can
 * rely on its classes and constants being available.
 */
add_action( 'plugins_loaded', 'openforms_woocommerce_boot', 20 );

/**
 * Start the integration, or explain what is missing.
 */
function openforms_woocommerce_boot(): void {
	if ( ! defined( 'OPENFORMS_VERSION' ) || ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'openforms_woocommerce_dependency_notice' );

		return;
	}

	require_once OPENFORMS_WC_PATH . 'src/Init.php';
	\OpenFormsWooCommerce\Init::boot();
}

/**
 * Admin notice shown when a required plugin is not active.
 */
function openforms_woocommerce_dependency_notice(): void {
	$missing = array();

	if ( ! defined( 'OPENFORMS_VERSION' ) ) {
		$missing[] = 'OpenForms';
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		$missing[] = 'WooCommerce';
	}

	if ( empty( $missing ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: comma separated list of plugin names. */
				__( 'OpenForms for WooCommerce requires %s to be installed and active.', 'open-forms-for-wc' ),
				implode( ', ', $missing )
			)
		)
	);
}
