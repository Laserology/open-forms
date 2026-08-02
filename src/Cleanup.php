<?php
/**
 * Cleanup. Deletes uploaded files that are no longer referenced anywhere once
 * their cart item is removed or their order is deleted, so orphaned uploads
 * do not pile up in the private storage folder.
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

/**
 * Removes orphaned uploads when their only reference goes away.
 */
class Cleanup {

	/**
	 * Session key under which file tokens pending deletion are stored.
	 *
	 * @var string
	 */
	private $session_key = 'openforms_pending_cleanup';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_remove_cart_item', array( $this, 'cleanup_cart_item' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'restore_cart_item' ), 10, 2 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'cleanup_emptied_cart' ) );

		// Both hooks cover order deletion: HPOS and modern legacy stores fire
		// woocommerce_before_delete_order (WooCommerce 8.0+), older stores only
		// fire before_delete_post. Priority 1 runs before WooCommerce's own
		// handler removes the order line items we need to read.
		add_action( 'woocommerce_before_delete_order', array( $this, 'cleanup_order' ), 1, 2 );
		add_action( 'before_delete_post', array( $this, 'cleanup_order_post' ), 1 );
	}

	/**
	 * Queue the files of a removed cart item for deletion. The files are not
	 * deleted right away because the shopper can still restore the item via
	 * WooCommerce's "Undo" notice; they are deleted once the cart is emptied.
	 *
	 * @param string   $cart_item_key Cart item key.
	 * @param \WC_Cart $cart          Cart object.
	 */
	public function cleanup_cart_item( $cart_item_key, $cart ) {
		if ( ! is_object( $cart ) ) {
			return;
		}

		$cart_contents = $cart->get_cart();

		if ( empty( $cart_contents[ $cart_item_key ]['openforms_data'] ) ) {
			return;
		}

		$data = json_decode( $cart_contents[ $cart_item_key ]['openforms_data'], true );

		$this->queue( self::file_tokens( $data ) );
	}

	/**
	 * Cancel the pending deletion when the shopper restores a removed item.
	 *
	 * @param string   $cart_item_key Cart item key.
	 * @param \WC_Cart $cart          Cart object.
	 */
	public function restore_cart_item( $cart_item_key, $cart ) {
		if ( ! is_object( $cart ) ) {
			return;
		}

		$cart_contents = $cart->get_cart();

		if ( empty( $cart_contents[ $cart_item_key ]['openforms_data'] ) ) {
			return;
		}

		$data = json_decode( $cart_contents[ $cart_item_key ]['openforms_data'], true );

		$this->unqueue( self::file_tokens( $data ) );
	}

	/**
	 * The cart was emptied (checkout completed or emptied manually). Delete
	 * every queued file that is not referenced by an order.
	 */
	public function cleanup_emptied_cart() {
		foreach ( $this->queued() as $token ) {
			if ( ! $this->token_in_orders( $token ) ) {
				$this->delete_file( $token );
			}
		}

		$this->set_queued( array() );
	}

	/**
	 * Delete the files referenced by an order that is about to be deleted,
	 * unless another order still references the same file.
	 *
	 * @param int             $order_id Order ID.
	 * @param \WC_Order|false $order    Order object.
	 */
	public function cleanup_order( $order_id, $order = false ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$exclude = array();
		$tokens  = array();

		foreach ( $order->get_items() as $item ) {
			$data = json_decode( $item->get_meta( 'openforms_data' ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$exclude[] = (int) $item->get_id();
			$tokens    = array_merge( $tokens, self::file_tokens( $data ) );
		}

		$exclude = array_unique( array_filter( $exclude ) );

		foreach ( array_unique( $tokens ) as $token ) {
			if ( ! $this->token_in_orders( $token, $exclude ) ) {
				$this->delete_file( $token );
			}
		}
	}

	/**
	 * Legacy (non-HPOS) order deletion through the posts table.
	 *
	 * @param int $post_id Post ID.
	 */
	public function cleanup_order_post( $post_id ) {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			$this->cleanup_order( $post_id );
		}
	}

	/**
	 * Extract the file tokens referenced by a decoded openforms_data array.
	 *
	 * @param array $data Decoded openforms_data.
	 * @return array
	 */
	private static function file_tokens( $data ) {
		$tokens = array();

		if ( ! is_array( $data ) ) {
			return $tokens;
		}

		foreach ( $data as $entry ) {
			if ( isset( $entry['type'] ) && 'file' === $entry['type'] && ! empty( $entry['file_key'] ) ) {
				$tokens[] = $entry['file_key'];
			}
		}

		return $tokens;
	}

	/**
	 * Tokens currently queued for deletion.
	 *
	 * @return array
	 */
	private function queued() {
		if ( ! $this->session() ) {
			return array();
		}

		$tokens = $this->session()->get( $this->session_key );

		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Queue tokens for deletion.
	 *
	 * @param array $tokens Tokens to queue.
	 */
	private function queue( $tokens ) {
		$this->set_queued( array_unique( array_merge( $this->queued(), $tokens ) ) );
	}

	/**
	 * Remove tokens that no longer need deletion.
	 *
	 * @param array $tokens Tokens to unqueue.
	 */
	private function unqueue( $tokens ) {
		$this->set_queued( array_diff( $this->queued(), $tokens ) );
	}

	/**
	 * Persist the queued token list.
	 *
	 * @param array $tokens Tokens.
	 */
	private function set_queued( $tokens ) {
		if ( $this->session() ) {
			$this->session()->set( $this->session_key, array_values( $tokens ) );
		}
	}

	/**
	 * The current WooCommerce session, or null when not available.
	 *
	 * @return \WC_Session|null
	 */
	private function session() {
		if ( function_exists( 'WC' ) && isset( WC()->session ) && WC()->session ) {
			return WC()->session;
		}

		return null;
	}

	/**
	 * Whether an order line item still references the file token.
	 *
	 * @param string $token            File token.
	 * @param array  $exclude_item_ids Order item IDs to ignore (the order being deleted).
	 * @return bool
	 */
	private function token_in_orders( $token, $exclude_item_ids = array() ) {
		global $wpdb;

		$exclude = array_unique( array_map( 'absint', (array) $exclude_item_ids ) );
		$table   = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$needle  = '%' . $wpdb->esc_like( '"file_key":"' . $token . '"' ) . '%';

		if ( empty( $exclude ) ) {
			$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT order_item_id FROM {$table} WHERE meta_key = 'openforms_data' AND meta_value LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$needle
				)
			);
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $exclude ), '%d' ) );

			$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT order_item_id FROM {$table} WHERE meta_key = 'openforms_data' AND meta_value LIKE %s AND order_item_id NOT IN ( {$placeholders} ) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					array_merge( array( $needle ), $exclude )
				)
			);
		}

		return (bool) $found;
	}

	/**
	 * Delete the stored file(s) for a token. The token must look like the
	 * random hex keys generated on upload.
	 *
	 * @param string $token File token.
	 */
	private function delete_file( $token ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return;
		}

		$match = glob( Fields::upload_dir() . '/' . $token . '-*' );

		if ( empty( $match ) ) {
			return;
		}

		foreach ( $match as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}
}
