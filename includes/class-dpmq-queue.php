<?php
/**
 * Intercepts wp_mail() and queues the email for background sending.
 *
 * @package DonePurpleMailQueue
 */

defined( 'ABSPATH' ) || exit;

/**
 * The pre_wp_mail short-circuit.
 */
class DPMQ_Queue {

	/**
	 * Hook in.
	 */
	public static function init() {
		// Late priority so other pre_wp_mail short-circuits win over us.
		add_filter( 'pre_wp_mail', array( __CLASS__, 'maybe_queue' ), 99999, 2 );
	}

	/**
	 * Queue the email and short-circuit wp_mail().
	 *
	 * Every bail-out path returns $return (null), which lets wp_mail()
	 * proceed with a normal synchronous send — the plugin fails open.
	 *
	 * @param null|bool $return Short-circuit value from earlier filters.
	 * @param array     $atts   {to, subject, message, headers, attachments}.
	 * @return null|bool True when queued (reported to caller as "sent").
	 */
	public static function maybe_queue( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}
		if ( DPMQ_Sender::$sending ) {
			// This IS the background send — don't re-queue our own email.
			return $return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return $return;
		}
		if ( '1' !== get_option( 'dpmq_enabled', '1' ) ) {
			return $return;
		}

		/**
		 * Whether this email should be queued (true) or sent synchronously (false).
		 *
		 * Password-reset emails are sent synchronously by default: if the
		 * background runner is broken, users must still be able to log in.
		 *
		 * @param bool  $should_queue Default decision.
		 * @param array $atts         wp_mail() arguments.
		 */
		$should_queue = apply_filters( 'dpmq_should_queue', ! did_action( 'retrieve_password' ), $atts );
		if ( ! $should_queue ) {
			return $return;
		}

		$id = DPMQ_Store::insert( $atts );
		if ( ! $id ) {
			return $return;
		}

		$attachments = self::normalize_attachments( isset( $atts['attachments'] ) ? $atts['attachments'] : array() );
		if ( $attachments ) {
			$copied = DPMQ_Attachments::copy( $id, $attachments );
			if ( false === $copied ) {
				// Couldn't secure the attachment files: send synchronously instead.
				DPMQ_Store::delete( $id );
				return $return;
			}
			DPMQ_Store::update( $id, array( 'attachments' => wp_json_encode( $copied ) ) );
		}

		as_enqueue_async_action( 'dpmq_send_email', array( 'email_id' => (int) $id ), 'dpmq' );

		// The caller sees success; actual delivery is logged on our admin page.
		return true;
	}

	/**
	 * Mirror wp_mail()'s attachment normalization (string => newline list).
	 *
	 * @param mixed $attachments Raw attachments argument.
	 * @return array
	 */
	private static function normalize_attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}
		return array_filter( array_map( 'trim', $attachments ) );
	}
}
