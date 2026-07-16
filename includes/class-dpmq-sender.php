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
	 * Minutes after which an unsent row is considered stuck (PHP timeout is
	 * far shorter, so a row 'sending' this long is a crashed runner).
	 */
	const STUCK_MINUTES = 5;

	/**
	 * True while we are calling wp_mail() ourselves, so DPMQ_Queue
	 * knows not to intercept and re-queue it (infinite loop otherwise).
	 *
	 * @var bool
	 */
	public static $sending = false;

	/**
	 * True while the deactivation flush runs — suppresses scheduling
	 * retry actions that nothing would be around to handle.
	 *
	 * @var bool
	 */
	private static $deactivating = false;

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
		add_action( 'dpmq_watchdog', array( __CLASS__, 'watchdog' ) );
		add_action( 'init', array( __CLASS__, 'schedule_recurring' ) );
	}

	/**
	 * Make sure the daily purge and the 5-minute watchdog are scheduled.
	 */
	public static function schedule_recurring() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( 'dpmq_purge', array(), 'dpmq' ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'dpmq_purge', array(), 'dpmq' );
		}
		if ( false === as_next_scheduled_action( 'dpmq_watchdog', array(), 'dpmq' ) ) {
			as_schedule_recurring_action( time() + ( 5 * MINUTE_IN_SECONDS ), 5 * MINUTE_IN_SECONDS, 'dpmq_watchdog', array(), 'dpmq' );
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
		// Atomic claim: flips queued/retrying → sending in one UPDATE, so a
		// duplicate action (stray retry, watchdog overlap) can never double-send.
		$attempts = (int) $email->attempts + 1;
		if ( ! DPMQ_Store::claim( $email->id, $attempts ) ) {
			return;
		}

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
			// During the deactivation flush nothing would handle the action,
			// so leave the row 'retrying' for the watchdog after reactivation.
			if ( ! self::$deactivating ) {
				as_schedule_single_action(
					time() + ( MINUTE_IN_SECONDS * $attempts ),
					'dpmq_send_email',
					array( 'email_id' => (int) $email->id ),
					'dpmq'
				);
			}
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
	 * Watchdog (every 5 minutes): recover emails that fell through the cracks.
	 *
	 * Covers two failure modes:
	 * - 'sending' rows whose runner crashed mid-send (PHP fatal, SMTP + PHP
	 *   timeout): flipped back to 'retrying' and re-queued, or marked failed
	 *   once attempts are exhausted.
	 * - 'queued'/'retrying' rows whose Action Scheduler action was lost
	 *   (deactivation window, AS failure): re-queued. A duplicate action for
	 *   a healthy row is harmless — the atomic claim makes it a no-op.
	 */
	public static function watchdog() {
		foreach ( DPMQ_Store::stale( self::STUCK_MINUTES ) as $email ) {
			if ( 'sending' === $email->status && (int) $email->attempts >= self::MAX_ATTEMPTS ) {
				DPMQ_Store::update(
					$email->id,
					array(
						'status'     => 'failed',
						'last_error' => __( 'Send attempt timed out or crashed.', 'done-purple-mail-queue' ),
					)
				);
				continue;
			}
			if ( 'sending' === $email->status ) {
				// Crashed mid-send; make it claimable again (keeps the
				// attempts already burned).
				DPMQ_Store::update( $email->id, array( 'status' => 'retrying' ) );
			}
			as_enqueue_async_action( 'dpmq_send_email', array( 'email_id' => (int) $email->id ), 'dpmq' );
		}
	}

	/**
	 * Deactivation: deliver what we owe, then clean up after ourselves.
	 *
	 * While the plugin is inactive nothing handles our Action Scheduler
	 * hooks, so pending actions would just pile up failures — cancel them
	 * all, and send any unsent emails synchronously right now so nothing
	 * is lost.
	 */
	public static function deactivate() {
		self::$deactivating = true;

		// Make crashed 'sending' rows claimable, then flush synchronously.
		foreach ( DPMQ_Store::stale( 0 ) as $email ) {
			if ( 'sending' === $email->status ) {
				// A fresh 'sending' row may be a live runner mid-SMTP right
				// now — leave it alone (re-claiming it could double-send).
				$age = time() - strtotime( $email->created_at . ' UTC' );
				if ( $age < self::STUCK_MINUTES * MINUTE_IN_SECONDS || (int) $email->attempts >= self::MAX_ATTEMPTS ) {
					continue;
				}
				DPMQ_Store::update( $email->id, array( 'status' => 'retrying' ) );
			}
			self::send( (int) $email->id );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'dpmq' );
		}

		self::$deactivating = false;
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
