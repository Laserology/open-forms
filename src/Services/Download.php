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

		if ( ! current_user_can( 'edit_shop_orders' ) && ! $this->token_in_cart( $token ) ) {
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
