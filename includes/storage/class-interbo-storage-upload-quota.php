<?php
/**
 * Soft quota enforcement for normal WordPress uploads.
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents uploads that exceed the configured storage quota.
 */
class Interbo_Storage_Upload_Quota {
	/**
	 * Initializes the upload quota filters.
	 */
	public static function init() {
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'check_upload_quota' ) );
		add_filter( 'wp_handle_upload', array( __CLASS__, 'handle_successful_upload' ) );
	}

	/**
	 * Determines whether the current upload is a WordPress software upgrader package.
	 *
	 * WordPress uses `pluginzip` and `themezip` form fields in `File_Upload_Upgrader`
	 * for manual plugin/theme ZIP uploads and software updates.
	 *
	 * @return bool
	 */
	public static function should_bypass_quota() {
		if ( ! isset( $_FILES ) || ! is_array( $_FILES ) ) {
			return false;
		}

		foreach ( array( 'pluginzip', 'themezip' ) as $form_name ) {
			if ( isset( $_FILES[ $form_name ] ) && is_array( $_FILES[ $form_name ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether a single upload can fit within the configured storage limit.
	 *
	 * @param array<string, mixed> $file Uploaded file array.
	 * @return array<string, mixed>
	 */
	public static function check_upload_quota( $file ) {
		if ( ! is_array( $file ) ) {
			return $file;
		}

		if ( self::should_bypass_quota() ) {
			return $file;
		}

		if ( ! isset( $file['size'] ) || ! is_numeric( $file['size'] ) ) {
			return $file;
		}

		$upload_size = max( 0, (int) $file['size'] );
		if ( $upload_size <= 0 ) {
			return $file;
		}

		$status = Interbo_Storage_Scanner::get_quota_status();
		if ( ! is_array( $status ) || empty( $status['status'] ) ) {
			return $file;
		}

		if ( in_array( $status['status'], array( 'no_data', 'no_limit' ), true ) ) {
			return $file;
		}

		$limit_bytes = self::get_limit_bytes();
		if ( 0 === $limit_bytes ) {
			return $file;
		}

		$current_usage = self::get_current_usage_bytes();
		if ( $current_usage >= $limit_bytes ) {
			$file['error'] = __( 'De opslaglimiet van de website is bereikt. Verwijder bestanden of neem contact op met Interbo voor extra opslag voordat je nieuwe bestanden uploadt.', 'interbo' );
			return $file;
		}

		$projected_usage = $current_usage + $upload_size;
		if ( $projected_usage > $limit_bytes ) {
			$file['error'] = __( 'Deze upload kan niet worden voltooid omdat de opslaglimiet van de website zou worden overschreden. Verwijder bestanden of neem contact op met Interbo voor extra opslag.', 'interbo' );
			return $file;
		}

		return $file;
	}

	/**
	 * Increases the cached usage state after a successful upload.
	 *
	 * @param array<string, mixed> $file Uploaded file details.
	 * @return array<string, mixed>
	 */
	public static function handle_successful_upload( $file ) {
		if ( ! is_array( $file ) ) {
			return $file;
		}

		if ( self::should_bypass_quota() ) {
			return $file;
		}

		if ( ! empty( $file['error'] ) ) {
			return $file;
		}

		$upload_size = 0;
		if ( isset( $file['size'] ) && is_numeric( $file['size'] ) ) {
			$upload_size = max( 0, (int) $file['size'] );
		}

		if ( $upload_size <= 0 && ! empty( $file['file'] ) && is_string( $file['file'] ) && file_exists( $file['file'] ) ) {
			$size = @filesize( $file['file'] );
			if ( is_numeric( $size ) ) {
				$upload_size = max( 0, (int) $size );
			}
		}

		if ( $upload_size <= 0 ) {
			return $file;
		}

		$status = Interbo_Storage_Scanner::get_quota_status();
		if ( ! is_array( $status ) || ! isset( $status['status'] ) ) {
			return $file;
		}

		if ( 'no_data' === $status['status'] ) {
			return $file;
		}

		if ( ! self::increment_usage( $upload_size ) ) {
			return $file;
		}

		Interbo_Storage_Notifier::process_status_change();

		return $file;
	}

	/**
	 * Returns the configured storage limit in bytes.
	 *
	 * @return int
	 */
	public static function get_limit_bytes() {
		$settings = get_option( 'interbo_storage_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['storage_limit'] ) || ! is_numeric( $settings['storage_limit'] ) ) {
			return 0;
		}

		$limit_gb = (float) $settings['storage_limit'];
		if ( $limit_gb <= 0 ) {
			return 0;
		}

		return max( 1, (int) round( $limit_gb * 1024 * 1024 * 1024 ) );
	}

	/**
	 * Returns the current cached usage total in bytes.
	 *
	 * @return int
	 */
	public static function get_current_usage_bytes() {
		$usage = Interbo_Storage_Scanner::get_usage();
		if ( ! is_array( $usage ) || ! isset( $usage['total_bytes'] ) || ! is_numeric( $usage['total_bytes'] ) ) {
			return 0;
		}

		return max( 0, (int) $usage['total_bytes'] );
	}

	/**
	 * Adds the uploaded file size to the stored usage payload without a full scan.
	 *
	 * @param int $upload_size Upload size in bytes.
	 * @return bool
	 */
	public static function increment_usage( $upload_size ) {
		if ( ! is_numeric( $upload_size ) ) {
			return false;
		}

		$upload_size = max( 0, (int) $upload_size );
		if ( $upload_size <= 0 ) {
			return false;
		}

		$usage = Interbo_Storage_Scanner::get_usage();
		if ( ! is_array( $usage ) ) {
			$usage = array(
				'files_bytes'       => 0,
				'uploads_bytes'     => 0,
				'plugins_bytes'     => 0,
				'themes_bytes'      => 0,
				'other_files_bytes' => 0,
				'database_bytes'    => 0,
				'total_bytes'       => 0,
				'scanned_at'        => 0,
				'scan_status'       => 'partial',
			);
		}

		$updated_usage = array(
			'files_bytes'       => isset( $usage['files_bytes'] ) && is_numeric( $usage['files_bytes'] ) ? max( 0, (int) $usage['files_bytes'] ) : 0,
			'uploads_bytes'     => isset( $usage['uploads_bytes'] ) && is_numeric( $usage['uploads_bytes'] ) ? max( 0, (int) $usage['uploads_bytes'] ) : 0,
			'plugins_bytes'     => isset( $usage['plugins_bytes'] ) && is_numeric( $usage['plugins_bytes'] ) ? max( 0, (int) $usage['plugins_bytes'] ) : 0,
			'themes_bytes'      => isset( $usage['themes_bytes'] ) && is_numeric( $usage['themes_bytes'] ) ? max( 0, (int) $usage['themes_bytes'] ) : 0,
			'other_files_bytes' => isset( $usage['other_files_bytes'] ) && is_numeric( $usage['other_files_bytes'] ) ? max( 0, (int) $usage['other_files_bytes'] ) : 0,
			'database_bytes'    => isset( $usage['database_bytes'] ) && is_numeric( $usage['database_bytes'] ) ? max( 0, (int) $usage['database_bytes'] ) : 0,
			'total_bytes'       => isset( $usage['total_bytes'] ) && is_numeric( $usage['total_bytes'] ) ? max( 0, (int) $usage['total_bytes'] ) : 0,
			'scanned_at'        => isset( $usage['scanned_at'] ) && is_numeric( $usage['scanned_at'] ) ? (int) $usage['scanned_at'] : 0,
			'scan_status'       => isset( $usage['scan_status'] ) && is_string( $usage['scan_status'] ) ? $usage['scan_status'] : 'partial',
		);

		$updated_usage['files_bytes']       += $upload_size;
		$updated_usage['uploads_bytes']     += $upload_size;
		$updated_usage['total_bytes']       += $upload_size;
		$updated_usage['scanned_at']        = isset( $usage['scanned_at'] ) && is_numeric( $usage['scanned_at'] ) ? (int) $usage['scanned_at'] : 0;
		$updated_usage['scan_status']       = isset( $usage['scan_status'] ) && is_string( $usage['scan_status'] ) ? $usage['scan_status'] : 'partial';

		$updated = update_option( Interbo_Storage_Scanner::USAGE_OPTION_KEY, $updated_usage, false );
		if ( ! $updated && get_option( Interbo_Storage_Scanner::USAGE_OPTION_KEY, array() ) !== $updated_usage ) {
			return false;
		}

		return true;
	}
}
