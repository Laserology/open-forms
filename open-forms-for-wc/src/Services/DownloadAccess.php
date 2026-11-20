<?php
/**
 * DownloadAccess: lets shop staff and shoppers with an active cart download
 * the uploaded files that belong to their order or cart items.
 */

namespace OpenFormsWooCommerce\Services;

use OpenForms\DataHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grants order and cart based access to the shared download endpoint.
 */
class DownloadAccess {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'openforms_can_download', array( $this, 'allow' ), 10, 2 );
	}

	/**
	 * Allow shop staff, or a shopper whose cart still holds the token.
	 *
	 * @param bool   $allowed Whether access is already granted.
	 * @param string $token   File token.
	 * @return bool
	 */
	public function allow( bool $allowed, string $token ): bool {
		if ( $allowed || current_user_can( 'edit_shop_orders' ) ) {
			return true;
		}

		return $this->token_in_cart( $token );
	}

	/**
	 * Whether the current shopper's cart still references the file token.
	 *
	 * @param string $token File token.
	 * @return bool
	 */
	private function token_in_cart( string $token ): bool {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) || ! is_object( WC()->cart ) ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['openforms_data'] ) ) {
				continue;
			}

			foreach ( DataHelpers::file_tokens( DataHelpers::decode( $cart_item['openforms_data'] ) ) as $file_key ) {
				if ( hash_equals( $token, $file_key ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
