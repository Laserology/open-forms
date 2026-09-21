<?php
/**
 * QuoteHandler: quote submissions. Stores get a quote submissions as a private
 * post type and provides the admin viewer (list columns and a detail meta box).
 */

namespace OpenForms\Services;

use OpenForms\DataHelpers;
use OpenForms\FieldHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the quote form submission and the admin viewer for stored quotes.
 */
class QuoteHandler {

	/**
	 * Post meta holding the details of a quote's uploaded attachment.
	 *
	 * @var string
	 */
	const FILE_META = '_openforms_quote_file';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'admin_post_openforms_quote', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_openforms_quote', array( $this, 'handle_submit' ) );
		add_filter( 'manage_openforms_quote_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_openforms_quote_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
	}

	/**
	 * The budget options shared by the block select and the validation here.
	 *
	 * @return array
	 */
	public static function budget_options(): array {
		return array(
			__( 'Less than $1,000', 'openforms' ),
			__( '$1,000 - $5,000', 'openforms' ),
			__( '$5,000 - $10,000', 'openforms' ),
			__( '$10,000+', 'openforms' ),
		);
	}

	/**
	 * The file types accepted as a quote attachment, as an extension => mime map
	 * for wp_check_filetype_and_ext(). Kept deliberately narrow: a quote only
	 * needs a document or an image, and a form that anyone can submit should not
	 * accept everything the site happens to allow.
	 *
	 * @return array
	 */
	public static function attachment_mimes(): array {
		/**
		 * Filters the file types accepted as a quote attachment.
		 *
		 * @param array $mimes Extension => mime map.
		 */
		return apply_filters(
			'openforms_quote_attachment_mimes',
			array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'gif'          => 'image/gif',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
				'pdf'          => 'application/pdf',
				'doc|docx'     => 'application/msword',
				'xls|xlsx'     => 'application/vnd.ms-excel',
				'zip'          => 'application/zip',
			)
		);
	}

	/**
	 * How long a requester must wait between quote attachments, in seconds. A
	 * value of 0 disables the limit.
	 *
	 * @return int
	 */
	public static function upload_interval(): int {
		/**
		 * Filters the minimum number of seconds between anonymous quote uploads
		 * from the same IP.
		 *
		 * @param int $seconds Seconds between uploads. 0 disables the limit.
		 */
		return (int) apply_filters( 'openforms_quote_upload_interval', 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * The accept attribute value for the attachment picker, built from the allowed
	 * types so the picker and the server checks never drift apart.
	 *
	 * @return string
	 */
	public static function attachment_accept(): string {
		$accept = array();

		foreach ( array_keys( self::attachment_mimes() ) as $extensions ) {
			foreach ( explode( '|', $extensions ) as $extension ) {
				$accept[] = '.' . $extension;
			}
		}

		return implode( ',', $accept );
	}

	/**
	 * Register the private quotes post type. It is only shown in the admin; the
	 * Add New action is disabled because quotes are created by the form.
	 */
	public function register_post_type(): void {
		register_post_type(
			'openforms_quote',
			array(
				'labels' => array(
					'name'          => __( 'Quotes', 'openforms' ),
					'singular_name' => __( 'Quote', 'openforms' ),
					'menu_name'     => __( 'Quotes', 'openforms' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => false,
				'menu_icon'          => 'dashicons-money-alt',
				'supports'           => array( 'title' ),
				'capabilities'       => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap'       => true,
			)
		);
	}

	/**
	 * Validate and store a submitted quote, then redirect back with a notice.
	 */
	public function handle_submit(): void {
		$referer = wp_get_referer() ? wp_get_referer() : home_url( '/' );

		$fail = function ( string $type = 'error' ) use ( $referer ) {
			wp_safe_redirect( add_query_arg( 'openforms_quote', $type, $referer ) );
			exit;
		};

		if ( empty( $_POST['openforms_quote_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['openforms_quote_nonce'] ), 'openforms_quote_submit' ) ) {
			$fail();
		}

		$data = isset( $_POST['openforms_quote'] ) && is_array( $_POST['openforms_quote'] ) ? wp_unslash( $_POST['openforms_quote'] ) : array();

		$name    = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$email   = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
		$phone   = isset( $data['phone'] ) ? sanitize_text_field( (string) $data['phone'] ) : '';
		$budget  = isset( $data['budget'] ) ? sanitize_text_field( (string) $data['budget'] ) : '';
		$message = isset( $data['message'] ) ? sanitize_textarea_field( (string) $data['message'] ) : '';
		$source  = isset( $data['source'] ) ? absint( $data['source'] ) : 0;

		if ( '' === $name ) {
			$fail();
		}

		if ( '' !== $email && ! is_email( $email ) ) {
			$fail();
		}

		if ( ! in_array( $budget, self::budget_options(), true ) ) {
			$budget = '';
		}

		// The form is multipart, so an attachment can arrive alongside the text
		// fields. It is stored in the private folder before the quote is created;
		// a rejected upload aborts the submission.
		$file = null;

		if ( ! empty( $_FILES['openforms_quote']['name']['file'] ) ) {
			// A form anyone can submit must not be usable to fill the disk, so a
			// single IP can only attach a file once per interval.
			if ( $this->upload_throttled() ) {
				$fail( 'throttle' );
			}

			$file = FieldHelpers::upload_file( 'openforms_quote', 'file', self::attachment_mimes() );

			if ( ! $file ) {
				$fail( 'file' );
			}

			$this->throttle_upload();
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'openforms_quote',
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			// Do not leave the stored upload behind when the quote was not created.
			if ( $file && ! empty( $file['file_path'] ) ) {
				unlink( $file['file_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}

			$fail();
		}

		update_post_meta(
			$post_id,
			'_openforms_quote_data',
			array(
				'email'   => $email,
				'phone'   => $phone,
				'budget'  => $budget,
				'message' => $message,
			)
		);

		if ( $file ) {
			update_post_meta( $post_id, self::FILE_META, $file );
		}

		if ( $source ) {
			update_post_meta( $post_id, '_openforms_quote_source', $source );
		}

		wp_safe_redirect( add_query_arg( 'openforms_quote', 'success', $referer ) );
		exit;
	}

	/**
	 * Whether the requester has attached a quote file too recently.
	 *
	 * @return bool
	 */
	private function upload_throttled(): bool {
		$key = $this->throttle_key();

		return '' !== $key && (bool) get_transient( $key );
	}

	/**
	 * Start the requester's cooldown once a file has been stored.
	 */
	private function throttle_upload(): void {
		$key = $this->throttle_key();

		if ( '' !== $key ) {
			set_transient( $key, 1, self::upload_interval() );
		}
	}

	/**
	 * The transient key a requester's cooldown is stored under, or an empty string
	 * when the limit does not apply to them. It applies to anonymous submissions
	 * only: it is skipped when the interval is disabled, when the IP is unknown,
	 * and for logged in users who can already publish content (which also keeps
	 * the form usable while testing). The IP is hashed, so no address is written
	 * to the database.
	 *
	 * @return string
	 */
	private function throttle_key(): string {
		if ( self::upload_interval() <= 0 || current_user_can( 'edit_posts' ) ) {
			return '';
		}

		// WooCommerce resolves the client IP behind proxies and CDNs; REMOTE_ADDR
		// is the fallback when it cannot.
		$ip = class_exists( 'WC_Geolocation' ) ? (string) \WC_Geolocation::get_ip_address() : '';

		if ( '' === $ip && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] );
			$ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
		}

		return '' === $ip ? '' : 'openforms_quote_upload_' . md5( $ip );
	}

	/**
	 * Insert the extra columns right after the title column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['openforms_email']  = __( 'Email', 'openforms' );
				$new['openforms_budget'] = __( 'Budget', 'openforms' );
				$new['openforms_source'] = __( 'Source', 'openforms' );
			}
		}

		return $new;
	}

	/**
	 * Render the added list columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Quote post ID.
	 */
	public function column_content( string $column, int $post_id ): void {
		$data = $this->data( $post_id );

		if ( 'openforms_email' === $column ) {
			echo esc_html( $data['email'] );
		}

		if ( 'openforms_budget' === $column ) {
			echo esc_html( $data['budget'] );
		}

		if ( 'openforms_source' === $column ) {
			$source = (int) get_post_meta( $post_id, '_openforms_quote_source', true );

			if ( $source && get_post( $source ) ) {
				echo '<a href="' . esc_url( get_edit_post_link( $source ) ) . '">' . esc_html( get_the_title( $source ) ) . '</a>';
			} else {
				echo '&mdash;';
			}
		}
	}

	/**
	 * Register the detail meta box on the quote edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'openforms-quote-details',
			__( 'Quote Details', 'openforms' ),
			array( $this, 'render_meta_box' ),
			'openforms_quote',
			'normal',
			'high'
		);
	}

	/**
	 * Render the submitted quote details on the edit screen.
	 *
	 * @param \WP_Post $post Quote post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$data   = $this->data( $post->ID );
		$source = (int) get_post_meta( $post->ID, '_openforms_quote_source', true );
		$file   = get_post_meta( $post->ID, self::FILE_META, true );

		$rows = array(
			'name'    => array( __( 'Name', 'openforms' ), get_the_title( $post ) ),
			'email'   => array( __( 'Email', 'openforms' ), $data['email'] ),
			'phone'   => array( __( 'Phone', 'openforms' ), $data['phone'] ),
			'budget'  => array( __( 'Budget', 'openforms' ), $data['budget'] ),
			'message' => array( __( 'Message', 'openforms' ), $data['message'] ),
		);

		echo '<table class="widefat striped">';
		echo '<tbody>';

		foreach ( $rows as $key => $row ) {
			list( $label, $value ) = $row;
			$value                = 'message' === $key ? nl2br( esc_html( $value ) ) : esc_html( $value );
			$value                = '' !== trim( $value ) ? $value : '&mdash;';

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
		}

		if ( is_array( $file ) && ! empty( $file['file_key'] ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Attachment', 'openforms' ) . '</th><td>' . DataHelpers::render_file( $file, true, true ) . '</td></tr>';
		}

		if ( $source && get_post( $source ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Submitted from', 'openforms' ) . '</th><td><a href="' . esc_url( get_permalink( $source ) ) . '">' . esc_html( get_the_title( $source ) ) . '</a></td></tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * The stored quote data with every key guaranteed to exist.
	 *
	 * @param int $post_id Quote post ID.
	 * @return array
	 */
	private function data( int $post_id ): array {
		$data = get_post_meta( $post_id, '_openforms_quote_data', true );

		return wp_parse_args(
			is_array( $data ) ? $data : array(),
			array(
				'email'   => '',
				'phone'   => '',
				'budget'  => '',
				'message' => '',
			)
		);
	}
}