<?php
/**
 * Cleanup: deletes uploaded files that are no longer referenced anywhere once
 * their quote is deleted, and sweeps up orphaned uploads that no longer belong
 * to a quote or another active module.
 */

namespace OpenForms\Services;

use OpenForms\FieldHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes uploads whose only reference was a deleted quote.
 */
class Cleanup {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Abandoned uploads leave files no hook ever sees.
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
		add_action( 'openforms_cleanup_orphans', array( $this, 'cleanup_orphans' ) );

		// Quote attachments are private to their quote, so they go with it.
		add_action( 'before_delete_post', array( $this, 'cleanup_quote_post' ), 1 );
	}

	/**
	 * Delete the attachment stored for a quote that is about to be deleted. The
	 * token only ever belongs to that quote, so no other reference is checked.
	 *
	 * @param int $post_id Post ID.
	 */
	public function cleanup_quote_post( int $post_id ): void {
		if ( 'openforms_quote' !== get_post_type( $post_id ) ) {
			return;
		}

		$files = get_post_meta( $post_id, QuoteHandler::FILE_META, true );

		if ( ! is_array( $files ) ) {
			return;
		}

		if ( isset( $files['file_key'] ) ) {
			$files = array( $files );
		}

		foreach ( $files as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			// Older quotes stored a flat list of files; newer ones group them
			// by the field that received them.
			if ( isset( $group['file_key'] ) ) {
				$this->delete_file( $group['file_key'] );
				continue;
			}

			foreach ( $group as $file ) {
				if ( is_array( $file ) && ! empty( $file['file_key'] ) ) {
					$this->delete_file( $file['file_key'] );
				}
			}
		}
	}

	/**
	 * Schedule the daily orphan sweep once.
	 */
	public function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'openforms_cleanup_orphans' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'openforms_cleanup_orphans' );
		}
	}

	/**
	 * Delete files no quote or other module references. Cart references live in
	 * the session and cannot be queried, so only files past the grace period
	 * count.
	 */
	public function cleanup_orphans(): void {
		$files = glob( FieldHelpers::upload_dir() . '/*' );

		if ( ! is_array( $files ) ) {
			return;
		}

		$cutoff = time() - self::orphan_age();

		foreach ( $files as $path ) {
			if ( ! is_file( $path ) || filemtime( $path ) > $cutoff ) {
				continue;
			}

			$parts = explode( '-', basename( $path ), 2 );

			if ( count( $parts ) < 2 || ! FieldHelpers::is_valid_token( $parts[0] ) ) {
				continue;
			}

			$token = $parts[0];

			if ( $this->token_in_quotes( $token ) || $this->file_token_in_use( $token ) ) {
				continue;
			}

			$this->delete_file( $token );
		}
	}

	/**
	 * Age an unreferenced upload is kept, in seconds.
	 *
	 * @return int
	 */
	private static function orphan_age(): int {
		/**
		 * Filters how long an orphaned upload is kept, in seconds.
		 *
		 * @param int $seconds Age before the file is deleted.
		 */
		return (int) apply_filters( 'openforms_orphan_file_age', WEEK_IN_SECONDS );
	}

	/**
	 * Whether a quote still references the file token.
	 *
	 * @param string $token File token.
	 * @return bool
	 */
	private function token_in_quotes( string $token ): bool {
		global $wpdb;

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				QuoteHandler::FILE_META,
				'%' . $wpdb->esc_like( $token ) . '%'
			)
		);

		return (bool) $found;
	}

	/**
	 * Whether another module still references the file token (for example, a
	 * WooCommerce order line item provided by the companion plugin).
	 *
	 * @param string $token File token.
	 * @return bool
	 */
	private function file_token_in_use( string $token ): bool {
		/**
		 * Filters whether another module still references an uploaded file.
		 *
		 * @param bool   $in_use Whether the token is referenced elsewhere.
		 * @param string $token  File token.
		 */
		return (bool) apply_filters( 'openforms_file_token_in_use', false, $token );
	}

	/**
	 * Delete the stored file(s) for a token.
	 *
	 * @param string $token File token.
	 */
	private function delete_file( string $token ): void {
		foreach ( FieldHelpers::files_for_token( $token ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
