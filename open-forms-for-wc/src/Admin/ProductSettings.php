<?php
/**
 * ProductSettings: product admin. Adds the form configuration to products and variations.
 */

namespace OpenFormsWooCommerce\Admin;

use OpenForms\FieldHelpers;
use OpenFormsWooCommerce\ProductFields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product data tab (product) and panel (variation) that manage the field list.
 */
class ProductSettings {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_data_panel' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_panel' ), 10, 3 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the OpenForms tab in the product data metabox.
	 *
	 * @param array $tabs Existing product data tabs.
	 * @return array
	 */
	public function add_tab( array $tabs ): array {
		$tabs['openforms'] = array(
			'label'    => __( 'OpenForms', 'open-forms-for-wc' ),
			'target'   => 'openforms_product_data',
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * Render the OpenForms product data panel.
	 *
	 * @return void
	 */
	public function product_data_panel(): void {
		global $post;

		echo '<div id="openforms_product_data" class="panel woocommerce_options_panel">';

		// Product-level nonce so the panel config can only be saved by an
		// authorized request.
		wp_nonce_field( 'openforms_save_fields', 'openforms_nonce' );

		$enabled = 'yes' === get_post_meta( $post->ID, '_openforms_enabled', true );
		$fields  = ProductFields::for_post( $post->ID );

		echo '<p class="openforms-intro">';
		echo esc_html__( 'Forms configured here appear on the product page above the add to cart button. On a variable product these apply to every variation.', 'open-forms-for-wc' );
		echo '</p>';

		$this->render_config( $post->ID, $enabled, $fields, 'openforms' );

		echo '</div>';
	}

	/**
	 * Render the per-variation panel.
	 *
	 * @param int      $loop           Variation loop index.
	 * @param array    $variation_data Variation data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public function variation_panel( int $loop, array $variation_data, \WP_Post $variation ): void {
		$enabled = 'yes' === get_post_meta( $variation->ID, '_openforms_enabled', true );
		$fields  = ProductFields::for_post( $variation->ID );

		echo '<div class="openforms-variation-panel">';
		echo '<h4>' . esc_html__( 'OpenForms', 'open-forms-for-wc' ) . '</h4>';

		// Per-variation nonce so the panel config can only be saved by an
		// authorized request. Defense in depth on top of the product save.
		wp_nonce_field( 'openforms_save_variation_' . $variation->ID, 'openforms_variation_nonce_' . $variation->ID );

		echo '<p class="openforms-intro">';
		echo esc_html__( 'Forms configured here only apply to this variation. Fields are shown when the variation is selected.', 'open-forms-for-wc' );
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
	private function render_config( int $post_id, bool $enabled, array $fields, string $prefix ): void {
		echo '<div class="openforms-config">';

		echo '<p class="form-field openforms-enabled">';
		echo '<label for="openforms-enabled-' . esc_attr( $post_id ) . '">' . esc_html__( 'Enable forms', 'open-forms-for-wc' ) . '</label>';
		echo '<input type="checkbox" class="checkbox" id="openforms-enabled-' . esc_attr( $post_id ) . '" name="' . esc_attr( $prefix ) . '[enabled]" value="1"' . checked( $enabled, true, false ) . ' />';
		echo '</p>';

		echo '<table class="widefat striped openforms-fields-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'open-forms-for-wc' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'open-forms-for-wc' ) . '</th>';
		echo '<th class="openforms-default-col">' . esc_html__( 'Default', 'open-forms-for-wc' ) . '</th>';
		echo '<th class="openforms-required-col">' . esc_html__( 'Required', 'open-forms-for-wc' ) . '</th>';
		echo '<th class="openforms-actions-col"></th>';
		echo '</tr></thead>';
		echo '<tbody class="openforms-fields-body">';

		if ( empty( $fields ) ) {
			echo '<tr class="openforms-empty-row"><td colspan="5">' . esc_html__( 'No fields added yet.', 'open-forms-for-wc' ) . '</td></tr>';
		}

		$row = 0;

		foreach ( $fields as $key => $field ) {
			$this->render_field_row( $prefix, $row, $field );
			$row++;
		}

		// Hidden template rows used by the admin JS. Kept inside the tbody so
		// the <tr> markup stays valid.
		$this->render_field_row( $prefix, 'INDEX', array(), true );
		echo '<tr class="openforms-empty-row openforms-empty-template" hidden><td colspan="5">' . esc_html__( 'No fields added yet.', 'open-forms-for-wc' ) . '</td></tr>';

		echo '</tbody></table>';

		echo '<p><button type="button" class="button openforms-add-field">' . esc_html__( 'Add Field', 'open-forms-for-wc' ) . '</button></p>';

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
	private function render_field_row( string $prefix, mixed $index, array $field = array(), bool $template = false ): void {
		$name     = isset( $field['name'] ) ? $field['name'] : '';
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$required = ! empty( $field['required'] );
		$options  = isset( $field['options'] ) ? (array) $field['options'] : array();
		$default  = isset( $field['default'] ) ? $field['default'] : '';

		$row_class = $template ? 'openforms-field-row openforms-field-template' : 'openforms-field-row';

		echo '<tr class="' . esc_attr( $row_class ) . '"' . ( $template ? ' hidden' : '' ) . '>';
		echo '<td><input type="text" name="' . esc_attr( $prefix ) . '[fields][' . esc_attr( $index ) . '][name]" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'Field name', 'open-forms-for-wc' ) . '" />';
		// Options for a dropdown field. Hidden with JS while another type is picked.
		echo '<textarea class="openforms-field-options" name="' . esc_attr( $prefix ) . '[fields][' . esc_attr( $index ) . '][options]" rows="2" placeholder="' . esc_attr__( 'One option per line', 'open-forms-for-wc' ) . '"' . ( 'select' === $type ? '' : ' hidden' ) . '>' . esc_textarea( implode( "\n", $options ) ) . '</textarea>';
		echo '</td>';
		echo '<td><select class="openforms-field-type" name="' . esc_attr( $prefix ) . '[fields][' . esc_attr( $index ) . '][type]">';

		foreach ( FieldHelpers::types() as $slug => $config ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $type, $slug, false ) . '>' . esc_html( $config['label'] ) . '</option>';
		}

		echo '</select></td>';
		echo '<td class="openforms-default-col"><input type="text" name="' . esc_attr( $prefix ) . '[fields][' . esc_attr( $index ) . '][default]" value="' . esc_attr( $default ) . '" placeholder="' . esc_attr__( 'Default value', 'open-forms-for-wc' ) . '" /></td>';
		echo '<td class="openforms-required-col"><input type="checkbox" name="' . esc_attr( $prefix ) . '[fields][' . esc_attr( $index ) . '][required]" value="1"' . checked( $required, true, false ) . ' /></td>';
		echo '<td class="openforms-actions-col"><button type="button" class="button openforms-remove-field">' . esc_html__( 'Remove', 'open-forms-for-wc' ) . '</button></td>';
		echo '</tr>';
	}

	/**
	 * Save the product level config.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_product( int $post_id ): void {
		if ( empty( $_POST['openforms_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['openforms_nonce'] ) ), 'openforms_save_fields' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$data = isset( $_POST['openforms'] ) && is_array( $_POST['openforms'] ) ? wp_unslash( $_POST['openforms'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in save_config().
		$this->save_config( $post_id, $data );
	}

	/**
	 * Save the variation level config.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 */
	public function save_variation( int $variation_id, int $loop ): void {
		$nonce_field = 'openforms_variation_nonce_' . $variation_id;
		$nonce       = isset( $_POST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'openforms_save_variation_' . $variation_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		$data = isset( $_POST['openforms_variation'][ $variation_id ] ) && is_array( $_POST['openforms_variation'][ $variation_id ] ) ? wp_unslash( $_POST['openforms_variation'][ $variation_id ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in save_config().
		$this->save_config( $variation_id, $data );
	}

	/**
	 * Persist the enabled flag and cleaned field list as post meta.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Raw submitted config.
	 */
	private function save_config( int $post_id, array $data ): void {
		$enabled = ! empty( $data['enabled'] );
		update_post_meta( $post_id, '_openforms_enabled', $enabled ? 'yes' : 'no' );

		$fields = array();

		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			foreach ( $data['fields'] as $i => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$name = isset( $field['name'] ) ? sanitize_text_field( $field['name'] ) : '';
				$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';

				if ( ! isset( FieldHelpers::types()[ $type ] ) ) {
					$type = 'text';
				}

				if ( '' === $name ) {
					continue;
				}

				$options = 'select' === $type ? FieldHelpers::options( isset( $field['options'] ) ? $field['options'] : array() ) : array();

				$clean = array(
					'key'      => sanitize_title( $name ) . '-' . ( (int) $i + 1 ),
					'name'     => $name,
					'type'     => $type,
					'required' => ! empty( $field['required'] ) ? 'yes' : '',
					'default'  => FieldHelpers::sanitize_default( $type, isset( $field['default'] ) ? $field['default'] : '', $options ),
				);

				if ( 'select' === $type ) {
					$clean['options'] = $options;
				}

				$fields[] = $clean;
			}
		}

		update_post_meta( $post_id, '_openforms_fields', $fields );
	}

	/**
	 * Enqueue admin assets on the product edit screens only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script( 'openforms-wc-admin', OPENFORMS_WC_URL . 'assets/js/admin.js', array( 'jquery' ), OPENFORMS_WC_VERSION, true );
		wp_enqueue_style( 'openforms-wc-admin', OPENFORMS_WC_URL . 'assets/css/admin.css', array(), OPENFORMS_WC_VERSION );
	}
}
