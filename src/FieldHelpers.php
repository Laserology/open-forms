<?php
/**
 * FieldHelpers: field type registry and per-field helpers (render, validate,
 * sanitize, uploads).
 */

namespace OpenForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the available field types and the shared helpers used by the
 * admin, frontend and checkout modules.
 */
class FieldHelpers {

	/**
	 * Registered field types.
	 *
	 * New types can be added via the `openforms_field_types` filter.
	 *
	 * @return array
	 */
	public static function types(): array {
		return apply_filters(
			'openforms_field_types',
			array(
				'text'     => array( 'label' => __( 'Text', 'openforms' ), 'input' => 'text' ),
				'textarea' => array( 'label' => __( 'Textarea', 'openforms' ), 'input' => 'textarea' ),
				'url'      => array( 'label' => __( 'URL', 'openforms' ), 'input' => 'url' ),
				'email'    => array( 'label' => __( 'Email', 'openforms' ), 'input' => 'email' ),
				'number'   => array( 'label' => __( 'Number', 'openforms' ), 'input' => 'number' ),
				'file'     => array( 'label' => __( 'File Upload', 'openforms' ), 'input' => 'file' ),
			)
		);
	}

	/**
	 * Get the enabled, cleaned field list for a product or variation.
	 *
	 * @param int $post_id Product or variation post ID.
	 * @return array Fields keyed by field key.
	 */
	public static function for_post( int $post_id ): array {
		if ( 'yes' !== get_post_meta( $post_id, '_openforms_enabled', true ) ) {
			return array();
		}

		$fields = get_post_meta( $post_id, '_openforms_fields', true );

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return array();
		}

		$clean = array();

		foreach ( $fields as $field ) {
			$name = isset( $field['name'] ) ? sanitize_text_field( $field['name'] ) : '';
			$type = isset( $field['type'] ) && isset( self::types()[ $field['type'] ] ) ? $field['type'] : 'text';
			$key  = isset( $field['key'] ) ? $field['key'] : sanitize_title( $name );

			if ( '' === $name ) {
				continue;
			}

			$clean[ $key ] = array(
				'key'      => $key,
				'name'     => $name,
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
			);
		}

