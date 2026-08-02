<?php
/**
 * Checkout / cart. Validates submissions, stores them on the cart item and
 * displays them in the cart and checkout summaries.
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the lifecycle of submitted form data between add to cart and the order.
 */
class CartHandler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_in_cart' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_order_itemmeta' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );
	}

	/**
	 * Merge product and variation level fields, keyed by the input name used
	 * on the frontend. Variation fields are prefixed with the variation ID.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @return array
	 */
	private function fields_for( $product_id, $variation_id = 0 ) {
		$fields = array();

		foreach ( Fields::for_post( $product_id ) as $key => $field ) {
			$fields[ $key ] = $field;
		}

		if ( $variation_id ) {
			foreach ( Fields::for_post( $variation_id ) as $key => $field ) {
				$fields[ $variation_id . '_' . $key ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Validate the submitted fields before an item is added to the cart.
	 *
	 * @param bool $passed       Current validation state.
	 * @param int  $product_id   Product ID.
	 * @param int  $quantity     Quantity.
	 * @param int  $variation_id Variation ID.
	 * @return bool
	 */
	public function validate( $passed, $product_id, $quantity, $variation_id = 0 ) {
		$fields = $this->fields_for( $product_id, $variation_id );

		if ( empty( $fields ) ) {
			return $passed;
		}

		$errors = array();

		foreach ( $fields as $input_key => $field ) {
			$errors = array_merge( $errors, Fields::validate( $input_key, $field ) );
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $error ) {
				wc_add_notice( $error, 'error' );
			}

			return false;
		}

		return $passed;
	}

	/**
	 * Attach the sanitized form data (and moved uploads) to the cart item.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id = 0 ) {
		$fields = $this->fields_for( $product_id, $variation_id );

		if ( empty( $fields ) ) {
			return $cart_item_data;
		}

		$data = array();

		foreach ( $fields as $input_key => $field ) {
			if ( 'file' === $field['type'] ) {
				$file = Fields::save_file( $input_key );

				if ( $file ) {
					$file['type']       = 'file';
					$file['field_name'] = $field['name'];
					$data[]             = $file;
				}

				continue;
			}

			$value = isset( $_POST['openforms'][ $input_key ] ) ? wp_unslash( $_POST['openforms'][ $input_key ] ) : '';
			$value = is_string( $value ) ? $value : '';

			$data[] = array(
				'field_name' => $field['name'],
				'type'       => $field['type'],
				'value'      => Fields::sanitize( $field['type'], $value ),
			);
		}

		if ( ! empty( $data ) ) {
			// Stored as a JSON string. It is carried over to the order line
			// item in save_order_item_meta().
			$cart_item_data['openforms_data'] = wp_json_encode( $data );
		}

		return $cart_item_data;
	}

	/**
	 * Show the submitted values in the cart and checkout item summaries.
	 *
	 * @param array $item_data Existing item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_in_cart( $item_data, $cart_item ) {
		if ( empty( $cart_item['openforms_data'] ) ) {
			return $item_data;
		}

		$data = json_decode( $cart_item['openforms_data'], true );

		if ( ! is_array( $data ) ) {
			return $item_data;
		}

		foreach ( $data as $entry ) {
			if ( 'file' === $entry['type'] ) {
				$value = '<a href="' . esc_url( $entry['file_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $entry['file_name'] ) . '</a>';
			} else {
				$value = esc_html( $entry['value'] );
			}

			$item_data[] = array(
				'key'   => $entry['field_name'],
				'value' => $value,
			);
		}

		return $item_data;
	}

	/**
	 * Keep the raw JSON out of the standard order item meta list. It is
	 * rendered in its own meta box on the order screen instead.
	 *
	 * @param array $meta Keys to hide.
	 * @return array
	 */
	public function hide_order_itemmeta( $meta ) {
		$meta[] = 'openforms_data';

		return $meta;
	}

	/**
	 * Persist the form data onto the order line item during checkout. Cart
	 * item data is not carried over to the order automatically.
	 *
	 * @param \WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item data.
	 * @param \WC_Order              $order         Order object.
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['openforms_data'] ) ) {
			$item->add_meta_data( 'openforms_data', $values['openforms_data'] );
		}
	}
}
