<?php
/**
 * GetQuote: the "Get a Quote" block. Registered in PHP with a server-side
 * render callback. The heading, subheading and submit button are standard core
 * blocks and the form fields are inner blocks, so the whole form edits like
 * native WordPress blocks. Submissions are handled by QuoteHandler.
 */

namespace OpenForms\Blocks;

use OpenForms\Services\QuoteHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the blocks and renders the quote request form. The heading, text
 * and submit button are standard core blocks and the form fields are inner
 * blocks, so editors can select, add and reorder everything by dragging.
 */
class GetQuote {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
	}

	/**
	 * Register the quote form block and its field inner block.
	 */
	public function register_block(): void {
		register_block_type(
			'openforms/get-quote',
			array(
				'title'         => __( 'Get a Quote', 'openforms' ),
				'description'   => __( 'A quote request form. Submissions are stored in the admin.', 'openforms' ),
				'category'      => 'text',
				'icon'          => 'money',
				'editor_script' => 'openforms-quote-editor',
				'api_version'   => 3,

				'supports' => array(
					'align' => array( 'wide', 'full' ),
					'color' => array(
						'text'       => true,
						'background' => true,
						'gradients'  => true,
						'link'       => true,
					),
					'typography' => array(
						'fontSize'   => true,
						'lineHeight' => true,
					),
					'spacing' => array(
						'margin'  => true,
						'padding' => true,
					),
					'border' => array(
						'color'  => true,
						'width'  => true,
						'style'  => true,
						'radius' => true,
					),
					'__experimentalBorder' => array(
						'color'  => true,
						'width'  => true,
						'style'  => true,
						'radius' => true,
					),
				),

				'render_callback' => array( $this, 'render' ),
			)
		);

		// A single form field. Only allowed inside the quote form block so
		// editors can reorder the fields by dragging them.
		register_block_type(
			'openforms/quote-field',
			array(
				'title'         => __( 'Quote Field', 'openforms' ),
				'parent'        => array( 'openforms/get-quote' ),
				'editor_script' => 'openforms-quote-editor',
				'api_version'   => 3,
				'attributes'    => array(
					'key' => array(
						'type'    => 'string',
						'default' => 'name',
					),
				),
				'supports' => array(
					'color' => array(
						'text'       => true,
						'background' => true,
					),
					'typography' => array(
						'fontSize' => true,
					),
					'border' => array(
						'color'  => true,
						'width'  => true,
						'style'  => true,
						'radius' => true,
						// The border is serialized manually onto the input itself
						// (see field_border), not onto the label wrapper.
						'__experimentalSkipSerialization' => true,
					),
					'__experimentalBorder' => array(
						'color'  => true,
						'width'  => true,
						'style'  => true,
						'radius' => true,
					),
					'spacing' => array(
						'padding' => true,
					),
				),
				'render_callback' => array( $this, 'render_field' ),
			)
		);

		// Frontend stylesheet: loaded only when the block is present. The
		// editor workaround below covers the editor preview.
		wp_enqueue_block_style(
			'openforms/get-quote',
			array(
				'handle' => 'openforms-quote',
				'src'    => OPENFORMS_URL . 'assets/css/quote.css',
				'ver'    => OPENFORMS_VERSION,
			)
		);

		// Editor script referenced by editor_script above. Registered on init
		// so the block editor can enqueue it whenever the block is used.
		wp_register_script( 'openforms-quote-editor', OPENFORMS_URL . 'assets/js/quote-editor.js', array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ), OPENFORMS_VERSION, true );
	}

	/**
	 * Render the quote request form.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Rendered inner block content.
	 * @return string
	 */
	public function render( array $attributes, string $content = '' ): string {
		$wrapper = get_block_wrapper_attributes( array( 'class' => 'openforms-quote' ) );
		$notice  = $this->notice();
		$fields  = $this->submit_button( (string) $content );

		$source = (int) get_the_ID();
		$form   = '<form class="openforms-quote__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$form  .= '<input type="hidden" name="action" value="openforms_quote" />';
		$form  .= $source ? '<input type="hidden" name="openforms_quote[source]" value="' . esc_attr( $source ) . '" />' : '';
		$form  .= wp_nonce_field( 'openforms_quote_submit', 'openforms_quote_nonce', true, false );
		$form  .= '<div class="openforms-quote__fields">' . $fields . '</div>';
		$form  .= '</form>';

		return sprintf( '<div %s>%s%s</div>', $wrapper, $notice, $form );
	}

	/**
	 * Render a single quote field based on its key.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_field( array $attributes ): string {
		$key = isset( $attributes['key'] ) ? $attributes['key'] : '';

		switch ( $key ) {
			case 'name':
				return $this->field( 'name', __( 'Name', 'openforms' ), 'text', true, false, $attributes );
			case 'email':
				return $this->field( 'email', __( 'Email', 'openforms' ), 'email', true, false, $attributes );
			case 'phone':
				return $this->field( 'phone', __( 'Phone', 'openforms' ), 'tel', false, false, $attributes );
			case 'budget':
				return $this->select_field( 'budget', __( 'Estimated Budget', 'openforms' ), QuoteHandler::budget_options(), false, false, $attributes );
			case 'message':
				return $this->field( 'message', __( 'Project Details', 'openforms' ), 'textarea', true, true, $attributes );
		}

		return '';
	}

	/**
	 * The core button block saves an anchor, but the submit control inside the
	 * form has to be a real submit button, so swap the tag and drop the link
	 * attributes. Everything else, including the styling classes, is kept.
	 *
	 * @param string $content Rendered inner block content.
	 * @return string
	 */
	private function submit_button( string $content ): string {
		return preg_replace_callback(
			'/<a\s+([^>]*class="[^"]*wp-block-button__link[^"]*"[^>]*)>([\s\S]*?)<\/a>/',
			static function ( $matches ) {
				$attrs = preg_replace( '/\s(href|rel|target|role)="[^"]*"/', '', $matches[1] );

				return '<button type="submit" ' . $attrs . '>' . $matches[2] . '</button>';
			},
			$content
		);
	}

	/**
	 * Render a single input or textarea field.
	 *
	 * @param string $key      Input key.
	 * @param string $label    Field label.
	 * @param string $input    Input type (text, email, tel or textarea).
	 * @param bool   $required Whether the field is required.
	 * @param bool   $full     Whether the field spans the full row.
	 * @return string
	 */
	private function field( string $key, string $label, string $input, bool $required, bool $full, array $attributes = array() ): string {
		$support = \WP_Block_Supports::get_instance()->apply_block_supports();
		$class   = isset( $support['class'] ) ? ' ' . $support['class'] : '';
		$style   = isset( $support['style'] ) ? ' style="' . esc_attr( $support['style'] ) . '"' : '';

		// The border support is serialized manually onto the input itself (not
		// the label wrapper) so a border colour/size follows the field.
		$border      = $this->field_border( $attributes );
		$input_class = $border['class'] ? ' ' . $border['class'] : '';
		$input_style = $border['style'] ? ' style="' . esc_attr( $border['style'] ) . '"' : '';

		$id   = 'openforms-quote-' . $key;
		$mark = $required ? ' <span class="required">*</span>' : '';

		$out  = '<p class="openforms-quote__field' . ( $full ? ' openforms-quote__field--full' : '' ) . $class . '"' . $style . '>';
		$out .= '<label class="openforms-quote__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $mark . '</label>';

		if ( 'textarea' === $input ) {
			$out .= '<textarea class="openforms-quote__textarea' . $input_class . '"' . $input_style . ' id="' . esc_attr( $id ) . '" name="openforms_quote[' . esc_attr( $key ) . ']" rows="4"' . ( $required ? ' required' : '' ) . '></textarea>';
		} else {
			$out .= '<input class="openforms-quote__input' . $input_class . '"' . $input_style . ' type="' . esc_attr( $input ) . '" id="' . esc_attr( $id ) . '" name="openforms_quote[' . esc_attr( $key ) . ']"' . ( $required ? ' required' : '' ) . ' />';
		}

		$out .= '</p>';

		return $out;
	}

	/**
	 * Render a select field.
	 *
	 * @param string $key      Input key.
	 * @param string $label    Field label.
	 * @param array  $options  Option labels.
	 * @param bool   $required Whether the field is required.
	 * @param bool   $full     Whether the field spans the full row.
	 * @return string
	 */
	private function select_field( string $key, string $label, array $options, bool $required, bool $full, array $attributes = array() ): string {
		$support = \WP_Block_Supports::get_instance()->apply_block_supports();
		$class   = isset( $support['class'] ) ? ' ' . $support['class'] : '';
		$style   = isset( $support['style'] ) ? ' style="' . esc_attr( $support['style'] ) . '"' : '';

		// Same as field(): the border lands on the select itself, not the
		// label wrapper.
		$border      = $this->field_border( $attributes );
		$input_class = $border['class'] ? ' ' . $border['class'] : '';
		$input_style = $border['style'] ? ' style="' . esc_attr( $border['style'] ) . '"' : '';

		$id   = 'openforms-quote-' . $key;
		$mark = $required ? ' <span class="required">*</span>' : '';

		$out  = '<p class="openforms-quote__field' . ( $full ? ' openforms-quote__field--full' : '' ) . $class . '"' . $style . '>';
		$out .= '<label class="openforms-quote__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $mark . '</label>';
		$out .= '<select class="openforms-quote__select' . $input_class . '"' . $input_style . ' id="' . esc_attr( $id ) . '" name="openforms_quote[' . esc_attr( $key ) . ']">';
		$out .= '<option value="">' . esc_html__( 'Select an option…', 'openforms' ) . '</option>';

		foreach ( $options as $option ) {
			$out .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
		}

		$out .= '</select></p>';

		return $out;
	}

	/**
	 * Border classes and inline styles for a single field input, mirroring the
	 * block editor's border support. The border support serialization is
	 * skipped on the field wrapper (see the block registration) so the border
	 * lands on the input itself instead of the label wrapper.
	 *
	 * @param array $attributes Block attributes.
	 * @return array Array with 'class' and 'style' keys.
	 */
	private function field_border( array $attributes ): array {
		$border = isset( $attributes['style']['border'] ) ? $attributes['style']['border'] : array();

		if ( empty( $border ) && empty( $attributes['borderColor'] ) ) {
			return array( 'class' => '', 'style' => '' );
		}

		$border_styles = array();

		if ( isset( $border['radius'] ) && '' !== $border['radius'] ) {
			$border_styles['radius'] = is_numeric( $border['radius'] ) ? $border['radius'] . 'px' : $border['radius'];
		}

		if ( isset( $border['style'] ) && '' !== $border['style'] ) {
			$border_styles['style'] = $border['style'];
		}

		if ( isset( $border['width'] ) && '' !== $border['width'] ) {
			$border_styles['width'] = is_numeric( $border['width'] ) ? $border['width'] . 'px' : $border['width'];
		}

		// A palette colour is stored in the borderColor attribute and a custom
		// colour in style.border.color, exactly like core's border support.
		$preset_border_color = ! empty( $attributes['borderColor'] ) ? 'var:preset|color|' . $attributes['borderColor'] : null;
		$custom_border_color = isset( $border['color'] ) ? $border['color'] : null;

		if ( $preset_border_color || $custom_border_color ) {
			$border_styles['color'] = $preset_border_color ? $preset_border_color : $custom_border_color;
		}

		// Per-side border values.
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $border[ $side ] ) && is_array( $border[ $side ] ) ) {
				$side_values = array();

				foreach ( array( 'width', 'color', 'style' ) as $prop ) {
					if ( isset( $border[ $side ][ $prop ] ) && '' !== $border[ $side ][ $prop ] ) {
						$value = $border[ $side ][ $prop ];

						if ( 'width' === $prop && is_numeric( $value ) ) {
							$value .= 'px';
						}

						$side_values[ $prop ] = $value;
					}
				}

				if ( $side_values ) {
					$border_styles[ $side ] = $side_values;
				}
			}
		}

		$styles = wp_style_engine_get_styles( array( 'border' => $border_styles ) );

		return array(
			'class' => isset( $styles['classnames'] ) ? $styles['classnames'] : '',
			'style' => isset( $styles['css'] ) ? $styles['css'] : '',
		);
	}

	/**
	 * The success or error banner shown after a form submission redirect.
	 *
	 * @return string
	 */
	private function notice(): string {
		$type = $this->notice_type();

		if ( 'success' === $type ) {
			return '<p class="openforms-quote__notice openforms-quote__notice--success">' . esc_html__( 'Thanks! Your quote request has been sent. We will be in touch shortly.', 'openforms' ) . '</p>';
		}

		if ( 'error' === $type ) {
			return '<p class="openforms-quote__notice openforms-quote__notice--error">' . esc_html__( 'Sorry, your quote request could not be sent. Please try again.', 'openforms' ) . '</p>';
		}

		return '';
	}

	/**
	 * The validated notice type from the query string, or empty.
	 *
	 * @return string
	 */
	private function notice_type(): string {
		if ( ! isset( $_GET['openforms_quote'] ) ) {
			return '';
		}

		$type = sanitize_key( wp_unslash( $_GET['openforms_quote'] ) );

		return in_array( $type, array( 'success', 'error' ), true ) ? $type : '';
	}

	/**
	 * Load the block stylesheet and editor script.
	 */
	public function editor_assets(): void {
		wp_enqueue_style( 'openforms-quote', OPENFORMS_URL . 'assets/css/quote.css', array(), OPENFORMS_VERSION );
		wp_enqueue_script( 'openforms-quote-editor' );
	}
}
