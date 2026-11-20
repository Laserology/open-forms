<?php
/**
 * QuoteHandler: quote submissions. Stores get a quote submissions as a private
 * post type and provides the admin viewer (list columns and a detail meta box).
 *
 * Every submission belongs to a quote form from FormRegistry, so the fields
 * shown in the admin are whatever that form defined rather than a fixed set.
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
		add_action( 'restrict_manage_posts', array( $this, 'form_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
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
		$referer      = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$stored_files = array();
		$form_id      = '';
		$upload_total = 0;

		$fail = function ( string $type = 'error' ) use ( $referer, &$stored_files, &$form_id ) {
			// Do not leave uploads behind when the quote was not created.
			foreach ( $stored_files as $group ) {
				foreach ( (array) $group as $file ) {
					if ( ! empty( $file['file_path'] ) && is_file( $file['file_path'] ) ) {
						unlink( $file['file_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					}
				}
			}

			$args = array( 'openforms_quote' => $type );

			if ( '' !== $form_id ) {
				$args['openforms_quote_form'] = $form_id;
			}

			wp_safe_redirect( add_query_arg( $args, $referer ) );
			exit;
		};

		if ( empty( $_POST['openforms_quote_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['openforms_quote_nonce'] ) ), 'openforms_quote_submit' ) ) {
			$fail();
		}

		$data = isset( $_POST['openforms_quote'] ) && is_array( $_POST['openforms_quote'] ) ? wp_unslash( $_POST['openforms_quote'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually below.

		$source  = isset( $data['source'] ) ? absint( $data['source'] ) : 0;
		$form_id = isset( $data['form'] ) && is_string( $data['form'] ) ? sanitize_key( $data['form'] ) : '';

		// A page cached before forms were identified posts no form ID; its source
		// post still points at the form that rendered it.
		if ( '' === $form_id && $source > 0 ) {
			$form_id = 'form-' . $source;
		}

		// Rebuild a missing definition from the post that rendered the form.
		if ( '' !== $form_id && ! FormRegistry::exists( $form_id ) && $source > 0 ) {
			FormRegistry::refresh_post( $source );
		}

		if ( '' === $form_id || ! FormRegistry::exists( $form_id ) ) {
			$fail();
		}

		$form      = FormRegistry::get( $form_id );
		$form_name = '' !== $form['name'] ? $form['name'] : __( 'Quote form', 'open-forms' );
		$entries   = array();
		$reply_to  = '';

		// The form is open to anyone, so every submission is rate limited: an
		// attachment fills disk space, and each stored quote sends the store
		// owner an email. The cooldown is claimed further down, once the quote is
		// safely stored, so a rejected submission costs the visitor nothing.
		if ( $this->throttled() ) {
			$fail( 'throttle' );
		}

		foreach ( $form['fields'] as $field ) {
			$key   = $field['key'];
			$type  = $field['type'];
			$label = $field['label'];

			if ( 'file' === $type ) {
				$files = array();
				$group = 'openforms_quote_files';

				// A cached page from before generic fields posts its single file
				// field through the old group name.
				if ( 'file' === $key && ! FieldHelpers::has_files( $group, $key ) ) {
					$group = 'openforms_quote';
				}

				if ( FieldHelpers::has_files( $group, $key ) ) {
					$upload_total += FieldHelpers::upload_size( $group, $key );
					$files         = FieldHelpers::upload_files( $group, $key, self::attachment_mimes() );

					if ( ! $files ) {
						$fail( 'file' );
					}

					$stored_files[ $key ] = $files;
				} elseif ( ! empty( $field['required'] ) ) {
					$fail();
				}

				$entries[] = array(
					'key'     => $key,
					'label'   => $label,
					'type'    => 'file',
					'value'   => '',
					'preview' => ! empty( $field['preview'] ),
				);

				continue;
			}

			$value = isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? trim( $data[ $key ] ) : '';

			if ( '' !== $value ) {
				if ( 'email' === $type && ! is_email( $value ) ) {
					$fail();
				}

				if ( 'url' === $type && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$fail();
				}

				if ( 'number' === $type && ! is_numeric( $value ) ) {
					$fail();
				}

				if ( 'color' === $type && ! sanitize_hex_color( $value ) ) {
					$fail();
				}

				if ( 'select' === $type && ! in_array( $value, (array) $field['options'], true ) ) {
					$value = '';
				}

				if ( 'email' === $type && '' === $reply_to ) {
					$reply_to = $value;
				}
			}

			if ( '' === $value && ! empty( $field['required'] ) ) {
				$fail();
			}

			$entries[] = array(
				'key'     => $key,
				'label'   => $label,
				'type'    => $type,
				'value'   => FieldHelpers::sanitize( $type, $value ),
				'preview' => ! empty( $field['preview'] ),
			);
		}

		// The form name prefixes the title so the quotes list is readable at a
		// glance without opening a submission.
		if ( $upload_total > FieldHelpers::max_request_upload_size() ) {
			$fail( 'file' );
		}

		$primary = $this->primary_value( $entries );

		if ( '' !== $primary ) {
			$primary = wp_html_excerpt( $primary, 60, '…' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'openforms_quote',
				'post_status' => 'publish',
				'post_title'  => '' !== $primary ? $form_name . ' – ' . $primary : $form_name,
				// Unowned, so submitters cannot edit their own quote.
				'post_author' => 0,
			)
		);

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			$fail();
		}

		update_post_meta( $post_id, '_openforms_quote_form', $form_id );
		update_post_meta( $post_id, '_openforms_quote_form_name', $form_name );
		update_post_meta( $post_id, '_openforms_quote_fields', $entries );

		if ( $stored_files ) {
			update_post_meta( $post_id, self::FILE_META, $stored_files );
		}

		if ( $source ) {
			update_post_meta( $post_id, '_openforms_quote_source', $source );
		}

		// Claim the cooldown only now that the quote is stored, so a submission
		// that fails validation or upload does not lock the visitor out.
		$this->throttle();

		$this->notify( $post_id, $reply_to );

		wp_safe_redirect(
			add_query_arg(
				array(
					'openforms_quote'      => 'success',
					'openforms_quote_form' => $form_id,
				),
				$referer
			)
		);
		exit;
	}

	/**
	 * Email the site owner about a stored quote. A delivery failure is only
	 * logged: the quote is already saved, and the visitor must not be shown an
	 * error that the admin mail caused.
	 *
	 * @param int    $post_id  Quote post ID.
	 * @param string $reply_to Address supplied by an email field, if any.
	 */
	private function notify( int $post_id, string $reply_to ): void {
		$entries   = $this->entries( $post_id );
		$files     = $this->file_groups( $post_id );
		$form_name = (string) get_post_meta( $post_id, '_openforms_quote_form_name', true );

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

		$total_size = 0;

		foreach ( $files as $group ) {
			foreach ( (array) $group as $file ) {
				$total_size += isset( $file['file_size'] ) ? (int) $file['file_size'] : 0;
			}
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

		$rows = array();

		if ( '' !== $form_name ) {
			$rows[ __( 'Form', 'open-forms' ) ] = $form_name;
		}

		foreach ( $entries as $entry ) {
			if ( 'file' === $entry['type'] ) {
				$names = array();

				foreach ( (array) ( $files[ $entry['key'] ] ?? array() ) as $file ) {
					if ( ! empty( $file['file_name'] ) ) {
						$names[] = $file['file_name'] . ' (' . size_format( (int) $file['file_size'] ) . ')';
					}
				}

				$rows[ $entry['label'] ] = implode( "\n", $names );
				continue;
			}

			$rows[ $entry['label'] ] = (string) $entry['value'];
		}

		$rows = array_filter(
			$rows,
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		$subject = sprintf(
			/* translators: 1: site name, 2: quote form name. */
			__( '[%1$s] New quote request: %2$s', 'open-forms' ),
			get_bloginfo( 'name' ),
			'' !== $form_name ? $form_name : __( 'Quote form', 'open-forms' )
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Replying goes straight back to whoever asked for the quote.
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
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
			foreach ( $files as $group ) {
				foreach ( (array) $group as $file ) {
					if ( ! empty( $file['file_path'] ) && is_readable( $file['file_path'] ) ) {
						$attachments[] = $file['file_path'];
					}
				}
			}
		}

		if ( ! wp_mail( $to, $subject, $body, $headers, $attachments ) ) {
			$message = 'OpenForms: the new quote notification email could not be sent.';

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->log(
					'error',
					$message,
					array( 'source' => 'openforms' )
				);
			} else {
				error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * The value used after the form name in the quote title: the first preview
	 * field with a value, falling back to the first text-like value.
	 *
	 * @param array $entries Stored entries.
	 * @return string
	 */
	private function primary_value( array $entries ): string {
		$fallback = '';

		foreach ( $entries as $entry ) {
			if ( 'file' === $entry['type'] ) {
				continue;
			}

			$value = trim( (string) $entry['value'] );

			if ( '' === $value ) {
				continue;
			}

			if ( ! empty( $entry['preview'] ) ) {
				return $value;
			}

			if ( '' === $fallback && in_array( $entry['type'], array( 'text', 'email', 'tel', 'number' ), true ) ) {
				$fallback = $value;
			}
		}

		return $fallback;
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
				$new['openforms_form']    = __( 'Form', 'open-forms' );
				$new['openforms_preview'] = __( 'Preview', 'open-forms' );
				$new['openforms_source']  = __( 'Source', 'open-forms' );
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
		if ( 'openforms_form' === $column ) {
			$name = (string) get_post_meta( $post_id, '_openforms_quote_form_name', true );

			echo '' !== $name ? esc_html( $name ) : '&mdash;';

			return;
		}

		if ( 'openforms_preview' === $column ) {
			echo $this->preview_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preview_html() returns escaped markup.
			return;
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
	 * The quick preview shown in the quotes list: every field the form marked
	 * for the preview, whatever its name or type.
	 *
	 * @param int $post_id Quote post ID.
	 * @return string
	 */
	private function preview_html( int $post_id ): string {
		$rows = array();

		foreach ( $this->entries( $post_id ) as $entry ) {
			if ( empty( $entry['preview'] ) || 'file' === $entry['type'] ) {
				continue;
			}

			$value = trim( (string) $entry['value'] );

			if ( '' === $value ) {
				continue;
			}

			$rows[] = '<strong>' . esc_html( $entry['label'] ) . '</strong> ' . esc_html( $value );
		}

		return $rows ? implode( '<br />', $rows ) : '&mdash;';
	}

	/**
	 * The form dropdown above the quotes list, built from the registry so a
	 * form disappears from the admin once its block is deleted.
	 *
	 * @param string $post_type Current post type.
	 */
	public function form_filter( string $post_type ): void {
		if ( 'openforms_quote' !== $post_type ) {
			return;
		}

		$forms = FormRegistry::all();

		if ( empty( $forms ) ) {
			return;
		}

		$selected = isset( $_GET['openforms_quote_form_filter'] ) ? sanitize_key( wp_unslash( $_GET['openforms_quote_form_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.

		echo '<select name="openforms_quote_form_filter" aria-label="' . esc_attr__( 'Filter by form', 'open-forms' ) . '">';
		echo '<option value="">' . esc_html__( 'All forms', 'open-forms' ) . '</option>';

		foreach ( $forms as $form ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $form['id'] ),
				selected( $selected, $form['id'], false ),
				esc_html( $form['name'] )
			);
		}

		echo '</select>';
	}

	/**
	 * Limit the quotes list to the selected form.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function filter_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || ! in_array( 'openforms_quote', (array) $query->get( 'post_type' ), true ) ) {
			return;
		}

		$form_id = isset( $_GET['openforms_quote_form_filter'] ) ? sanitize_key( wp_unslash( $_GET['openforms_quote_form_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.

		if ( '' !== $form_id ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => '_openforms_quote_form',
						'value' => $form_id,
					),
				)
			);
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
	 * Render the submitted quote details on the edit screen. The rows follow the
	 * field snapshot stored with the submission, so any number of fields is
	 * shown without a fixed layout.
	 *
	 * @param \WP_Post $post Quote post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$form_name = (string) get_post_meta( $post->ID, '_openforms_quote_form_name', true );
		$entries   = $this->entries( $post->ID );
		$files     = $this->file_groups( $post->ID );
		$source    = (int) get_post_meta( $post->ID, '_openforms_quote_source', true );

		echo '<table class="widefat striped">';
		echo '<tbody>';

		if ( '' !== $form_name ) {
			echo '<tr><th scope="row">' . esc_html__( 'Form', 'open-forms' ) . '</th><td>' . esc_html( $form_name ) . '</td></tr>';
		}

		if ( empty( $entries ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Name', 'open-forms' ) . '</th><td>' . esc_html( get_the_title( $post ) ) . '</td></tr>';
		}

		$shown = array();

		foreach ( $entries as $entry ) {
			$shown[] = $entry['key'];

			if ( 'file' === $entry['type'] ) {
				$value = '';

				foreach ( (array) ( $files[ $entry['key'] ] ?? array() ) as $file ) {
					$value .= DataHelpers::render_file( $file, true, true ) . '<br />';
				}

				$value = '' !== $value ? $value : '&mdash;';
			} else {
				$value = '' !== trim( (string) $entry['value'] ) ? nl2br( esc_html( $entry['value'] ) ) : '&mdash;';
			}

			echo '<tr><th scope="row">' . esc_html( $entry['label'] ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $value is escaped above.
		}

		// Legacy quotes, and files uploaded through a field the form no longer
		// defines, still get an attachments row.
		$orphans = array_diff_key( $files, array_flip( $shown ) );

		foreach ( $orphans as $group ) {
			$value = '';

			foreach ( (array) $group as $file ) {
				$value .= DataHelpers::render_file( $file, true, true ) . '<br />';
			}

			echo '<tr><th scope="row">' . esc_html__( 'Attachments', 'open-forms' ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_file() returns escaped markup.
		}

		if ( $source && get_post( $source ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Submitted from', 'open-forms' ) . '</th><td><a href="' . esc_url( get_permalink( $source ) ) . '">' . esc_html( get_the_title( $source ) ) . '</a></td></tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * The stored field snapshot for a quote. Quotes created before fields were
	 * configurable fall back to the old fixed columns.
	 *
	 * @param int $post_id Quote post ID.
	 * @return array
	 */
	private function entries( int $post_id ): array {
		$entries = get_post_meta( $post_id, '_openforms_quote_fields', true );

		if ( is_array( $entries ) && ! empty( $entries ) ) {
			return $entries;
		}

		$data = $this->legacy_data( $post_id );
		$rows = array(
			array(
				'key'     => 'name',
				'label'   => __( 'Name', 'open-forms' ),
				'type'    => 'text',
				'value'   => get_the_title( $post_id ),
				'preview' => false,
			),
		);

		$labels = array(
			'email'   => array( __( 'Email', 'open-forms' ), 'email', true ),
			'phone'   => array( __( 'Phone', 'open-forms' ), 'tel', true ),
			'budget'  => array( __( 'Budget', 'open-forms' ), 'text', true ),
			'message' => array( __( 'Message', 'open-forms' ), 'textarea', false ),
		);

		foreach ( $labels as $key => $meta ) {
			if ( '' === trim( (string) $data[ $key ] ) ) {
				continue;
			}

			$rows[] = array(
				'key'     => $key,
				'label'   => $meta[0],
				'type'    => $meta[1],
				'value'   => $data[ $key ],
				'preview' => $meta[2],
			);
		}

		return $rows;
	}

	/**
	 * The fixed data array stored by quotes submitted before the generic fields.
	 *
	 * @param int $post_id Quote post ID.
	 * @return array
	 */
	private function legacy_data( int $post_id ): array {
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

	/**
	 * The stored attachments grouped by the field that received them. Also
	 * accepts the old single-file and flat-list formats.
	 *
	 * @param int $post_id Quote post ID.
	 * @return array Field key => list of file details.
	 */
	private function file_groups( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::FILE_META, true );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return array();
		}

		$first = reset( $stored );

		// Current format: field key => list of files.
		if ( is_array( $first ) && isset( $first[0] ) && is_array( $first[0] ) ) {
			return $stored;
		}

		// Legacy: a single file, or a flat list of files.
		if ( isset( $stored['file_key'] ) ) {
			return array( 'file' => array( $stored ) );
		}

		if ( isset( $stored[0] ) && is_array( $stored[0] ) && isset( $stored[0]['file_key'] ) ) {
			return array( 'file' => array_values( $stored ) );
		}

		return array();
	}
}
