<?php
/**
 * Product admin. Adds the form configuration to products and variations.
 *
 * @package OpenForms
 */

namespace OpenForms;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box (product) and panel (variation) that manage the field list.
 */
class ProductForms {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_panel' ), 10, 3 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the product meta box.
	 */
	public function add_meta_box() {
		add_meta_box(
			'openforms-product-fields',
			__( 'OpenForms', 'openforms' ),
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * Render the product meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'openforms_save_fields', 'openforms_nonce' );

		$enabled = 'yes' === get_post_meta( $post->ID, '_openforms_enabled', true );
		$fields  = Fields::for_post( $post->ID );

		echo '<p class="openforms-intro">';
		echo esc_html__( 'Forms configured here appear on the product page above the add to cart button. On a variable product these apply to every variation.', 'openforms' );
		echo '</p>';

		$this->render_config( $post->ID, $enabled, $fields, 'openforms' );
	}

	/**
	 * Render the per-variation panel.
	 *
	 * @param int      $loop           Variation loop index.
	 * @param array    $variation_data Variation data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public function variation_panel( $loop, $variation_data, $variation ) {
		$enabled = 'yes' === get_post_meta( $variation->ID, '_openforms_enabled', true );
		$fields  = Fields::for_post( $variation->ID );

		echo '<div class="openforms-variation-panel">';
		echo '<h4>' . esc_html__( 'OpenForms', 'openforms' ) . '</h4>';

		// Per-variation nonce so the panel config can only be saved by an
		// authorized request. Defense in depth on top of the product save.
		wp_nonce_field( 'openforms_save_variation_' . $variation->ID, 'openforms_variation_nonce_' . $variation->ID );

		echo '<p class="openforms-intro">';
		echo esc_html__( 'Forms configured here only apply to this variation. Fields are shown when the variation is selected.', 'openforms' );
		echo '</p>';

		$this->render_config( $variation->ID, $enabled, $fields, 'openforms_variation[' . $variation->ID . ']' );

		echo '</div>';
	}

	/**
	 * Render the shared field list config.
	 *
	 * @param int    $post_id Post ID being configured.
	 * @param bool   $enabled Whether forms are enabled.
	 * @param array  $fields  Existing fields.
	 * @param string $prefix  Input name prefix.
	 */
	private function render_config( $post_id, $enabled, $fields, $prefix ) {
		echo '<div class="openforms-config">';

		echo '<p class="form-field openforms-enabled">';
		echo '<label for="openforms-enabled-' . esc_attr( $post_id ) . '">';
		echo '<input type="checkbox" id="openforms-enabled-' . esc_attr( $post_id ) . '" name="' . esc_attr( $prefix ) . '[enabled]" value="1"' . checked( $enabled, true, false ) . ' /> ';
		echo esc_html__( 'Enable forms', 'openforms' );
		echo '</label>';
		echo '</p>';

		echo '<table class="widefat striped openforms-fields-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'openforms' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'openforms' ) . '</th>';
		echo '<th class="openforms-required-col">' . esc_html__( 'Required', 'openforms' ) . '</th>';
		echo '<th class="openforms-actions-col"></th>';
		echo '</tr></thead>';
		echo '<tbody class="openforms-fields-body">';

		if ( empty( $fields ) ) {
			echo '<tr class="openforms-empty-row"><td colspan="4">' . esc_html__( 'No fields added yet.', 'openforms' ) . '</td></tr>';
		}

		$row = 0;

		foreach ( $fields as $key => $field ) {
			$this->render_field_row( $prefix, $row, $field );
			$row++;
		}

		// Hidden template rows used by the admin JS. Kept inside the tbody so
		// the <tr> markup stays valid.
		$this->render_field_row( $prefix, 'INDEX', array(), true );
		echo '<tr class="openforms-empty-row openforms-empty-template" hidden><td colspan="4">' . esc_html__( 'No fields added yet.', 'openforms' ) . '</td></tr>';

		echo '</tbody></table>';

		echo '<p><button type="button" class="button openforms-add-field">' . esc_html__( 'Add Field', 'openforms' ) . '</button></p>';

		echo '</div>';
	}

