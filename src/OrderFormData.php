<?php
/**
 * Order admin. Adds a meta box with the attached form content and a summary
 * order note for every line item that carries form data.
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

/**
 * Renders form attachments on the order screen.
 */
class OrderFormData {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'add_order_notes' ), 10, 1 );
	}

	/**
	 * Register the meta box on the order screen. Works with both legacy order
	 * storage and High Performance Order Storage (HPOS).
	 */
	public function add_meta_box() {
		$screen = 'shop_order';

		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$controller = wc_get_container()->get( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' );

			if ( $controller && $controller->custom_orders_table_usage_is_enabled() ) {
				$screen = wc_get_page_screen_id( 'shop-order' );
			}
		}

		add_meta_box(
			'openforms-order-attachments',
			__( 'Form Attachments', 'openforms' ),
			array( $this, 'render_meta_box' ),
			$screen,
			'normal',
			'high'
		);
	}

	/**
	 * Render the attached form content for each line item.
	 *
	 * @param \WP_Post|\WC_Order $post Order post or order object.
	 */
	public function render_meta_box( $post ) {
		$order    = is_a( $post, 'WP_Post' ) ? wc_get_order( $post->ID ) : $post;
		$rendered = false;

		if ( $order ) {
			foreach ( $order->get_items() as $item ) {
				$data = json_decode( $item->get_meta( 'openforms_data' ), true );

				if ( ! is_array( $data ) || empty( $data ) ) {
					continue;
				}

				$rendered = true;

				echo '<h4>' . esc_html( $item->get_name() ) . '</h4>';
				echo '<table class="widefat striped openforms-order-table">';
				echo '<tbody>';

				foreach ( $data as $entry ) {
					echo '<tr>';
					echo '<th>' . esc_html( $entry['field_name'] ) . '</th>';

					if ( 'file' === $entry['type'] ) {
						echo '<td><a href="' . esc_url( $entry['file_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $entry['file_name'] ) . '</a>';
						echo '<span class="openforms-file-meta"> (' . esc_html( size_format( $entry['file_size'] ) ) . ')</span></td>';
					} else {
						echo '<td>' . esc_html( $entry['value'] ) . '</td>';
					}

					echo '</tr>';
				}

				echo '</tbody>';
				echo '</table>';
			}
		}

		if ( ! $rendered ) {
			echo '<p>' . esc_html__( 'No form attachments for this order.', 'openforms' ) . '</p>';
		}
	}

	/**
	 * Add a concise summary note for each item with form data.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function add_order_notes( $order ) {
		foreach ( $order->get_items() as $item ) {
			$data = json_decode( $item->get_meta( 'openforms_data' ), true );

			if ( ! is_array( $data ) || empty( $data ) ) {
				continue;
			}

			$lines = array();

			foreach ( $data as $entry ) {
				if ( 'file' === $entry['type'] ) {
					$lines[] = $entry['field_name'] . ': ' . $entry['file_name'] . ' (' . $entry['file_url'] . ')';
				} else {
					$lines[] = $entry['field_name'] . ': ' . $entry['value'];
				}
			}

			$order->add_order_note(
				sprintf(
					/* translators: 1: item name, 2: submitted values */
					__( 'OpenForms: %1$s - %2$s', 'openforms' ),
					$item->get_name(),
					implode( '; ', $lines )
				)
			);
		}
	}
}
