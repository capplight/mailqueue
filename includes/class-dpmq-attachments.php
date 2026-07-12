<?php
/**
 * Attachment handling for queued emails.
 *
 * Form plugins (Contact Form 7, Gravity Forms, ...) pass temp file paths
 * to wp_mail() and delete them as soon as it returns. Because we return
 * early, those files would be gone by the time the background send runs —
 * so we copy them into a protected uploads subdirectory at queue time and
 * clean them up when the row is purged/deleted.
 *
 * @package DonePurpleMailQueue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copies and cleans up per-email attachment files.
 */
class DPMQ_Attachments {

	/**
	 * Base directory for copied attachments.
	 *
	 * @return string Path without trailing slash.
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'dpmq-attachments';
	}

	/**
	 * Copy attachment files for an email into its own subdirectory.
	 *
	 * @param int   $email_id Email row ID.
	 * @param array $paths    Original file paths.
	 * @return array|false New paths on success, false on any failure
	 *                     (caller should fail open and send synchronously).
	 */
	public static function copy( $email_id, array $paths ) {
		$base = self::base_dir();
		$dir  = $base . '/' . (int) $email_id;

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		self::protect( $base );

		$copied = array();
		$i      = 0;
		foreach ( $paths as $path ) {
			$path = (string) $path;
			if ( '' === trim( $path ) ) {
				continue;
			}
			if ( ! is_readable( $path ) ) {
				return false;
			}
			$i++;
			$dest = $dir . '/' . $i . '-' . sanitize_file_name( wp_basename( $path ) );
			if ( ! copy( $path, $dest ) ) {
				return false;
			}
			$copied[] = $dest;
		}

		return $copied;
	}

	/**
	 * Remove an email's attachment directory.
	 *
	 * @param int $email_id Email row ID.
	 */
	public static function delete_for( $email_id ) {
		$dir = self::base_dir() . '/' . (int) $email_id;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}
			@unlink( $dir . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Block direct web access to the attachments directory.
	 *
	 * @param string $base Base directory path.
	 */
	private static function protect( $base ) {
		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Apache 2.2 and 2.4 compatible deny-all.
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules ); // phpcs:ignore
		}
		$index = $base . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore
		}
	}
}
