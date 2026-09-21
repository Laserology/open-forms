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

			if ( ! empty( $_FILES['openforms']['name'][ $input_key ] ) && (int) $_FILES['openforms']['error'][ $input_key ] !== UPLOAD_ERR_OK ) {
				/* translators: %s: field name. Shown when the uploaded file fails validation. */
				return array( sprintf( __( '%s could not be uploaded.', 'openforms' ), $name ) );
			}

			return array();
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
	 * directly: the folder is denied over the web and content is handed out
	 * through the authorized Download endpoint only.
	 *
	 * @return string
	 */
	public static function upload_dir(): string {
		$dir = wp_upload_dir();
		$dir = $dir['basedir'] . '/openforms';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );

			// Block direct web access and stop PHP from executing in here.
			file_put_contents( $dir . '/index.php', '<?php' . PHP_EOL . '// Silence is golden.' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$dir . '/.htaccess',
				'# Apache 2.2' . PHP_EOL .
				'<IfModule !mod_authz_core.c>' . PHP_EOL .
				'Order deny,allow' . PHP_EOL .
				'Deny from all' . PHP_EOL .
				'</IfModule>' . PHP_EOL .
				'# Apache 2.4+' . PHP_EOL .
				'<IfModule mod_authz_core.c>' . PHP_EOL .
				'Require all denied' . PHP_EOL .
				'</IfModule>' . PHP_EOL
			);
		}

		return $dir;
	}

	/**
	 * Move an uploaded file into the private openforms uploads folder. Files
	 * are stored under a random token so they can only be reached through the
	 * authorized download endpoint, never by guessing their URL.
	 *
	 * @param string $input_key Input name key.
	 * @return array|null File details or null on failure.
	 */
	public static function save_file( string $input_key ): ?array {
		if ( empty( $_FILES['openforms']['name'][ $input_key ] ) ) {
			return null;
		}

		$tmp_name = $_FILES['openforms']['tmp_name'][ $input_key ];
		$name     = sanitize_file_name( $_FILES['openforms']['name'][ $input_key ] );

		if ( empty( $tmp_name ) || empty( $name ) ) {
			return null;
		}

		// Reject anything that does not pass the core filetype checks.
		$filetype = wp_check_filetype_and_ext( $tmp_name, $name );

		if ( empty( $filetype['type'] ) || empty( $filetype['ext'] ) ) {
			/* translators: %s: uploaded file name. Shown when the file type is rejected by WordPress. */
			wc_add_notice( sprintf( __( '%s could not be uploaded.', 'openforms' ), $name ), 'error' );
			return null;
		}

		$folder = self::upload_dir();
		$token  = bin2hex( random_bytes( 16 ) );
		$dest   = $folder . '/' . $token . '-' . $name;

		if ( ! move_uploaded_file( $tmp_name, $dest ) ) {
			/* translators: %s: uploaded file name. Shown when the file cannot be moved into the uploads folder. */
			wc_add_notice( sprintf( __( '%s could not be uploaded.', 'openforms' ), $name ), 'error' );
			return null;
		}

		return array(
			'file_key'  => $token,
			'file_name' => $name,
			'file_url'  => add_query_arg( 'openforms_download', $token, home_url( '/' ) ),
			'file_path' => $dest,
			'file_size' => isset( $_FILES['openforms']['size'][ $input_key ] ) ? (int) $_FILES['openforms']['size'][ $input_key ] : 0,
		);
	}
}