	/**
	 * Render a single config row for a field.
	 *
	 * @param string $prefix   Input name prefix.
	 * @param string $index    Row index (or INDEX placeholder for the template).
	 * @param array  $field    Existing field data.
	 * @param bool   $template Whether this is the hidden JS template row.
	 */
	private function render_field_row( $prefix, $index, $field = array(), $template = false ) {
		$name     = isset( $field['name'] ) ? $field['name'] : '';
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$required = ! empty( $field['required'] );

		$row_class = $template ? 'openforms-field-row openforms-field-template' : 'openforms-field-row';

		echo '<tr class="' . esc_attr( $row_class ) . '"' . ( $template ? ' hidden' : '' ) . '>';
		echo '<td><input type="text" name="' . esc_attr( $prefix ) . '[fields][' . esc_attr( $index ) . '][name]" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'Field name', 'openforms' ) . '" /></td>';
		echo '<td><select name="' . esc_attr( $prefix ) . '[fields][' . esc_attr( $index ) . '][type]">';

		foreach ( Fields::types() as $slug => $config ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $type, $slug, false ) . '>' . esc_html( $config['label'] ) . '</option>';
		}

		echo '</select></td>';
		echo '<td class="openforms-required-col"><input type="checkbox" name="' . esc_attr( $prefix ) . '[fields][' . esc_attr( $index ) . '][required]" value="1"' . checked( $required, true, false ) . ' /></td>';
		echo '<td class="openforms-actions-col"><button type="button" class="button openforms-remove-field">' . esc_html__( 'Remove', 'openforms' ) . '</button></td>';
		echo '</tr>';
	}

	/**
	 * Save the product level config.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_product( $post_id ) {
		if ( ! isset( $_POST['openforms_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['openforms_nonce'] ), 'openforms_save_fields' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$data = isset( $_POST['openforms'] ) ? wp_unslash( $_POST['openforms'] ) : array();
		$this->save_config( $post_id, $data );
	}

	/**
	 * Save the variation level config.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 */
	public function save_variation( $variation_id, $loop ) {
		$nonce_field = 'openforms_variation_nonce_' . $variation_id;

		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $nonce_field ] ), 'openforms_save_variation_' . $variation_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		$data = isset( $_POST['openforms_variation'][ $variation_id ] ) ? wp_unslash( $_POST['openforms_variation'][ $variation_id ] ) : array();
		$this->save_config( $variation_id, $data );
	}

	/**
	 * Persist the enabled flag and cleaned field list as post meta.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Raw submitted config.
	 */
	private function save_config( $post_id, $data ) {
		$enabled = ! empty( $data['enabled'] );
		update_post_meta( $post_id, '_openforms_enabled', $enabled ? 'yes' : 'no' );

		$fields = array();

		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			foreach ( $data['fields'] as $i => $field ) {
				$name = isset( $field['name'] ) ? sanitize_text_field( $field['name'] ) : '';
				$type = isset( $field['type'] ) ? $field['type'] : 'text';

				if ( ! isset( Fields::types()[ $type ] ) ) {
					$type = 'text';
				}

				if ( '' === $name ) {
					continue;
				}

				$fields[] = array(
					'key'      => sanitize_title( $name ) . '-' . ( (int) $i + 1 ),
					'name'     => $name,
					'type'     => $type,
					'required' => ! empty( $field['required'] ) ? 'yes' : '',
				);
			}
		}

		update_post_meta( $post_id, '_openforms_fields', $fields );
	}

	/**
	 * Enqueue admin assets on the product edit screens only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script( 'openforms-admin', OPENFORMS_URL . 'assets/js/admin.js', array( 'jquery' ), OPENFORMS_VERSION, true );
		wp_enqueue_style( 'openforms-admin', OPENFORMS_URL . 'assets/css/admin.css', array(), OPENFORMS_VERSION );
	}
}
