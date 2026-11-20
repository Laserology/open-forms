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
 * quote form and the WooCommerce add-on.
 */
class FieldHelpers {

	/**
	 * Cached upload throttle decision for the current request.
	 *
	 * @var bool|null
	 */
	private static ?bool $upload_decision = null;

	/**
	 * Whether this request has already been counted against the throttle.
	 *
	 * @var bool
	 */
	private static bool $upload_recorded = false;

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
				'text'     => array( 'label' => __( 'One-line text', 'open-forms' ), 'input' => 'text' ),
				'textarea' => array( 'label' => __( 'Multi-line text', 'open-forms' ), 'input' => 'textarea' ),
				'email'    => array( 'label' => __( 'Email', 'open-forms' ), 'input' => 'email' ),
				'url'      => array( 'label' => __( 'URL', 'open-forms' ), 'input' => 'url' ),
				'tel'      => array( 'label' => __( 'Phone number', 'open-forms' ), 'input' => 'tel' ),
				'number'   => array( 'label' => __( 'Number', 'open-forms' ), 'input' => 'number' ),
				'password' => array( 'label' => __( 'Password', 'open-forms' ), 'input' => 'password' ),
				'color'    => array( 'label' => __( 'Colour', 'open-forms' ), 'input' => 'color' ),
				'date'     => array( 'label' => __( 'Date', 'open-forms' ), 'input' => 'date' ),
				'time'     => array( 'label' => __( 'Time', 'open-forms' ), 'input' => 'time' ),
				'file'     => array( 'label' => __( 'File upload', 'open-forms' ), 'input' => 'file' ),
				'select'   => array( 'label' => __( 'Dropdown', 'open-forms' ), 'input' => 'select', 'options' => true ),
			)
		);
	}

	/**
	 * Clean a newline separated option list into an array of labels.
	 *
	 * @param mixed $value Newline separated string or array of labels.
	 * @return array
	 */
	public static function options( mixed $value ): array {
		$lines   = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) $value );
		$options = array();

		foreach ( (array) $lines as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}

			$label = trim( sanitize_text_field( (string) $line ) );

			if ( '' !== $label && ! in_array( $label, $options, true ) ) {
				$options[] = $label;
			}
		}

		return $options;
	}

	/**
	 * Render a single form field.
	 *
	 * @param string $input_key Key used for the input name attribute.
	 * @param array  $field     Field definition.
	 * @return string
	 */
	public static function render( string $input_key, array $field ): string {
		$type          = isset( $field['type'] ) ? $field['type'] : 'text';
		$config        = isset( self::types()[ $type ] ) ? self::types()[ $type ] : self::types()['text'];
		$input         = $config['input'];
		$required      = ! empty( $field['required'] );
		$required_mark = $required ? ' <span class="required">*</span>' : '';
		$default       = isset( $field['default'] ) ? (string) $field['default'] : '';

		$output  = '<p class="openforms-field openforms-field-' . esc_attr( $type ) . '">';
		$output .= '<label class="openforms-label" for="openforms-' . esc_attr( $input_key ) . '">' . esc_html( $field['name'] ) . $required_mark . '</label>';

		if ( 'textarea' === $input ) {
			$output .= '<textarea name="openforms[' . esc_attr( $input_key ) . ']" id="openforms-' . esc_attr( $input_key ) . '" class="openforms-input" rows="3">' . esc_textarea( $default ) . '</textarea>';
		} elseif ( 'file' === $type ) {
			$output .= '<input type="file" name="openforms[' . esc_attr( $input_key ) . '][]" id="openforms-' . esc_attr( $input_key ) . '" class="openforms-input" multiple />';
		} elseif ( 'select' === $input ) {
			$options = isset( $field['options'] ) ? (array) $field['options'] : array();

			$output .= '<select name="openforms[' . esc_attr( $input_key ) . ']" id="openforms-' . esc_attr( $input_key ) . '" class="openforms-input">';
			$output .= '<option value=""' . selected( '', $default, false ) . '>' . esc_html__( 'Select an option…', 'open-forms' ) . '</option>';

			foreach ( $options as $option ) {
				$output .= '<option value="' . esc_attr( $option ) . '"' . selected( $option, $default, false ) . '>' . esc_html( $option ) . '</option>';
			}

			$output .= '</select>';
		} else {
			$output .= '<input type="' . esc_attr( $input ) . '" name="openforms[' . esc_attr( $input_key ) . ']" id="openforms-' . esc_attr( $input_key ) . '" class="openforms-input" value="' . esc_attr( $default ) . '" />';
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
			if ( $required && ! self::has_files( 'openforms', $input_key ) ) {
				/* translators: %s: field name. Shown when a required file field is left empty. */
				return array( sprintf( __( '%s is required.', 'open-forms' ), $name ) );
			}

			// Run the same checks the move will run, so a file that cannot be
			// stored blocks the add to cart instead of being dropped afterwards.
			$error = self::upload_error( 'openforms', $input_key );

			return '' === $error ? array() : array( self::upload_error_message( $error, $name ) );
		}

		$value = isset( $_POST['openforms'][ $input_key ] ) ? wp_unslash( $_POST['openforms'][ $input_key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public form submissions are not nonce verified; the value is sanitized by sanitize() before use.
		$value = is_string( $value ) ? $value : '';

		if ( $required && '' === trim( $value ) ) {
			/* translators: %s: field name. Shown when a required field is left empty. */
			return array( sprintf( __( '%s is required.', 'open-forms' ), $name ) );
		}

		if ( '' !== trim( $value ) ) {
			if ( 'url' === $type && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
				/* translators: %s: field name. Shown when the value is not a well-formed URL. */
				return array( sprintf( __( '%s is not a valid URL.', 'open-forms' ), $name ) );
			}

			if ( 'email' === $type && ! is_email( $value ) ) {
				/* translators: %s: field name. Shown when the value is not a well-formed email address. */
				return array( sprintf( __( '%s is not a valid email address.', 'open-forms' ), $name ) );
			}

			if ( 'number' === $type && ! is_numeric( $value ) ) {
				/* translators: %s: field name. Shown when the value is not numeric. */
				return array( sprintf( __( '%s must be a number.', 'open-forms' ), $name ) );
			}

			if ( 'color' === $type && ! sanitize_hex_color( $value ) ) {
				/* translators: %s: field name. Shown when the value is not a valid colour. */
				return array( sprintf( __( '%s is not a valid colour.', 'open-forms' ), $name ) );
			}

			if ( 'select' === $type && ! empty( $field['options'] ) && ! in_array( $value, (array) $field['options'], true ) ) {
				/* translators: %s: field name. Shown when a value is not one of the configured options. */
				return array( sprintf( __( '%s must be one of the available options.', 'open-forms' ), $name ) );
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
			case 'color':
				return (string) sanitize_hex_color( (string) $value );
			default:
				return (string) sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitize a field's default value for its type.
	 *
	 * Defaults are authored by an admin, so they are cleaned once when the form
	 * is saved and rendered as-is afterwards.
	 *
	 * @param string $type    Field type.
	 * @param mixed  $value   Submitted default.
	 * @param array  $options Allowed labels for a dropdown field.
	 * @return string
	 */
	public static function sanitize_default( string $type, mixed $value, array $options = array() ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		switch ( $type ) {
			case 'textarea':
				return (string) sanitize_textarea_field( $value );
			case 'email':
				return (string) sanitize_email( $value );
			case 'url':
				return (string) esc_url_raw( $value );
			case 'number':
				return is_numeric( $value ) ? (string) $value : '';
			case 'color':
				return (string) sanitize_hex_color( $value );
			case 'select':
				return in_array( $value, $options, true ) ? $value : '';
			case 'file':
				return '';
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
	 * The requester's IP address, used to rate limit public uploads.
	 *
	 * Forwarded headers are client controlled, so they are only used when a
	 * site opts in.
	 *
	 * @return string A validated IP address, or an empty string when unknown.
	 */
	public static function client_ip(): string {
		/**
		 * Filters whether trusted proxy headers may supply the client address.
		 *
		 * @param bool $trust Whether the forwarded headers can be trusted.
		 */
		if ( apply_filters( 'openforms_trust_proxy_headers', false ) && class_exists( 'WC_Geolocation' ) ) {
			$ip = (string) \WC_Geolocation::get_ip_address();

			if ( '' !== $ip ) {
				return $ip;
			}
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
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
	 * Validate and move every uploaded file for one field into the private
	 * folder. No notices are added, so callers can report a failure themselves.
	 *
	 * @param string $group     $_FILES group name.
	 * @param string $input_key Input name key inside the group.
	 * @param array  $mimes     Optional extension => mime map to restrict the
	 *                          upload to. Empty accepts the site's own types.
	 * @return array Stored file details (empty on failure).
	 */
	public static function upload_files( string $group, string $input_key, array $mimes = array() ): array {
		if ( '' !== self::upload_error( $group, $input_key, $mimes ) ) {
			return array();
		}

		$folder = self::upload_dir();
		$stored = array();

		foreach ( self::files( $group, $input_key ) as $file ) {
			$name = sanitize_file_name( $file['name'] );

			if ( '' === $name ) {
				continue;
			}

			$token = bin2hex( random_bytes( 16 ) );
			$dest  = $folder . '/' . $token . '-' . $name;

			if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- move_uploaded_file() is the correct API for storing an uploaded file.
				continue;
			}

			$stored[] = array(
				'file_key'  => $token,
				'file_name' => $name,
				'file_url'  => add_query_arg( 'openforms_download', $token, home_url( '/' ) ),
				'file_path' => $dest,
				'file_size' => (int) filesize( $dest ),
			);
		}

		if ( $stored ) {
			self::record_upload();
		}

		return $stored;
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
	 * @return string One of 'size', 'request_size', 'type', 'throttle' or 'upload', or an empty string.
	 */
	public static function upload_error( string $group, string $input_key, array $mimes = array() ): string {
		$files = self::files( $group, $input_key );

		if ( empty( $files ) ) {
			return '';
		}

		// Public form: bound how often an upload request can be stored.
		if ( ! self::upload_allowed() ) {
			return 'throttle';
		}

		$total = 0;

		foreach ( $files as $file ) {
			$error = (int) $file['error'];

			if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
				return 'size';
			}

			if ( UPLOAD_ERR_OK !== $error ) {
				return 'upload';
			}

			$tmp_name = $file['tmp_name'];
			$name     = sanitize_file_name( $file['name'] );

			if ( '' === $tmp_name || '' === $name || ! is_uploaded_file( $tmp_name ) ) {
				return 'upload';
			}

			// The size reported in $_FILES comes from the client, so the limit is
			// checked against the real file on disk.
			$size   = (int) filesize( $tmp_name );
			$total += $size;

			if ( $size > self::max_upload_size() ) {
				return 'size';
			}

			// Only extensions the site allows, or the caller's narrower list, pass.
			$filetype = wp_check_filetype_and_ext( $tmp_name, $name, $mimes ? $mimes : null );

			if ( empty( $filetype['type'] ) || empty( $filetype['ext'] ) ) {
				return 'type';
			}
		}

		if ( $total > self::max_request_upload_size() ) {
			return 'request_size';
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
			return sprintf( __( '%s is too large.', 'open-forms' ), $field_name );
		}

		if ( 'type' === $error ) {
			/* translators: %s: field or file name. Shown when the uploaded file type is not allowed. */
			return sprintf( __( '%s is not an allowed file type.', 'open-forms' ), $field_name );
		}

		if ( 'request_size' === $error ) {
			return __( 'The selected files are too large to upload together.', 'open-forms' );
		}

		if ( 'throttle' === $error ) {
			/* translators: Shown when an upload request is refused because the requester sent too many too recently. */
			return __( 'Too many uploads have been sent from this address. Please try again later.', 'open-forms' );
		}

		/* translators: %s: field or file name. Shown when an upload fails or cannot be stored. */
		return sprintf( __( '%s could not be uploaded.', 'open-forms' ), $field_name );
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
	 * The combined size of every file in one request, in bytes.
	 *
	 * @return int
	 */
	public static function max_request_upload_size(): int {
		/**
		 * Filters the combined size of all files uploaded in one request.
		 *
		 * @param int $size Maximum combined size in bytes.
		 */
		return (int) apply_filters( 'openforms_max_request_upload_size', 125 * MB_IN_BYTES );
	}

	/**
	 * Whether any file was submitted for one field.
	 *
	 * @param string $group     $_FILES group name.
	 * @param string $input_key Input name key inside the group.
	 * @return bool
	 */
	public static function has_files( string $group, string $input_key ): bool {
		return ! empty( self::files( $group, $input_key ) );
	}

	/**
	 * The combined size of the files submitted for one field, in bytes.
	 *
	 * @param string $group     $_FILES group name.
	 * @param string $input_key Input name key inside the group.
	 * @return int
	 */
	public static function upload_size( string $group, string $input_key ): int {
		$total = 0;

		foreach ( self::files( $group, $input_key ) as $file ) {
			if ( UPLOAD_ERR_OK === $file['error'] && is_uploaded_file( $file['tmp_name'] ) ) {
				$total += (int) filesize( $file['tmp_name'] );
			}
		}

		return $total;
	}

	/**
	 * The submitted files for one field, normalised from PHP's transposed
	 * multi-file $_FILES layout. Empty slots are dropped.
	 *
	 * @param string $group     $_FILES group name.
	 * @param string $input_key Input name key inside the group.
	 * @return array List of name/tmp_name/error details.
	 */
	private static function files( string $group, string $input_key ): array {
		if ( empty( $_FILES[ $group ]['name'][ $input_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public add-to-cart and quote form.
			return array();
		}

		$names = $_FILES[ $group ]['name'][ $input_key ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Names are sanitized when stored.

		if ( ! is_array( $names ) ) {
			$error = isset( $_FILES[ $group ]['error'][ $input_key ] ) ? (int) $_FILES[ $group ]['error'][ $input_key ] : UPLOAD_ERR_OK; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$tmp   = isset( $_FILES[ $group ]['tmp_name'][ $input_key ] ) && is_string( $_FILES[ $group ]['tmp_name'][ $input_key ] ) ? $_FILES[ $group ]['tmp_name'][ $input_key ] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( UPLOAD_ERR_NO_FILE === $error || ! is_string( $names ) ) {
				return array();
			}

			return array(
				array( 'name' => $names, 'tmp_name' => $tmp, 'error' => $error ),
			);
		}

		$files = array();

		foreach ( $names as $index => $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$error = isset( $_FILES[ $group ]['error'][ $input_key ][ $index ] ) ? (int) $_FILES[ $group ]['error'][ $input_key ][ $index ] : UPLOAD_ERR_OK; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}

			$tmp = isset( $_FILES[ $group ]['tmp_name'][ $input_key ][ $index ] ) && is_string( $_FILES[ $group ]['tmp_name'][ $input_key ][ $index ] ) ? $_FILES[ $group ]['tmp_name'][ $input_key ][ $index ] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$files[] = array( 'name' => $name, 'tmp_name' => $tmp, 'error' => $error );
		}

		return $files;
	}

	/**
	 * Upload requests one requester may make per window. 0 disables the limit.
	 *
	 * @return int
	 */
	private static function upload_throttle_limit(): int {
		/**
		 * Filters how many upload requests one requester may make per window.
		 *
		 * @param int $limit Maximum requests. 0 disables the limit.
		 */
		return (int) apply_filters( 'openforms_upload_throttle_limit', 3 );
	}

	/**
	 * The length of the upload throttle window, in seconds.
	 *
	 * @return int Window length in seconds.
	 */
	private static function upload_throttle_window(): int {
		/**
		 * Filters how long the upload throttle window lasts, in seconds.
		 *
		 * @param int $seconds Window length. 0 disables the limit.
		 */
		return (int) apply_filters( 'openforms_upload_throttle_window', 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Whether the requester may still upload in the current window. Decided once
	 * per request, so a request with several files counts as one.
	 *
	 * @return bool
	 */
	private static function upload_allowed(): bool {
		if ( null !== self::$upload_decision ) {
			return self::$upload_decision;
		}

		$key = self::upload_throttle_key();

		self::$upload_decision = '' === $key || (int) get_transient( $key ) < self::upload_throttle_limit();

		return self::$upload_decision;
	}

	/**
	 * Count one request against the requester's window.
	 */
	private static function record_upload(): void {
		if ( self::$upload_recorded ) {
			return;
		}

		$key = self::upload_throttle_key();

		if ( '' === $key ) {
			return;
		}

		self::$upload_recorded = true;

		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::upload_throttle_window() );
	}

	/**
	 * Upload request count key, or an empty string when the limit does not apply.
	 *
	 * @return string
	 */
	private static function upload_throttle_key(): string {
		if ( self::upload_throttle_limit() <= 0 || self::upload_throttle_window() <= 0 || current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			return '';
		}

		$ip = self::client_ip();

		return '' === $ip ? '' : 'openforms_upload_' . md5( $ip );
	}

	/**
	 * The sanitized file name submitted for an upload, or an empty string.
	 *
	 * @param string $group     $_FILES group name.
	 * @param string $input_key Input name key inside the group.
	 * @return string
	 */
	public static function upload_name( string $group, string $input_key ): string {
		$files = self::files( $group, $input_key );

		return $files ? sanitize_file_name( $files[0]['name'] ) : '';
	}
}
