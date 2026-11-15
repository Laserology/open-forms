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
	 * Post meta holding the details of a quote's uploaded attachments.
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
			__( 'Less than $1,000', 'open-forms' ),
			__( '$1,000 - $5,000', 'open-forms' ),
			__( '$5,000 - $10,000', 'open-forms' ),
			__( '$10,000+', 'open-forms' ),
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
	 * How long a requester must wait between quotes, in seconds. A value of 0
	 * disables the limit.
	 *
	 * The cooldown covers every submission, not just ones with an attachment, so
	 * an unauthenticated form cannot be used to fill the disk or flood the store
	 * owner with notification mail.
	 *
	 * @return int Minimum number of seconds between quotes.
	 */
	public static function submission_interval(): int {
		$seconds = 10 * MINUTE_IN_SECONDS;

		/**
		 * Filters the minimum number of seconds between quote submissions from
		 * the same IP.
		 *
		 * @deprecated Use openforms_quote_submission_interval instead, which
		 *             covers attachments and text-only submissions alike.
		 * @param int $seconds Seconds between submissions. 0 disables the limit.
		 */
		$seconds = (int) apply_filters( 'openforms_quote_upload_interval', $seconds );

		/**
		 * Filters the minimum number of seconds between quote submissions from
		 * the same IP.
		 *
		 * @param int $seconds Seconds between submissions. 0 disables the limit.
		 */
		return (int) apply_filters( 'openforms_quote_submission_interval', $seconds );
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
	 * The combined size of the attachments mailed with a quote notification, in
	 * bytes. A set past this budget is named in the email but not attached: mail
	 * servers routinely refuse messages past a few tens of megabytes.
	 *
	 * @return int Maximum combined attachment size in bytes.
	 */
	public static function notification_max_attachment_size(): int {
		/**
		 * Filters the combined size of the files attached to a quote notification.
		 *
		 * @param int $bytes Maximum combined size in bytes.
		 */
		return (int) apply_filters( 'openforms_quote_notification_max_attachment_size', 10 * MB_IN_BYTES );
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
					'name'          => __( 'Quotes', 'open-forms' ),
					'singular_name' => __( 'Quote', 'open-forms' ),
					'menu_name'     => __( 'Quotes', 'open-forms' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => false,
				'menu_icon'          => 'dashicons-money-alt',
				'supports'           => array( 'title' ),
				// Shop order caps: quotes hold customer details.
				'capability_type'    => array( 'shop_order', 'shop_orders' ),
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

		if ( empty( $_POST['openforms_quote_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['openforms_quote_nonce'] ) ), 'openforms_quote_submit' ) ) {
			$fail();
		}

		$data = isset( $_POST['openforms_quote'] ) && is_array( $_POST['openforms_quote'] ) ? wp_unslash( $_POST['openforms_quote'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually below.

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

		// The form is open to anyone, so every submission is rate limited: an
		// attachment fills disk space, and each stored quote sends the store
		// owner an email. The cooldown is claimed further down, once the quote is
		// safely stored, so a rejected submission costs the visitor nothing.
		if ( $this->throttled() ) {
			$fail( 'throttle' );
		}

		// The form is multipart, so attachments can arrive alongside the text
		// fields. They are stored before the quote is created; a rejected upload
		// aborts the submission.
		$files = array();

		if ( FieldHelpers::has_files( 'openforms_quote', 'file' ) ) {
			$files = FieldHelpers::upload_files( 'openforms_quote', 'file', self::attachment_mimes() );

			if ( ! $files ) {
				$fail( 'file' );
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'openforms_quote',
				'post_status' => 'publish',
				'post_title'  => $name,
				// Unowned, so submitters cannot edit their own quote.
				'post_author' => 0,
			)
		);

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			// Do not leave the stored uploads behind when the quote was not created.
			foreach ( $files as $stored ) {
				if ( ! empty( $stored['file_path'] ) ) {
					unlink( $stored['file_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
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

		if ( $files ) {
			update_post_meta( $post_id, self::FILE_META, $files );
		}

		if ( $source ) {
			update_post_meta( $post_id, '_openforms_quote_source', $source );
		}

		// Claim the cooldown only now that the quote is stored, so a submission
		// that fails validation or upload does not lock the visitor out.
		$this->throttle();

		$this->notify( $post_id, $files );

		wp_safe_redirect( add_query_arg( 'openforms_quote', 'success', $referer ) );
		exit;
	}

	/**
	 * Email the site owner about a stored quote. A delivery failure is only
	 * logged: the quote is already saved, and the visitor must not be shown an
	 * error that the admin mail caused.
	 *
	 * @param int   $post_id Quote post ID.
	 * @param array $files   Stored attachment details.
	 */
	private function notify( int $post_id, array $files ): void {
		$data = $this->data( $post_id );

		/**
		 * Filters the recipient of the new quote notification. Returning an empty
		 * value turns the notification off.
		 *
		 * @param string $to      Recipient address.
		 * @param int    $post_id Quote post ID.
		 */
		$to = (string) apply_filters( 'openforms_quote_notification_recipient', get_option( 'admin_email' ), $post_id );

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$names      = array();
		$total_size = 0;

		foreach ( $files as $file ) {
			if ( empty( $file['file_name'] ) ) {
				continue;
			}

			$size        = (int) $file['file_size'];
			$total_size += $size;
			$names[]     = $file['file_name'] . ' (' . size_format( $size ) . ')';
		}

		// A set past the mail budget is named in the email but not attached, so
		// the admin knows it exists and where to find it.
		$too_large = $total_size > self::notification_max_attachment_size()
			? sprintf(
				/* translators: %s: formatted total file size. */
				__( 'Too large to attach (%s) — download from the quote in the admin.', 'open-forms' ),
				size_format( $total_size )
			)
			: '';

		$rows = array_filter(
			array(
				__( 'Name', 'open-forms' )        => get_the_title( $post_id ),
				__( 'Email', 'open-forms' )       => $data['email'],
				__( 'Phone', 'open-forms' )       => $data['phone'],
				__( 'Budget', 'open-forms' )      => $data['budget'],
				__( 'Message', 'open-forms' )     => $data['message'],
				__( 'Attachments', 'open-forms' ) => implode( "\n", $names ),
			),
			static function ( $value ) {
				return '' !== trim( $value );
			}
		);

		$subject = sprintf(
			/* translators: 1: site name, 2: name of the person asking for a quote. */
			__( '[%1$s] New quote request from %2$s', 'open-forms' ),
			get_bloginfo( 'name' ),
			get_the_title( $post_id )
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Replying goes straight back to whoever asked for the quote.
		if ( '' !== $data['email'] ) {
			$headers[] = 'Reply-To: ' . $data['email'];
		}

		/* translators: %s: site name. */
		$body  = '<p>' . esc_html( sprintf( __( 'A new quote request was submitted on %s.', 'open-forms' ), get_bloginfo( 'name' ) ) ) . '</p>';
		$body .= '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;">';

		foreach ( $rows as $label => $value ) {
			$body .= '<tr><th align="left" valign="top" style="border-bottom:1px solid #eeeeee;">' . esc_html( $label ) . '</th>';
			$body .= '<td style="border-bottom:1px solid #eeeeee;">' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}

		$body .= '</table>';

		if ( '' !== $too_large ) {
			$body .= '<p>' . esc_html( $too_large ) . '</p>';
		}

		$body .= '<p><a href="' . esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) . '">' . esc_html__( 'Open this quote in the admin', 'open-forms' ) . '</a></p>';

		/**
		 * Filters the HTML body of the new quote notification.
		 *
		 * @param string $body    Email body.
		 * @param int    $post_id Quote post ID.
		 */
		$body = (string) apply_filters( 'openforms_quote_notification_body', $body, $post_id );

		// Attachments live in the private folder and are only reachable by a
		// signed-in admin, so they are mailed rather than linked. The whole set is
		// skipped past the budget: the mail would be refused outright.
		$attachments = array();

		if ( '' === $too_large ) {
			foreach ( $files as $file ) {
				if ( ! empty( $file['file_path'] ) && is_readable( $file['file_path'] ) ) {
					$attachments[] = $file['file_path'];
				}
			}
		}

		if ( ! wp_mail( $to, $subject, $body, $headers, $attachments ) ) {
			wc_get_logger()->log(
				'error',
				'OpenForms: the new quote notification email could not be sent.',
				array( 'source' => 'openforms' )
			);
		}
	}

	/**
	 * Whether the requester has submitted a quote too recently.
	 *
	 * @return bool
	 */
	private function throttled(): bool {
		$key = $this->throttle_key();

		return '' !== $key && (bool) get_transient( $key );
	}

	/**
	 * Start the requester's cooldown once a quote has been stored.
	 */
	private function throttle(): void {
		$key = $this->throttle_key();

		if ( '' !== $key ) {
			set_transient( $key, 1, self::submission_interval() );
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
		if ( self::submission_interval() <= 0 || current_user_can( 'edit_posts' ) ) {
			return '';
		}

		// Forwarded headers are client controlled; see client_ip().
		$ip = FieldHelpers::client_ip();

		return '' === $ip ? '' : 'openforms_quote_submit_' . md5( $ip );
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
				$new['openforms_email']  = __( 'Email', 'open-forms' );
				$new['openforms_budget'] = __( 'Budget', 'open-forms' );
				$new['openforms_source'] = __( 'Source', 'open-forms' );
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
			__( 'Quote Details', 'open-forms' ),
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
		$files  = $this->files( $post->ID );

		$rows = array(
			'name'    => array( __( 'Name', 'open-forms' ), get_the_title( $post ) ),
			'email'   => array( __( 'Email', 'open-forms' ), $data['email'] ),
			'phone'   => array( __( 'Phone', 'open-forms' ), $data['phone'] ),
			'budget'  => array( __( 'Budget', 'open-forms' ), $data['budget'] ),
			'message' => array( __( 'Message', 'open-forms' ), $data['message'] ),
		);

		echo '<table class="widefat striped">';
		echo '<tbody>';

		foreach ( $rows as $key => $row ) {
			list( $label, $value ) = $row;
			$value                = 'message' === $key ? nl2br( esc_html( $value ) ) : esc_html( $value );
			$value                = '' !== trim( $value ) ? $value : '&mdash;';

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $value is escaped above.
		}

		if ( $files ) {
			echo '<tr><th scope="row">' . esc_html__( 'Attachments', 'open-forms' ) . '</th><td>';

			foreach ( $files as $file ) {
				echo DataHelpers::render_file( $file, true, true ) . '<br />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_file() returns escaped markup.
			}

			echo '</td></tr>';
		}

		if ( $source && get_post( $source ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Submitted from', 'open-forms' ) . '</th><td><a href="' . esc_url( get_permalink( $source ) ) . '">' . esc_html( get_the_title( $source ) ) . '</a></td></tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * The stored attachment list, accepting the old single-file value.
	 *
	 * @param int $post_id Quote post ID.
	 * @return array
	 */
	private function files( int $post_id ): array {
		$files = get_post_meta( $post_id, self::FILE_META, true );

		if ( ! is_array( $files ) ) {
			return array();
		}

		if ( isset( $files['file_key'] ) ) {
			return array( $files );
		}

		return $files;
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