<?php
/**
 * Plugin Name:       Done Purple Mail Queue
 * Plugin URI:        https://donepurple.com/
 * Description:       Sends WordPress emails in the background via Action Scheduler. Form submissions respond instantly while emails still go out within seconds.
 * Version:           0.1.0
 * Author:            Done Purple
 * Author URI:        https://donepurple.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       done-purple-mail-queue
 * Requires at least: 6.5
 * Requires PHP:      7.4
 *
 * @package DonePurpleMailQueue
 */

defined( 'ABSPATH' ) || exit;

define( 'DPMQ_VERSION', '0.1.0' );
define( 'DPMQ_PLUGIN_FILE', __FILE__ );
define( 'DPMQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Action Scheduler must be loaded early (it handles its own version
// resolution when other plugins, e.g. WooCommerce, bundle it too).
require_once DPMQ_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';

require_once DPMQ_PLUGIN_DIR . 'includes/class-dpmq-store.php';
require_once DPMQ_PLUGIN_DIR . 'includes/class-dpmq-attachments.php';
require_once DPMQ_PLUGIN_DIR . 'includes/class-dpmq-queue.php';
require_once DPMQ_PLUGIN_DIR . 'includes/class-dpmq-sender.php';

register_activation_hook( __FILE__, array( 'DPMQ_Store', 'install' ) );

add_action( 'plugins_loaded', 'dpmq_init' );

/**
 * Boot the plugin.
 */
function dpmq_init() {
	DPMQ_Store::maybe_upgrade();
	DPMQ_Queue::init();
	DPMQ_Sender::init();

	if ( is_admin() ) {
		require_once DPMQ_PLUGIN_DIR . 'includes/class-dpmq-admin.php';
		DPMQ_Admin::init();
	}
}
