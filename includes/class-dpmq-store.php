<?php
/**
 * Persistence for queued emails (custom table).
 *
 * Email payloads live in our own table rather than in Action Scheduler
 * action args, because AS caps args at ~8000 characters and HTML email
 * bodies routinely exceed that.
 *
 * @package DonePurpleMailQueue
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the {prefix}dpmq_emails table.
 */
class DPMQ_Store {

	const DB_VERSION = '1';

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dpmq_emails';
	}

	/**
	 * Create/upgrade the table (activation hook).
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				status VARCHAR(20) NOT NULL DEFAULT 'queued',
				recipients TEXT NULL,
				subject TEXT NULL,
				message LONGTEXT NULL,
				headers TEXT NULL,
				attachments TEXT NULL,
				attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
				last_error TEXT NULL,
				created_at DATETIME NOT NULL,
				sent_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( 'dpmq_db_version', self::DB_VERSION );

		if ( false === get_option( 'dpmq_enabled', false ) ) {
			add_option( 'dpmq_enabled', '1' );
		}
		if ( false === get_option( 'dpmq_retention_days', false ) ) {
			add_option( 'dpmq_retention_days', 7 );
		}
	}

	/**
	 * Re-run install when the schema version changes (e.g. after update).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'dpmq_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Insert a queued email from wp_mail() atts.
	 *
	 * @param array $atts {to, subject, message, headers, attachments}.
	 * @return int|false Row ID or false.
	 */
	public static function insert( array $atts ) {
		global $wpdb;

		$ok = $wpdb->insert(
			self::table(),
			array(
				'status'      => 'queued',
				'recipients'  => wp_json_encode( isset( $atts['to'] ) ? $atts['to'] : '' ),
				'subject'     => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'message'     => isset( $atts['message'] ) ? (string) $atts['message'] : '',
				'headers'     => wp_json_encode( isset( $atts['headers'] ) ? $atts['headers'] : '' ),
				'attachments' => wp_json_encode( array() ),
				'attempts'    => 0,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetch one row.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Update columns on a row.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data column => value.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete a row and its copied attachments.
	 *
	 * @param int $id Row ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		DPMQ_Attachments::delete_for( $id );
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Query rows for the admin list table.
	 *
	 * @param array $args {status, search, per_page, paged}.
	 * @return array {items: object[], total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;

		$table    = self::table();
		$where    = array( '1=1' );
		$params   = array();
		$per_page = max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) );
		$paged    = max( 1, (int) ( isset( $args['paged'] ) ? $args['paged'] : 1 ) );

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(subject LIKE %s OR recipients LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $paged - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$items_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$items     = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Count rows per status.
	 *
	 * @return array status => count.
	 */
	public static function counts() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status" );
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->n;
		}
		return $counts;
	}

	/**
	 * Number of unsent emails older than $minutes — a stuck-queue signal.
	 *
	 * @param int $minutes Age threshold.
	 * @return int
	 */
	public static function stuck_count( $minutes = 10 ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','retrying','sending') AND created_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Delete old rows: sent past retention, failed after 30 days.
	 *
	 * @param int $retention_days Days to keep sent emails.
	 */
	public static function purge( $retention_days ) {
		global $wpdb;
		$table       = self::table();
		$sent_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $retention_days ) * DAY_IN_SECONDS ) );
		$fail_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE (status = 'sent' AND created_at < %s)
				    OR (status = 'failed' AND created_at < %s)",
				$sent_cutoff,
				$fail_cutoff
			)
		);
		// phpcs:enable

		foreach ( (array) $ids as $id ) {
			self::delete( (int) $id );
		}
	}
}
