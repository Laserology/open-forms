<?php
/**
 * ProductFields: reads the field list configured on a product or variation.
 */

namespace OpenFormsWooCommerce;

use OpenForms\FieldHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and cleans the form configuration stored on a product or variation.
 */
class ProductFields {

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
			$type = isset( $field['type'] ) && isset( FieldHelpers::types()[ $field['type'] ] ) ? $field['type'] : 'text';
			$key  = isset( $field['key'] ) ? $field['key'] : sanitize_title( $name );

			if ( '' === $name ) {
				continue;
			}

			$options = 'select' === $type ? FieldHelpers::options( isset( $field['options'] ) ? $field['options'] : array() ) : array();

			$clean[ $key ] = array(
				'key'      => $key,
				'name'     => $name,
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'options'  => $options,
				'default'  => FieldHelpers::sanitize_default( $type, isset( $field['default'] ) ? $field['default'] : '', $options ),
			);
		}

		return $clean;
	}
}
