<?php
/**
 * Admin page: email log, settings, health checks.
 *
 * @package DonePurpleMailQueue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools → Mail Queue.
 */
class DPMQ_Admin {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register the Tools submenu page.
	 */
	public static function menu() {
		$hook = add_management_page(
			__( 'Mail Queue', 'done-purple-mail-queue' ),
			__( 'Mail Queue', 'done-purple-mail-queue' ),
			'manage_options',
			'dpmq',
			array( __CLASS__, 'render_page' )
		);
		add_action( 'load-' . $hook, array( __CLASS__, 'handle_actions' ) );
	}

	/**
	 * Settings.
	 */
	public static function register_settings() {
		register_setting(
			'dpmq_settings',
			'dpmq_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				'default'           => '1',
			)
		);
		register_setting(
			'dpmq_settings',
			'dpmq_retention_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 7,
			)
		);
	}

	/**
	 * Checkbox sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return string '1' or '0'.
	 */
	public static function sanitize_checkbox( $value ) {
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Handle row/bulk actions before output.
	 */
	public static function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require_once DPMQ_PLUGIN_DIR . 'includes/class-dpmq-list-table.php';

		// Single row actions (GET links with per-row nonce).
		if ( isset( $_GET['action'], $_GET['email'], $_GET['_wpnonce'] ) && ! is_array( $_GET['email'] ) ) {
			$id     = (int) $_GET['email'];
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );

			if ( 'requeue' === $action && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dpmq_requeue_' . $id ) ) {
				DPMQ_Sender::requeue( $id );
				wp_safe_redirect( admin_url( 'tools.php?page=dpmq&dpmq_notice=requeued' ) );
				exit;
			}
			if ( 'delete' === $action && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dpmq_delete_' . $id ) ) {
				DPMQ_Store::delete( $id );
				wp_safe_redirect( admin_url( 'tools.php?page=dpmq&dpmq_notice=deleted' ) );
				exit;
			}
		}

		// Bulk delete (POST from the list table).
		if ( isset( $_POST['email'] ) && is_array( $_POST['email'] ) ) {
			check_admin_referer( 'bulk-emails' );
			$table  = new DPMQ_List_Table();
			$action = $table->current_action();
			if ( 'delete' === $action ) {
				foreach ( array_map( 'intval', (array) wp_unslash( $_POST['email'] ) ) as $id ) {
					DPMQ_Store::delete( $id );
				}
				wp_safe_redirect( admin_url( 'tools.php?page=dpmq&dpmq_notice=deleted' ) );
				exit;
			}
		}
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		require_once DPMQ_PLUGIN_DIR . 'includes/class-dpmq-list-table.php';

		$table = new DPMQ_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mail Queue', 'done-purple-mail-queue' ); ?></h1>

			<?php self::notices(); ?>
			<?php self::health_checks(); ?>

			<form method="post" action="options.php" style="background:#fff;border:1px solid #c3c4c7;padding:1em 1.5em;margin:1em 0;">
				<?php settings_fields( 'dpmq_settings' ); ?>
				<h2 style="margin-top:0;"><?php esc_html_e( 'Settings', 'done-purple-mail-queue' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Background sending', 'done-purple-mail-queue' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="dpmq_enabled" value="1" <?php checked( '1', get_option( 'dpmq_enabled', '1' ) ); ?> />
								<?php esc_html_e( 'Queue outgoing emails and send them in the background', 'done-purple-mail-queue' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dpmq_retention_days"><?php esc_html_e( 'Keep sent emails for', 'done-purple-mail-queue' ); ?></label></th>
						<td>
							<input type="number" min="1" max="365" id="dpmq_retention_days" name="dpmq_retention_days" value="<?php echo esc_attr( get_option( 'dpmq_retention_days', 7 ) ); ?>" class="small-text" />
							<?php esc_html_e( 'days (failed emails are kept 30 days)', 'done-purple-mail-queue' ); ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'done-purple-mail-queue' ), 'primary', 'submit', false ); ?>
			</form>

			<form method="post">
				<?php
				$table->views();
				$table->search_box( __( 'Search emails', 'done-purple-mail-queue' ), 'dpmq-search' );
				// Preserve page + status across the search/bulk form.
				echo '<input type="hidden" name="page" value="dpmq" />';
				if ( isset( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Success notices after row actions.
	 */
	private static function notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['dpmq_notice'] ) ? sanitize_key( wp_unslash( $_GET['dpmq_notice'] ) ) : '';
		if ( 'requeued' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Email queued for sending.', 'done-purple-mail-queue' ) . '</p></div>';
		} elseif ( 'deleted' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'done-purple-mail-queue' ) . '</p></div>';
		}
	}

	/**
	 * Warn about conditions that break background sending.
	 */
	private static function health_checks() {
		// 1. Another plugin replaced the pluggable wp_mail() entirely —
		// pre_wp_mail never fires and this plugin silently does nothing.
		if ( function_exists( 'wp_mail' ) ) {
			try {
				$ref  = new ReflectionFunction( 'wp_mail' );
				$file = (string) $ref->getFileName();
				if ( false === strpos( wp_normalize_path( $file ), wp_normalize_path( ABSPATH . WPINC ) ) ) {
					printf(
						'<div class="notice notice-error"><p><strong>%s</strong> %s<br /><code>%s</code></p></div>',
						esc_html__( 'Mail Queue is inactive:', 'done-purple-mail-queue' ),
						esc_html__( 'another plugin has replaced the wp_mail() function, so emails cannot be intercepted. It is defined in:', 'done-purple-mail-queue' ),
						esc_html( $file )
					);
				}
			} catch ( ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Ignore.
			}
		}

		// 2. Emails stuck in the queue — background runner is likely broken.
		$stuck = DPMQ_Store::stuck_count( 10 );
		if ( $stuck > 0 ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of emails */
						_n( '%d email has been waiting more than 10 minutes.', '%d emails have been waiting more than 10 minutes.', $stuck, 'done-purple-mail-queue' ),
						$stuck
					)
				),
				esc_html__( 'The background runner (WP-Cron / Action Scheduler) may not be working on this host. Check Tools → Scheduled Actions, and consider configuring a real server cron job.', 'done-purple-mail-queue' )
			);
		}

		// 3. Action Scheduler functions unavailable (should not happen — bundled).
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Action Scheduler is not available; emails are being sent synchronously.', 'done-purple-mail-queue' )
			);
		}
	}
}
