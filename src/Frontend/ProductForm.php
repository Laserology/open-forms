<?php
/**
 * ProductForm: frontend. Renders the configured fields above the add to cart button.
 */

namespace OpenForms\Frontend;

use OpenForms\FieldHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 *
	 * @return void
	 */
	public function render(): void {
		global $product;

		if ( ! $product ) {
			return;
		}

		// Product level fields. These apply to the product itself (simple)
		// or to every variation of a variable product.
		$this->render_field_list( FieldHelpers::for_post( $product->get_id() ) );

		// Variation level fields. These only apply to their specific
		// variation and are toggled with JS when the variation is selected.
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$this->render_field_list( FieldHelpers::for_post( $variation_id ), $variation_id );
			}
		}
	}

	/**
	 * Render a set of fields inside a wrapper div.
	 *
	 * @param array $fields       Fields keyed by field key.
	 * @param int   $variation_id Variation ID, or 0 for product level fields.
	 */
	private function render_field_list( array $fields, int $variation_id = 0 ): void {
		if ( empty( $fields ) ) {
			return;
		}

		$attrs = '';

		if ( $variation_id ) {
			$attrs = ' openforms-variation-fields" data-variation-id="' . esc_attr( $variation_id ) . '" style="display:none;"';
		}

		echo '<div class="openforms-fields' . $attrs . '>';

		foreach ( $fields as $key => $field ) {
			// Variation fields are prefixed with the variation ID so the
			// submitted values stay unique on the checkout side.
			echo FieldHelpers::render( $variation_id ? $variation_id . '_' . $key : $key, $field ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '</div>';
	}

	/**
	 * Enqueue the frontend assets on product pages only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! is_product() ) {
			return;
		}

		wp_enqueue_script( 'openforms-frontend', OPENFORMS_URL . 'assets/js/frontend.js', array( 'jquery' ), OPENFORMS_VERSION, true );
		wp_enqueue_style( 'openforms-frontend', OPENFORMS_URL . 'assets/css/frontend.css', array(), OPENFORMS_VERSION );
	}
}
