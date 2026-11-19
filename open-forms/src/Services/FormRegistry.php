<?php
/**
 * FormRegistry: keeps track of the quote forms created in the block editor.
 *
 * Every "Get a Quote" block carries a random form ID and a user editable name.
 * The block editor writes both into the post, this registry mirrors them so the
 * admin can label submissions and validate them against the form that was
 * actually shown. A form leaves the registry once its block is removed and no
 * other post still uses it; submissions already stored keep their snapshot.
 */

namespace OpenForms\Services;

use OpenForms\FieldHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the form definitions found in post content and reconciles them on
 * save, trash and delete.
 */
class FormRegistry {

	/**
	 * Option holding every registered form, keyed by form ID.
	 *
	 * @var string
	 */
	const OPTION = 'openforms_forms';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'save_post', array( $this, 'sync_post' ), 20, 3 );
		add_action( 'untrashed_post', array( $this, 'sync_saved_post' ) );
		add_action( 'before_delete_post', array( $this, 'remove_source' ) );
		add_action( 'wp_trash_post', array( $this, 'remove_source' ) );
	}

	/**
	 * Every registered form, keyed by form ID.
	 *
	 * @return array
	 */
	public static function all(): array {
		$forms = get_option( self::OPTION, array() );

		return is_array( $forms ) ? $forms : array();
	}

	/**
	 * One registered form, with every key present.
	 *
	 * @param string $id Form ID.
	 * @return array
	 */
	public static function get( string $id ): array {
		$id    = sanitize_key( $id );
		$forms = self::all();
		$form  = isset( $forms[ $id ] ) ? $forms[ $id ] : array();

		return wp_parse_args(
			is_array( $form ) ? $form : array(),
			array(
				'id'      => $id,
				'name'    => '',
				'fields'  => array(),
				'sources' => array(),
				'updated' => 0,
			)
		);
	}

	/**
	 * Whether a form is registered.
	 *
	 * @param string $id Form ID.
	 * @return bool
	 */
	public static function exists( string $id ): bool {
		$forms = self::all();

		return isset( $forms[ sanitize_key( $id ) ] );
	}

	/**
	 * Register or refresh a form found while rendering the block. Rendering is
	 * the last chance to record a definition before a visitor submits, so the
	 * render callback calls this too instead of relying on the post save alone.
	 *
	 * @param string $id     Form ID.
	 * @param string $name   Form name.
	 * @param array  $fields Field definitions.
	 * @param int    $source Post the form was rendered from.
	 */
	public static function remember( string $id, string $name, array $fields, int $source ): void {
		$id = sanitize_key( $id );

		if ( '' === $id ) {
			return;
		}

		$forms   = self::all();
		$current = isset( $forms[ $id ] ) && is_array( $forms[ $id ] ) ? $forms[ $id ] : array();
		$sources = isset( $current['sources'] ) && is_array( $current['sources'] ) ? $current['sources'] : array();

		if ( $source > 0 && ! in_array( $source, $sources, true ) ) {
			$sources[] = $source;
		}

		$form = array(
			'id'      => $id,
			'name'    => self::form_name( $name ),
			'fields'  => $fields,
			'sources' => array_values( array_unique( array_map( 'absint', $sources ) ) ),
			'updated' => isset( $current['updated'] ) ? (int) $current['updated'] : 0,
		);

		if ( isset( $forms[ $id ] ) && ! self::differs( $forms[ $id ], $form ) ) {
			return;
		}

		$form['updated'] = time();
		$forms[ $id ]     = $form;

		update_option( self::OPTION, $forms, false );
	}

	/**
	 * Normalise a quote field block into a field definition.
	 *
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	public static function field( array $attributes ): array {
		$types  = FieldHelpers::types();
		$key    = isset( $attributes['key'] ) ? sanitize_key( (string) $attributes['key'] ) : '';
		// Blocks saved before fields became generic never stored a type.
		$type        = isset( $attributes['type'] ) ? (string) $attributes['type'] : '';
		$is_legacy   = '' === $type;
		$legacy      = $is_legacy ? self::legacy_field( $key ) : self::legacy_field( '' );

		if ( ! isset( $types[ $type ] ) ) {
			$type = $legacy['type'];
		}

		if ( '' === $key ) {
			$key = sanitize_title( isset( $attributes['label'] ) ? (string) $attributes['label'] : '' );
		}

		if ( '' === $key ) {
			$key = $type;
		}

		$label = isset( $attributes['label'] ) ? trim( (string) $attributes['label'] ) : '';

		if ( '' === $label ) {
			$label = '' !== $legacy['label'] ? $legacy['label'] : $types[ $type ]['label'];
		}

		$options = array();

		if ( 'select' === $type ) {
			$options = isset( $attributes['options'] ) ? FieldHelpers::options( $attributes['options'] ) : array();

			if ( empty( $options ) ) {
				$options = $legacy['options'];
			}
		}

		return array(
			'key'         => $key,
			'label'       => sanitize_text_field( $label ),
			'type'        => $type,
			'required'    => isset( $attributes['required'] ) ? ! empty( $attributes['required'] ) : ! empty( $legacy['required'] ),
			'placeholder' => isset( $attributes['placeholder'] ) ? sanitize_text_field( (string) $attributes['placeholder'] ) : '',
			'default'     => FieldHelpers::sanitize_default( $type, isset( $attributes['default'] ) ? $attributes['default'] : '', $options ),
			'options'     => $options,
			'full'        => isset( $attributes['full'] ) ? ! empty( $attributes['full'] ) : ! empty( $legacy['full'] ),
			'preview'     => isset( $attributes['preview'] ) ? ! empty( $attributes['preview'] ) : ! empty( $legacy['preview'] ),
		);
	}

	/**
	 * The field definition the old fixed quote fields used. Only consulted when
	 * a block has no explicit type, so existing forms keep working unchanged.
	 *
	 * @param string $key Field key.
	 * @return array
	 */
	private static function legacy_field( string $key ): array {
		$default = array(
			'type'     => 'text',
			'label'    => '',
			'options'  => array(),
			'required' => false,
			'full'     => false,
			'preview'  => false,
		);

		$fields = array(
			'name'    => array(
				'type'     => 'text',
				'label'    => __( 'Name', 'open-forms' ),
				'required' => true,
			),
			'email'   => array(
				'type'     => 'email',
				'label'    => __( 'Email', 'open-forms' ),
				'required' => true,
				'preview'  => true,
			),
			'phone'   => array(
				'type'    => 'tel',
				'label'   => __( 'Phone', 'open-forms' ),
				'preview' => true,
			),
			'budget'  => array(
				'type'    => 'select',
				'label'   => __( 'Estimated Budget', 'open-forms' ),
				'preview' => true,
				'options' => array(
					__( 'Less than $1,000', 'open-forms' ),
					__( '$1,000 - $5,000', 'open-forms' ),
					__( '$5,000 - $10,000', 'open-forms' ),
					__( '$10,000+', 'open-forms' ),
				),
			),
			'message' => array(
				'type'     => 'textarea',
				'label'    => __( 'Project Details', 'open-forms' ),
				'required' => true,
				'full'     => true,
			),
			'file'    => array(
				'type'  => 'file',
				'label' => __( 'File Upload', 'open-forms' ),
				'full'  => true,
			),
		);

		return isset( $fields[ $key ] ) ? wp_parse_args( $fields[ $key ], $default ) : $default;
	}

	/**
	 * Field definitions for a list of rendered inner blocks, keys made unique.
	 *
	 * @param iterable $blocks Inner block instances.
	 * @return array
	 */
	public static function fields_from_blocks( iterable $blocks ): array {
		$fields = array();
		$seen   = array();

		self::walk( $blocks, $fields );

		foreach ( $fields as $index => $field ) {
			$key  = $field['key'];
			$base = $key;
			$n    = 2;

			while ( isset( $seen[ $key ] ) ) {
				$key = $base . '-' . $n;
				$n++;
			}

			$seen[ $key ]            = true;
			$fields[ $index ]['key'] = $key;
		}

		return $fields;
	}

	/**
	 * Collect the quote field blocks from a parsed block tree.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $fields Collected fields.
	 */
	private static function walk( iterable $blocks, array &$fields ): void {
		foreach ( $blocks as $block ) {
			$is_instance = $block instanceof \WP_Block;
			$name        = $is_instance ? $block->name : ( isset( $block['blockName'] ) ? $block['blockName'] : '' );
			// Raw attributes: the prepared ones carry defaults, which would hide
			// the fact that an older field never stored a type.
			$attrs = $is_instance
				? ( isset( $block->parsed_block['attrs'] ) && is_array( $block->parsed_block['attrs'] ) ? $block->parsed_block['attrs'] : array() )
				: ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array() );
			$inner = $is_instance ? $block->inner_blocks : ( isset( $block['innerBlocks'] ) ? $block['innerBlocks'] : array() );

			if ( 'openforms/quote-field' === $name ) {
				$fields[] = self::field( $attrs );
			}

			if ( ! empty( $inner ) ) {
				self::walk( $inner, $fields );
			}
		}
	}

	/**
	 * The quote forms defined in a piece of post content.
	 *
	 * @param string $content Post content.
	 * @param int    $source  Post ID, used as the fallback form ID.
	 * @return array Forms keyed by form ID.
	 */
	public static function forms_in_content( string $content, int $source ): array {
		$forms = array();

		foreach ( parse_blocks( $content ) as $block ) {
			$found = self::forms_in_blocks( array( $block ), $source );

			foreach ( $found as $id => $form ) {
				$forms[ $id ] = $form;
			}
		}

		return $forms;
	}

	/**
	 * Recursively find quote forms in a parsed block tree.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $source Post ID.
	 * @return array
	 */
	private static function forms_in_blocks( array $blocks, int $source ): array {
		$forms = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			if ( 'openforms/get-quote' === $block['blockName'] ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$id    = isset( $attrs['formId'] ) ? sanitize_key( (string) $attrs['formId'] ) : '';

				if ( '' === $id ) {
					$id = 'form-' . $source;
				}

				$fields = array();

				self::walk( isset( $block['innerBlocks'] ) ? $block['innerBlocks'] : array(), $fields );

				$forms[ $id ] = array(
					'name'   => isset( $attrs['formName'] ) ? sanitize_text_field( (string) $attrs['formName'] ) : '',
					'fields' => $fields,
				);

				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( self::forms_in_blocks( $block['innerBlocks'], $source ) as $id => $form ) {
					$forms[ $id ] = $form;
				}
			}
		}

		return $forms;
	}

	/**
	 * Mirror the forms of a saved post and drop the ones it no longer contains.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public function sync_post( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::sync_content( $post_id, (string) $post->post_content );
	}

	/**
	 * Re-read a post and refresh every form it defines. Used when a submission
	 * arrives for a form that is not registered yet, so a cached page rendered
	 * before the plugin was updated can still be submitted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function refresh_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( $post ) {
			self::sync_content( $post_id, (string) $post->post_content );
		}
	}

	/**
	 * Re-sync a post after it is restored from the trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public function sync_saved_post( int $post_id ): void {
		self::refresh_post( $post_id );
	}

	/**
	 * Register the forms in a post and unlink the forms that are gone.
	 *
	 * @param int    $source  Post ID.
	 * @param string $content Post content.
	 */
	private static function sync_content( int $source, string $content ): void {
		$forms   = self::all();
		$found   = self::forms_in_content( $content, $source );
		$changed = false;

		foreach ( $found as $id => $form ) {
			$current = isset( $forms[ $id ] ) && is_array( $forms[ $id ] ) ? $forms[ $id ] : null;
			$sources = $current && isset( $current['sources'] ) ? (array) $current['sources'] : array();

			if ( ! in_array( $source, $sources, true ) ) {
				$sources[] = $source;
			}

			$candidate = array(
				'id'      => $id,
				'name'    => self::form_name( $form['name'] ),
				'fields'  => $form['fields'],
				'sources' => array_values( array_unique( array_map( 'absint', $sources ) ) ),
				'updated' => $current && isset( $current['updated'] ) ? (int) $current['updated'] : 0,
			);

			if ( $current && ! self::differs( $current, $candidate ) ) {
				continue;
			}

			$candidate['updated'] = time();
			$forms[ $id ]         = $candidate;
			$changed              = true;
		}

		foreach ( $forms as $id => $form ) {
			if ( isset( $found[ $id ] ) ) {
				continue;
			}

			$sources = isset( $form['sources'] ) ? (array) $form['sources'] : array();
			$left    = array_values( array_diff( array_map( 'absint', $sources ), array( $source ) ) );

			if ( count( $left ) === count( $sources ) ) {
				continue;
			}

			if ( empty( $left ) ) {
				unset( $forms[ $id ] );
			} else {
				$forms[ $id ]['sources'] = $left;
				$forms[ $id ]['updated'] = time();
			}

			$changed = true;
		}

		if ( $changed ) {
			update_option( self::OPTION, $forms, false );
		}
	}

	/**
	 * Remove a post from every form that references it. Forms left without a
	 * source are deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function remove_source( int $post_id ): void {
		$forms   = self::all();
		$changed = false;

		foreach ( $forms as $id => $form ) {
			$sources = isset( $form['sources'] ) ? (array) $form['sources'] : array();
			$left    = array_values( array_diff( array_map( 'absint', $sources ), array( $post_id ) ) );

			if ( count( $left ) === count( $sources ) ) {
				continue;
			}

			if ( empty( $left ) ) {
				unset( $forms[ $id ] );
			} else {
				$forms[ $id ]['sources'] = $left;
				$forms[ $id ]['updated'] = time();
			}

			$changed = true;
		}

		if ( $changed ) {
			update_option( self::OPTION, $forms, false );
		}
	}

	/**
	 * A form name that is always safe to display.
	 *
	 * @param string $name Submitted name.
	 * @return string
	 */
	private static function form_name( string $name ): string {
		$name = sanitize_text_field( $name );

		return '' !== $name ? $name : __( 'Quote form', 'open-forms' );
	}

	/**
	 * Whether two stored definitions differ in anything but their timestamp.
	 *
	 * @param array $stored Stored form.
	 * @param array $form   Candidate form.
	 * @return bool
	 */
	private static function differs( array $stored, array $form ): bool {
		$keys = array( 'name', 'fields', 'sources' );

		foreach ( $keys as $key ) {
			$a = isset( $stored[ $key ] ) ? $stored[ $key ] : null;
			$b = isset( $form[ $key ] ) ? $form[ $key ] : null;

			if ( wp_json_encode( $a ) !== wp_json_encode( $b ) ) {
				return true;
			}
		}

		return false;
	}
}
