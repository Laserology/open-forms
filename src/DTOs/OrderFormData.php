<?php
/**
 * OrderFormData: order admin. Adds a meta box with the attached form content
 * and a summary order note for every line item that carries form data.
 */

namespace OpenForms\DTOs;

use OpenForms\DataHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		add_filter( 'woocommerce_display_item_meta', array( $this, 'display_item_meta' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the meta box on the order screen. Works with both legacy order
	 * storage and High Performance Order Storage (HPOS).
	 */
	public function add_meta_box(): void {
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
	public function render_meta_box( mixed $post ): void {
		$order    = is_a( $post, 'WP_Post' ) ? wc_get_order( $post->ID ) : $post;
		$rendered = false;

		if ( $order ) {
			foreach ( $order->get_items() as $item ) {
				$data = DataHelpers::decode( $item->get_meta( 'openforms_data' ) );

				if ( empty( $data ) ) {
					continue;
				}

				$rendered = true;

				echo '<h4>' . esc_html( $item->get_name() ) . '</h4>';
				echo '<table class="widefat striped openforms-order-table">';
				echo '<tbody>';

				foreach ( $data as $entry ) {
					echo '<tr>';
					echo '<th>' . esc_html( $entry['field_name'] ) . '</th>';
					echo '<td>' . ( 'file' === $entry['type'] ? DataHelpers::render_file( $entry, true, true ) : esc_html( $entry['value'] ) ) . '</td>';
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
	public function add_order_notes( \WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			$data = DataHelpers::decode( $item->get_meta( 'openforms_data' ) );

			if ( empty( $data ) ) {
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
					/* translators: 1: item name, 2: submitted field values separated by "; ". Order note added at checkout to summarize the form content of a line item. */
					__( 'OpenForms: %1$s - %2$s', 'openforms' ),
					$item->get_name(),
					implode( '; ', $lines )
				)
			);
		}
	}

	/**
	 * Replace the raw JSON meta on customer facing order screens (order
	 * received page, order details, emails) with a readable table.
	 *
	 * @param string         $html Existing item meta html.
	 * @param \WC_Order_Item $item Order line item.
	 * @param array          $args Display arguments.
	 * @return string
	 */
	public function display_item_meta( string $html, \WC_Order_Item $item, array $args ): string {
		$data = DataHelpers::decode( $item->get_meta( 'openforms_data' ) );

		if ( empty( $data ) ) {
			return $html;
		}

		$rows = '';

		foreach ( $data as $entry ) {
			// Files are shown without a link: the download endpoint only
			// serves them while the token is still in the cart or to shop
			// staff, so a customer-facing link would fail after checkout.
			$value = 'file' === $entry['type'] ? DataHelpers::render_file( $entry, false, true ) : esc_html( $entry['value'] );
			$rows .= '<tr><th>' . esc_html( $entry['field_name'] ) . '</th><td>' . $value . '</td></tr>';
		}

		return '<table class="openforms-item-meta">' . $rows . '</table>';
	}

	/**
	 * Load the frontend stylesheet on the order screens that render item meta.
	 */
	public function enqueue(): void {
		if ( ! is_order_received_page() && ! is_view_order_page() ) {
			return;
		}

		wp_enqueue_style( 'openforms-frontend', OPENFORMS_URL . 'assets/css/frontend.css', array(), OPENFORMS_VERSION );
	}
}
