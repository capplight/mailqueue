<?php
/**
 * Background sender: delivers queued emails via Action Scheduler.
 *
 * @package DonePurpleMailQueue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends queued emails, retries failures, purges old rows.
 */
class DPMQ_Sender {

	const MAX_ATTEMPTS = 3;

	/**
	 * True while we are calling wp_mail() ourselves, so DPMQ_Queue
	 * knows not to intercept and re-queue it (infinite loop otherwise).
	 *
	 * @var bool
	 */
	public static $sending = false;

	/**
	 * Last error captured from wp_mail_failed during our send.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'dpmq_send_email', array( __CLASS__, 'send' ) );
		add_action( 'dpmq_purge', array( __CLASS__, 'purge' ) );
		add_action( 'init', array( __CLASS__, 'schedule_purge' ) );
	}

	/**
	 * Make sure the daily purge is scheduled.
	 */
	public static function schedule_purge() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( 'dpmq_purge', array(), 'dpmq' ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'dpmq_purge', array(), 'dpmq' );
		}
	}

	/**
	 * Action Scheduler callback: send one queued email.
	 *
	 * @param int $email_id Email row ID.
	 */
	public static function send( $email_id ) {
		$email = DPMQ_Store::get( (int) $email_id );
		if ( ! $email ) {
			return;
		}
		// Idempotency: skip rows another runner already picked up or finished.
		if ( ! in_array( $email->status, array( 'queued', 'retrying' ), true ) ) {
			return;
		}

		$attempts = (int) $email->attempts + 1;
		DPMQ_Store::update(
			$email->id,
			array(
				'status'   => 'sending',
				'attempts' => $attempts,
			)
		);

		$recipients  = json_decode( (string) $email->recipients, true );
		$headers     = json_decode( (string) $email->headers, true );
		$attachments = json_decode( (string) $email->attachments, true );

		self::$last_error = '';
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		self::$sending = true;

		$sent = wp_mail(
			$recipients,
			(string) $email->subject,
			(string) $email->message,
			is_null( $headers ) ? '' : $headers,
			is_array( $attachments ) ? $attachments : array()
		);

		self::$sending = false;
		remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );

		if ( $sent ) {
			DPMQ_Store::update(
				$email->id,
				array(
					'status'     => 'sent',
					'sent_at'    => current_time( 'mysql', true ),
					'last_error' => '',
				)
			);
			return;
		}

		$error = self::$last_error ? self::$last_error : __( 'wp_mail() returned false.', 'done-purple-mail-queue' );

		if ( $attempts < self::MAX_ATTEMPTS ) {
			DPMQ_Store::update(
				$email->id,
				array(
					'status'     => 'retrying',
					'last_error' => $error,
				)
			);
			// Backoff: 1 minute after the first failure, 2 after the second.
			as_schedule_single_action(
				time() + ( MINUTE_IN_SECONDS * $attempts ),
				'dpmq_send_email',
				array( 'email_id' => (int) $email->id ),
				'dpmq'
			);
			return;
		}

		DPMQ_Store::update(
			$email->id,
			array(
				'status'     => 'failed',
				'last_error' => $error,
			)
		);
	}

	/**
	 * Re-queue an email (admin "Send again" for failed or sent rows).
	 *
	 * @param int $email_id Email row ID.
	 * @return bool
	 */
	public static function requeue( $email_id ) {
		$email = DPMQ_Store::get( (int) $email_id );
		if ( ! $email || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		DPMQ_Store::update(
			$email->id,
			array(
				'status'     => 'queued',
				'attempts'   => 0,
				'last_error' => '',
			)
		);
		as_enqueue_async_action( 'dpmq_send_email', array( 'email_id' => (int) $email->id ), 'dpmq' );
		return true;
	}

	/**
	 * Daily cleanup of old rows and their attachment files.
	 */
	public static function purge() {
		DPMQ_Store::purge( (int) get_option( 'dpmq_retention_days', 7 ) );
	}

	/**
	 * Capture the error message from wp_mail_failed.
	 *
	 * @param WP_Error $error Error from PHPMailer.
	 */
	public static function capture_error( $error ) {
		self::$last_error = $error->get_error_message();
	}
}
