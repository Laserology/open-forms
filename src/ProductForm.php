<?php
/**
 * Frontend. Renders the configured fields above the add to cart button.
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the form fields on the single product page.
 */
class ProductForm {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Render the fields for the current product.
	 */
	public function render() {
		global $product;

		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();
		$has_variations = $product->is_type( 'variable' );

		// Product level fields. These apply to the product itself (simple)
		// or to every variation of a variable product.
		$fields = Fields::for_post( $product_id );

		if ( ! empty( $fields ) ) {
			echo '<div class="openforms-fields">';

			foreach ( $fields as $key => $field ) {
				echo Fields::render( $key, $field ); // phpcs:ignore WordPress.Security.EscapeOutput
			}

			echo '</div>';
		}

		// Variation level fields. These only apply to their specific
		// variation and are toggled with JS when the variation is selected.
		if ( $has_variations ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation_fields = Fields::for_post( $variation_id );

				if ( empty( $variation_fields ) ) {
					continue;
				}

				echo '<div class="openforms-fields openforms-variation-fields" data-variation-id="' . esc_attr( $variation_id ) . '" style="display:none;">';

				foreach ( $variation_fields as $key => $field ) {
					echo Fields::render( $variation_id . '_' . $key, $field ); // phpcs:ignore WordPress.Security.EscapeOutput
				}

				echo '</div>';
			}
		}
	}

	/**
	 * Enqueue the frontend assets on product pages only.
	 */
	public function enqueue() {
		if ( ! is_product() ) {
			return;
		}

		wp_enqueue_script( 'openforms-frontend', OPENFORMS_URL . 'assets/js/frontend.js', array( 'jquery' ), OPENFORMS_VERSION, true );
		wp_enqueue_style( 'openforms-frontend', OPENFORMS_URL . 'assets/css/frontend.css', array(), OPENFORMS_VERSION );
	}
}
