<?php
/**
 * Form payload helpers. Handles the JSON blob that travels with cart items
 * and order line items.
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes, decodes and inspects the submitted form payload.
 */
class DataHelpers {

	/**
	 * Decode a stored openforms_data JSON string into an array.
	 *
	 * @param string $json Stored JSON payload.
	 * @return array
	 */
	public static function decode( $json ) {
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * The file tokens referenced by a decoded openforms_data array.
	 *
	 * @param array $data Decoded openforms_data.
	 * @return array
	 */
	public static function file_tokens( $data ) {
		$tokens = array();

		foreach ( (array) $data as $entry ) {
			if ( isset( $entry['type'] ) && 'file' === $entry['type'] && ! empty( $entry['file_key'] ) ) {
				$tokens[] = $entry['file_key'];
			}
		}

		return $tokens;
	}

	/**
	 * Render a file entry's value: name with optional download link and size.
	 *
	 * @param array $entry     Decoded file entry.
	 * @param bool  $with_link Whether to link to the download endpoint.
	 * @param bool  $with_size Whether to append the formatted file size.
	 * @return string
	 */
	public static function render_file( $entry, $with_link = true, $with_size = false ) {
		$name = esc_html( $entry['file_name'] );
		$size = $with_size && ! empty( $entry['file_size'] ) ? ' (' . esc_html( size_format( $entry['file_size'] ) ) . ')' : '';

		if ( ! $with_link ) {
			return $name . $size;
		}

		return '<a href="' . esc_url( $entry['file_url'] ) . '" target="_blank" rel="noopener">' . $name . '</a>' . $size;
	}
}
