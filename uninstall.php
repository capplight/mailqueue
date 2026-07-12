<?php
/**
 * Uninstall cleanup: table, options, attachment files, scheduled actions.
 *
 * @package DonePurpleMailQueue
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Cancel our scheduled actions if Action Scheduler is available
// (e.g. via WooCommerce); otherwise they fail harmlessly as orphans.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'dpmq_send_email', array(), 'dpmq' );
	as_unschedule_all_actions( 'dpmq_purge', array(), 'dpmq' );
}

// Drop the queue table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dpmq_emails" ); // phpcs:ignore

// Options.
delete_option( 'dpmq_db_version' );
delete_option( 'dpmq_enabled' );
delete_option( 'dpmq_retention_days' );

// Copied attachment files.
$uploads = wp_upload_dir();
$base    = trailingslashit( $uploads['basedir'] ) . 'dpmq-attachments';
if ( is_dir( $base ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			@rmdir( $file->getPathname() ); // phpcs:ignore
		} else {
			@unlink( $file->getPathname() ); // phpcs:ignore
		}
	}
	@rmdir( $base ); // phpcs:ignore
}
