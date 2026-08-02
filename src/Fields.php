<?php
/**
 * Field type registry and per-field helpers (render, validate, sanitize, uploads).
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the available field types and the shared helpers used by the
 * admin, frontend and checkout modules.
 */
class Fields {

	/**
	 * Registered field types.
	 *
	 * New types can be added via the `openforms_field_types` filter.
	 *
	 * @return array
	 */
	public static function types() {
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
	public static function for_post( $post_id ) {
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
	public static function render( $input_key, $field ) {
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
	public static function validate( $input_key, $field ) {
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$name     = isset( $field['name'] ) ? $field['name'] : '';
		$required = ! empty( $field['required'] );

		if ( 'file' === $type ) {
			if ( $required && empty( $_FILES['openforms']['name'][ $input_key ] ) ) {
				return array( sprintf( __( '%s is required.', 'openforms' ), $name ) );
			}

			if ( ! empty( $_FILES['openforms']['name'][ $input_key ] ) && (int) $_FILES['openforms']['error'][ $input_key ] !== UPLOAD_ERR_OK ) {
				return array( sprintf( __( '%s could not be uploaded.', 'openforms' ), $name ) );
			}

			return array();
		}

		$value = isset( $_POST['openforms'][ $input_key ] ) ? wp_unslash( $_POST['openforms'][ $input_key ] ) : '';
		$value = is_string( $value ) ? $value : '';

		if ( $required && '' === trim( $value ) ) {
			return array( sprintf( __( '%s is required.', 'openforms' ), $name ) );
		}

		if ( '' !== trim( $value ) ) {
			if ( 'url' === $type && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return array( sprintf( __( '%s is not a valid URL.', 'openforms' ), $name ) );
			}

			if ( 'email' === $type && ! is_email( $value ) ) {
				return array( sprintf( __( '%s is not a valid email address.', 'openforms' ), $name ) );
			}

			if ( 'number' === $type && ! is_numeric( $value ) ) {
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
	public static function sanitize( $type, $value ) {
		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'url':
				return esc_url_raw( $value );
			case 'email':
				return sanitize_email( $value );
			case 'number':
				return is_numeric( $value ) ? $value : '';
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Move an uploaded file into the openforms uploads directory.
	 *
	 * @param string $input_key Input name key.
	 * @return array|null File details or null on failure.
	 */
	public static function save_file( $input_key ) {
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
			wc_add_notice( sprintf( __( '%s could not be uploaded.', 'openforms' ), $name ), 'error' );
			return null;
		}

		$dir    = wp_upload_dir();
		$folder = $dir['path'] . '/openforms';

		if ( ! is_dir( $folder ) ) {
			wp_mkdir_p( $folder );
		}

		$file     = wp_unique_filename( $folder, $name );
		$dest     = $folder . '/' . $file;

		if ( ! move_uploaded_file( $tmp_name, $dest ) ) {
			wc_add_notice( sprintf( __( '%s could not be uploaded.', 'openforms' ), $name ), 'error' );
			return null;
		}

		return array(
			'file_name' => $name,
			'file_url'  => $dir['url'] . '/openforms/' . $file,
			'file_path' => $dest,
			'file_size' => isset( $_FILES['openforms']['size'][ $input_key ] ) ? (int) $_FILES['openforms']['size'][ $input_key ] : 0,
		);
	}
}
