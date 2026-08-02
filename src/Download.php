<?php
/**
 * Authorized file delivery. Uploaded files are stored outside the public
 * uploads path and are only handed out to shop staff or to the shopper whose
 * active cart still holds the file's token.
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

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
	 */
	public function maybe_download() {
		$token = isset( $_GET['openforms_download'] ) ? sanitize_text_field( wp_unslash( $_GET['openforms_download'] ) ) : '';

		if ( '' === $token || ! $this->is_valid_token( $token ) ) {
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
	 * Check the token looks like the random hex keys we generate.
	 *
	 * @param string $token File token.
	 * @return bool
	 */
	private function is_valid_token( $token ) {
		return (bool) preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Locate the stored file matching a token.
	 *
	 * @param string $token File token.
	 * @return string|null
	 */
	private function find_file( $token ) {
		$match = glob( Fields::upload_dir() . '/' . $token . '-*' );

		if ( empty( $match ) ) {
			return null;
		}

		$file = $match[0];

		return ( is_file( $file ) && is_readable( $file ) ) ? $file : null;
	}

	/**
	 * Whether the current shopper's cart still references the file token.
	 *
	 * @param string $token File token.
	 * @return bool
	 */
	private function token_in_cart( $token ) {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) || ! is_object( WC()->cart ) ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['openforms_data'] ) ) {
				continue;
			}

			$data = json_decode( $cart_item['openforms_data'], true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			foreach ( $data as $entry ) {
				if ( 'file' === $entry['type'] && ! empty( $entry['file_key'] ) && hash_equals( $token, $entry['file_key'] ) ) {
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
	private function serve( $file ) {
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
