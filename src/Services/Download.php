<?php
/**
 * Download: authorized file delivery. Uploaded files are stored outside the
 * public uploads path and are only handed out to shop staff or to the shopper
 * whose active cart still holds the file's token.
 */

namespace OpenForms\Services;

use OpenForms\DataHelpers;
use OpenForms\FieldHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves files from the private uploads folder through a download endpoint.
 */
class Download {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_download' ) );
	}

	/**
	 * Serve the requested file when a valid token is present and the current
	 * user is allowed to see it.
	 *
	 * @return void
	 */
	public function maybe_download(): void {
		$token = isset( $_GET['openforms_download'] ) ? sanitize_text_field( wp_unslash( $_GET['openforms_download'] ) ) : '';

		if ( '' === $token || ! FieldHelpers::is_valid_token( $token ) ) {
			return;
		}

		$file = $this->find_file( $token );

		if ( ! $file ) {
			wp_die( esc_html__( 'File not found.', 'openforms' ), '', array( 'response' => 404 ) );
		}

		if ( ! $this->can_download( $token ) && ! $this->token_in_cart( $token ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'openforms' ), '', array( 'response' => 403 ) );
		}

		$this->serve( $file );
	}

	/**
	 * Locate the stored file matching a token.
	 *
	 * @param string $token File token.
	 * @return string|null
	 */
	private function find_file( string $token ): ?string {
		$files = FieldHelpers::files_for_token( $token );

		return ! empty( $files ) && is_readable( $files[0] ) ? $files[0] : null;
	}

	/**
	 * Whether the current user may download a token that is not in their cart.
	 * Access follows the record the file belongs to: order attachments are for
	 * shop staff, and a quote attachment is for whoever can edit that quote.
	 *
	 * @param string $token File token.
	 * @return bool
	 */
	private function can_download( string $token ): bool {
		if ( current_user_can( 'edit_shop_orders' ) ) {
			return true;
		}

		$quote_id = $this->quote_id_for_token( $token );

		return $quote_id > 0 && current_user_can( 'edit_post', $quote_id );
	}

	/**
	 * The quote that owns a file token, or 0 when no quote references it. The
	 * token is random, so matching it anywhere in the stored file details is
	 * enough to identify the quote holding it.
	 *
	 * @param string $token File token.
	 * @return int
	 */
	private function quote_id_for_token( string $token ): int {
		global $wpdb;

		$post_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				QuoteHandler::FILE_META,
				'%' . $wpdb->esc_like( $token ) . '%'
			)
		);

		return (int) $post_id;
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

	/**
	 * Stream the file to the browser as a forced download so the stored
	 * content is never rendered inside a browser origin.
	 *
	 * @param string $file Absolute file path.
	 */
	private function serve( string $file ): void {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		$mime = wp_check_filetype( $file )['type'];

		header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Content-Length: ' . (int) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations.readfile

		exit;
	}
}