		return $clean;
	}

	/**
	 * Render a single form field.
	 *
	 * @param string $input_key Key used for the input name attribute.
	 * @param array  $field     Field definition.
	 * @return string
	 */
	public static function render( string $input_key, array $field ): string {
		$type      = isset( $field['type'] ) ? $field['type'] : 'text';
		$config    = isset( self::types()[ $type ] ) ? self::types()[ $type ] : self::types()['text'];
		$input     = $config['input'];
		$required  = ! empty( $field['required'] );
		$required_mark = $required ? ' <span class="required">*</span>' : '';

		$output  = '<p class="openforms-field openforms-field-' . esc_attr( $type ) . '">';
		$output .= '<label class="openforms-label" for="openforms-' . esc_attr( $input_key ) . '">' . esc_html( $field['name'] ) . $required_mark . '</label>';

		if ( 'textarea' === $input ) {
			$output .= '<textarea name="openforms[' . esc_attr( $input_key ) . ']" id="openforms-' . esc_attr( $input_key ) . '" class="openforms-input" rows="3"></textarea>';
		} else {
			$output .= '<input type="' . esc_attr( $input ) . '" name="openforms[' . esc_attr( $input_key ) . ']" id="openforms-' . esc_attr( $input_key ) . '" class="openforms-input" />';
		}

		$output .= '</p>';

		return $output;
	}

	/**
	 * Validate a submitted value for a field.
	 *
	 * @param string $input_key Input name key.
	 * @param array  $field     Field definition.
	 * @return array List of error messages (empty when valid).
	 */
	public static function validate( string $input_key, array $field ): array {
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$name     = isset( $field['name'] ) ? $field['name'] : '';
		$required = ! empty( $field['required'] );

		if ( 'file' === $type ) {
			if ( $required && empty( $_FILES['openforms']['name'][ $input_key ] ) ) {
				/* translators: %s: field name. Shown when a required file field is left empty. */
				return array( sprintf( __( '%s is required.', 'openforms' ), $name ) );
			}

			// Run the same checks the move will run, so a file that cannot be
			// stored blocks the add to cart instead of being dropped afterwards.
			$error = self::upload_error( 'openforms', $input_key );

			return '' === $error ? array() : array( self::upload_error_message( $error, $name ) );
		}

		$value = isset( $_POST['openforms'][ $input_key ] ) ? wp_unslash( $_POST['openforms'][ $input_key ] ) : '';
		$value = is_string( $value ) ? $value : '';

		if ( $required && '' === trim( $value ) ) {
			/* translators: %s: field name. Shown when a required field is left empty. */
			return array( sprintf( __( '%s is required.', 'openforms' ), $name ) );
		}

		if ( '' !== trim( $value ) ) {
			if ( 'url' === $type && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
				/* translators: %s: field name. Shown when the value is not a well-formed URL. */
				return array( sprintf( __( '%s is not a valid URL.', 'openforms' ), $name ) );
			}

			if ( 'email' === $type && ! is_email( $value ) ) {
				/* translators: %s: field name. Shown when the value is not a well-formed email address. */
				return array( sprintf( __( '%s is not a valid email address.', 'openforms' ), $name ) );
			}

			if ( 'number' === $type && ! is_numeric( $value ) ) {
				/* translators: %s: field name. Shown when the value is not numeric. */
				return array( sprintf( __( '%s must be a number.', 'openforms' ), $name ) );
			}
		}

		return array();
	}

	/**
	 * Sanitize a submitted value based on the field type.
	 *
	 * Expects an already unslashed value.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Submitted value.
	 * @return string
	 */
	public static function sanitize( string $type, mixed $value ): string {
		switch ( $type ) {
			case 'textarea':
				return (string) sanitize_textarea_field( $value );
			case 'url':
				return (string) esc_url_raw( $value );
			case 'email':
				return (string) sanitize_email( $value );
			case 'number':
				return is_numeric( $value ) ? (string) $value : '';
			default:
				return (string) sanitize_text_field( $value );
		}
	}

	/**
	 * Whether the token looks like the random hex keys generated on upload.
	 *
	 * @param string $token File token.
	 * @return bool
	 */
	public static function is_valid_token( string $token ): bool {
		return (bool) preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * The readable stored files matching a token. Tokens are prefixed onto the
	 * original file name, so glob catches every file stored under one token.
	 *
	 * @param string $token File token.
	 * @return array
	 */
	public static function files_for_token( string $token ): array {
		if ( ! self::is_valid_token( $token ) ) {
			return array();
		}

		$match = glob( self::upload_dir() . '/' . $token . '-*' );

		return array_values( array_filter( $match, 'is_file' ) );
	}

	/**
	 * Path to the private uploads folder. Files stored here are never served
	 * directly: the folder carries the server protection files, and content is
	 * handed out through the authorized Download endpoint only. Hosts whose web
	 * server ignores those files (Nginx, for example) can move the folder out of
	 * the document root with the `openforms_upload_dir` filter.
	 *
	 * @return string
	 */
	public static function upload_dir(): string {
		$dir = wp_upload_dir();
		$dir = $dir['basedir'] . '/openforms';

		/**
		 * Filters the private folder uploads are stored in.
		 *
		 * @param string $dir Absolute path to the storage folder.
		 */
		$dir = (string) apply_filters( 'openforms_upload_dir', $dir );

		self::protect_dir( $dir );

		return $dir;
	}

	/**
	 * Create the storage folder and make sure the server protection files are in
	 * place. They are checked rather than written once, so a file that is deleted
	 * later is restored on the next upload or download instead of silently leaving
	 * the folder open over the web.
	 *
	 * @param string $dir Absolute path to the storage folder.
	 */
	private static function protect_dir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}

		// Block direct web access and stop PHP from executing in here.
		$files = array(
			'index.php' => '<?php' . PHP_EOL . '// Silence is golden.' . PHP_EOL,
			'.htaccess' => '# Apache 2.2' . PHP_EOL .
				'<IfModule !mod_authz_core.c>' . PHP_EOL .
				'Order deny,allow' . PHP_EOL .
				'Deny from all' . PHP_EOL .
				'</IfModule>' . PHP_EOL .
				'# Apache 2.4+' . PHP_EOL .
				'<IfModule mod_authz_core.c>' . PHP_EOL .
				'Require all denied' . PHP_EOL .
				'</IfModule>' . PHP_EOL,
		);

		foreach ( $files as $name => $contents ) {
			$path = $dir . '/' . $name;

			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Move an uploaded file from the product form into the private openforms
	 * uploads folder. Files are stored under a random token so they can only be
	 * reached through the authorized download endpoint, never by guessing their
	 * URL. Adds an error notice when a submitted upload cannot be stored.
	 *
	 * @param string $input_key Input name key.
	 * @return array|null File details or null on failure.
	 */
	public static function save_file( string $input_key ): ?array {
		$file = self::upload_file( 'openforms', $input_key );

		if ( $file ) {
			return $file;
		}

		// Nothing was submitted (so there is no message to show), or the upload
		// was rejected. Either way, report it and skip the attachment.
		$error = self::upload_error( 'openforms', $input_key );

		if ( '' !== $error ) {
			wc_add_notice( self::upload_error_message( $error, self::upload_name( 'openforms', $input_key ) ), 'error' );
		}

		return null;
	}

	/**
	 * Validate and move one uploaded file out of a $_FILES group into the private
	 * folder. No notices are added, so callers that are not the product form (the
	 * quote form, for example) can report a failure in their own way.
	 *
	 * @param string $group     $_FILES group name.
	 * @param string $input_key Input name key inside the group.
	 * @param array  $mimes     Optional extension => mime map to restrict the
	 *                          upload to. Empty accepts the site's own types.
	 * @return array|null File details, or null when nothing valid was uploaded.
	 */
	public static function upload_file( string $group, string $input_key, array $mimes = array() ): ?array {
		if ( '' !== self::upload_error( $group, $input_key, $mimes ) ) {
			return null;
		}

		$tmp_name = $_FILES[ $group ]['tmp_name'][ $input_key ];
		$name     = self::upload_name( $group, $input_key );

		$folder = self::upload_dir();
		$token  = bin2hex( random_bytes( 16 ) );
		$dest   = $folder . '/' . $token . '-' . $name;

		if ( ! move_uploaded_file( $tmp_name, $dest ) ) {
			return null;
		}

		return array(
			'file_key'  => $token,
			'file_name' => $name,
			'file_url'  => add_query_arg( 'openforms_download', $token, home_url( '/' ) ),
			'file_path' => $dest,
			'file_size' => (int) filesize( $dest ),
		);
	}

	/**
	 * Why a submitted upload would be rejected, or an empty string when it can be
	 * stored. Validation and the move both go through this, so a file that cannot
	 * be stored blocks the submission instead of being dropped afterwards.
	 *
	 * @param string $group     $_FILES group name.
	 * @param string $input_key Input name key inside the group.
	 * @param array  $mimes     Optional extension => mime map to restrict the
	 *                          upload to. Empty accepts the site's own types.
	 * @return string One of 'size', 'type' or 'upload', or an empty string.
	 */
	public static function upload_error( string $group, string $input_key, array $mimes = array() ): string {
		if ( empty( $_FILES[ $group ]['name'][ $input_key ] ) ) {
			return '';
		}

		$error = isset( $_FILES[ $group ]['error'][ $input_key ] ) ? (int) $_FILES[ $group ]['error'][ $input_key ] : UPLOAD_ERR_OK;

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return 'size';
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			return 'upload';
		}

		$tmp_name = isset( $_FILES[ $group ]['tmp_name'][ $input_key ] ) ? $_FILES[ $group ]['tmp_name'][ $input_key ] : '';
		$name     = self::upload_name( $group, $input_key );

		if ( empty( $tmp_name ) || '' === $name || ! is_uploaded_file( $tmp_name ) ) {
			return 'upload';
		}

		// The size reported in $_FILES comes from the client, so the limit is
		// checked against the real file on disk.
		if ( filesize( $tmp_name ) > self::max_upload_size() ) {
			return 'size';
		}

		// Only extensions the site allows, or the caller's narrower list, pass.
		$filetype = wp_check_filetype_and_ext( $tmp_name, $name, $mimes ? $mimes : null );

		if ( empty( $filetype['type'] ) || empty( $filetype['ext'] ) ) {
			return 'type';
		}

		return '';
	}

	/**
	 * The message shown for a rejected upload.
	 *
	 * @param string $error      Code returned by upload_error().
	 * @param string $field_name Field or file name to report back.
	 * @return string
	 */
	public static function upload_error_message( string $error, string $field_name ): string {
		if ( 'size' === $error ) {
			/* translators: %s: field or file name. Shown when an upload is larger than the allowed maximum. */
			return sprintf( __( '%s is too large.', 'openforms' ), $field_name );
		}

		if ( 'type' === $error ) {
			/* translators: %s: field or file name. Shown when the uploaded file type is not allowed. */
			return sprintf( __( '%s is not an allowed file type.', 'openforms' ), $field_name );
		}

		/* translators: %s: field or file name. Shown when an upload fails or cannot be stored. */
		return sprintf( __( '%s could not be uploaded.', 'openforms' ), $field_name );
	}

	/**
	 * The largest upload accepted, in bytes. Still bounded by the server's own
	 * upload limits; raise or lower it with the filter.
	 *
	 * @return int
	 */
	public static function max_upload_size(): int {
		/**
		 * Filters the maximum size of a stored upload, in bytes.
		 *
		 * @param int $size Maximum size in bytes.
		 */
		return (int) apply_filters( 'openforms_max_upload_size', 75 * MB_IN_BYTES );
	}

	/**
	 * The sanitized file name submitted for an upload, or an empty string.
	 *
	 * @param string $group     $_FILES group name.
	 * @param string $input_key Input name key inside the group.
	 * @return string
	 */
	private static function upload_name( string $group, string $input_key ): string {
		$name = isset( $_FILES[ $group ]['name'][ $input_key ] ) ? $_FILES[ $group ]['name'][ $input_key ] : '';

		return is_string( $name ) ? sanitize_file_name( $name ) : '';
	}
}
