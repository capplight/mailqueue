<?php
/**
 * Admin list table for the email log.
 *
 * @package DonePurpleMailQueue
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders queued/sent/failed emails.
 */
class DPMQ_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'email',
				'plural'   => 'emails',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'subject'    => __( 'Subject', 'done-purple-mail-queue' ),
			'recipients' => __( 'To', 'done-purple-mail-queue' ),
			'status'     => __( 'Status', 'done-purple-mail-queue' ),
			'attempts'   => __( 'Attempts', 'done-purple-mail-queue' ),
			'created_at' => __( 'Queued', 'done-purple-mail-queue' ),
			'sent_at'    => __( 'Sent', 'done-purple-mail-queue' ),
			'took'       => __( 'Delivery', 'done-purple-mail-queue' ),
		);
	}

	/**
	 * Status filter links above the table.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = DPMQ_Store::counts();
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base    = admin_url( 'tools.php?page=dpmq' );
		$views   = array();

		$all_count    = array_sum( $counts );
		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $base ),
			'' === $current ? ' class="current"' : '',
			esc_html__( 'All', 'done-purple-mail-queue' ),
			$all_count
		);

		foreach ( array( 'queued', 'retrying', 'sending', 'sent', 'failed' ) as $status ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( ucfirst( $status ) ),
				$counts[ $status ]
			);
		}

		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'done-purple-mail-queue' ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		// phpcs:enable

		$per_page = 20;
		$paged    = $this->get_pagenum();

		$result = DPMQ_Store::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);

		$this->items = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="email[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Subject column with row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_subject( $item ) {
		$subject = $item->subject ? $item->subject : __( '(no subject)', 'done-purple-mail-queue' );

		$send_url = wp_nonce_url(
			admin_url( 'tools.php?page=dpmq&action=requeue&email=' . (int) $item->id ),
			'dpmq_requeue_' . (int) $item->id
		);
		$del_url  = wp_nonce_url(
			admin_url( 'tools.php?page=dpmq&action=delete&email=' . (int) $item->id ),
			'dpmq_delete_' . (int) $item->id
		);

		$actions = array(
			'requeue' => sprintf( '<a href="%s">%s</a>', esc_url( $send_url ), esc_html__( 'Send again', 'done-purple-mail-queue' ) ),
			'delete'  => sprintf( '<a href="%s">%s</a>', esc_url( $del_url ), esc_html__( 'Delete', 'done-purple-mail-queue' ) ),
		);

		$error = '';
		if ( 'failed' === $item->status && $item->last_error ) {
			$error = '<br /><span style="color:#b32d2e;">' . esc_html( $item->last_error ) . '</span>';
		}

		return '<strong>' . esc_html( wp_html_excerpt( $subject, 80, '…' ) ) . '</strong>' . $error . $this->row_actions( $actions );
	}

	/**
	 * Recipients column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_recipients( $item ) {
		$to = json_decode( (string) $item->recipients, true );
		if ( is_array( $to ) ) {
			$to = implode( ', ', $to );
		}
		return esc_html( wp_html_excerpt( (string) $to, 60, '…' ) );
	}

	/**
	 * Status column as a colored badge.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_status( $item ) {
		$colors = array(
			'queued'   => '#996800',
			'retrying' => '#996800',
			'sending'  => '#2271b1',
			'sent'     => '#00a32a',
			'failed'   => '#b32d2e',
		);
		$color  = isset( $colors[ $item->status ] ) ? $colors[ $item->status ] : '#646970';
		return sprintf( '<span style="color:%s;font-weight:600;">%s</span>', esc_attr( $color ), esc_html( ucfirst( $item->status ) ) );
	}

	/**
	 * Queued time, in the site's timezone.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_created_at( $item ) {
		return $this->format_gmt( $item->created_at );
	}

	/**
	 * Sent time, in the site's timezone.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_sent_at( $item ) {
		return $this->format_gmt( $item->sent_at );
	}

	/**
	 * Queue → send duration.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_took( $item ) {
		if ( ! $item->sent_at || 'sent' !== $item->status ) {
			return '—';
		}
		$seconds = strtotime( $item->sent_at . ' UTC' ) - strtotime( $item->created_at . ' UTC' );
		if ( $seconds < 0 ) {
			return '—';
		}
		if ( $seconds < MINUTE_IN_SECONDS ) {
			/* translators: %d: seconds */
			return esc_html( sprintf( __( '%ds', 'done-purple-mail-queue' ), $seconds ) );
		}
		/* translators: 1: minutes, 2: seconds */
		return esc_html( sprintf( __( '%1$dm %2$ds', 'done-purple-mail-queue' ), (int) floor( $seconds / MINUTE_IN_SECONDS ), $seconds % MINUTE_IN_SECONDS ) );
	}

	/**
	 * Render a stored UTC datetime in the site's timezone, with an "ago" hint.
	 *
	 * @param string|null $gmt_datetime MySQL datetime in UTC.
	 * @return string
	 */
	private function format_gmt( $gmt_datetime ) {
		if ( ! $gmt_datetime ) {
			return '—';
		}
		$timestamp = strtotime( $gmt_datetime . ' UTC' );
		$local     = get_date_from_gmt( $gmt_datetime, 'M j, Y H:i:s' );
		/* translators: %s: human-readable time difference */
		$ago = sprintf( __( '%s ago', 'done-purple-mail-queue' ), human_time_diff( $timestamp ) );
		return esc_html( $local ) . '<br /><span style="color:#646970;">' . esc_html( $ago ) . '</span>';
	}

	/**
	 * Default column renderer.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		$value = isset( $item->$column_name ) ? $item->$column_name : '';
		return esc_html( (string) $value );
	}
}
