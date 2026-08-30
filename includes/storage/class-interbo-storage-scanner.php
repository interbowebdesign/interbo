<?php
/**
 * Measures WordPress file and database storage usage.
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans and stores the current WordPress storage usage.
 */
class Interbo_Storage_Scanner {
	/**
	 * Quota threshold at which usage becomes a warning.
	 */
	const WARNING_THRESHOLD = 80;

	/**
	 * Quota threshold at which usage becomes critical.
	 */
	const CRITICAL_THRESHOLD = 90;

	/**
	 * Quota threshold at which usage is exceeded.
	 */
	const EXCEEDED_THRESHOLD = 100;

	/**
	 * Option key for the last storage scan result.
	 */
	const USAGE_OPTION_KEY = 'interbo_storage_usage';

	/**
	 * Runs a scan and stores it only when both measurements are available.
	 *
	 * @return array{success: bool, result: array<string, mixed>|null, error: string|null}
	 */
	public static function scan_and_save() {
		$file_result     = self::measure_files();
		$database_result = self::measure_database();

		if ( empty( $file_result['success'] ) || empty( $database_result['success'] ) ) {
			$error_messages = array_filter(
				array(
					isset( $file_result['error'] ) ? $file_result['error'] : null,
					isset( $database_result['error'] ) ? $database_result['error'] : null,
				)
			);

			return array(
				'success' => false,
				'result'  => null,
				'error'   => implode( ' ', $error_messages ),
			);
		}

		$usage = array(
			'files_bytes'       => (int) $file_result['files_bytes'],
			'uploads_bytes'     => (int) $file_result['uploads_bytes'],
			'plugins_bytes'     => (int) $file_result['plugins_bytes'],
			'themes_bytes'      => (int) $file_result['themes_bytes'],
			'other_files_bytes' => (int) $file_result['other_files_bytes'],
			'database_bytes'    => (int) $database_result['bytes'],
			'total_bytes'       => (int) $file_result['files_bytes'] + (int) $database_result['bytes'],
			'scanned_at'        => time(),
			'scan_status'       => ! empty( $file_result['complete'] ) ? 'complete' : 'partial',
		);

		$updated = update_option( self::USAGE_OPTION_KEY, $usage, false );
		if ( ! $updated && get_option( self::USAGE_OPTION_KEY, array() ) !== $usage ) {
			return array(
				'success' => false,
				'result'  => null,
				'error'   => __( 'Het opslagscanresultaat kon niet worden opgeslagen.', 'interbo' ),
			);
		}

		return array(
			'success' => true,
			'result'  => $usage,
			'error'   => null,
		);
	}

	/**
	 * Returns the last stored storage scan.
	 *
	 * @return array<string, mixed>|false
	 */
	public static function get_usage() {
		$usage = get_option( self::USAGE_OPTION_KEY, false );

		return is_array( $usage ) ? $usage : false;
	}

	/**
	 * Calculates the current storage quota status from usage and settings.
	 *
	 * @return array{status: string, used_bytes: int|null, limit_bytes: int|null, remaining_bytes: int|null, percentage: float|null}
	 */
	public static function get_quota_status() {
		$usage = self::get_usage();
		if ( ! is_array( $usage ) || ! isset( $usage['total_bytes'] ) || ! is_numeric( $usage['total_bytes'] ) ) {
			return array(
				'status'          => 'no_data',
				'used_bytes'      => null,
				'limit_bytes'     => null,
				'remaining_bytes' => null,
				'percentage'      => null,
			);
		}

		$used_bytes = max( 0, (int) $usage['total_bytes'] );
		$settings   = get_option( 'interbo_storage_settings', array() );
		$limit_gb   = is_array( $settings ) && isset( $settings['storage_limit'] ) && is_numeric( $settings['storage_limit'] ) ? (float) $settings['storage_limit'] : 0;

		if ( $limit_gb <= 0 ) {
			return array(
				'status'          => 'no_limit',
				'used_bytes'      => $used_bytes,
				'limit_bytes'     => null,
				'remaining_bytes' => null,
				'percentage'      => null,
			);
		}

		$limit_bytes = max( 1, (int) round( $limit_gb * 1024 * 1024 * 1024 ) );
		$percentage  = ( $used_bytes / $limit_bytes ) * 100;
		$status      = 'normal';

		if ( $used_bytes * 100 >= $limit_bytes * self::EXCEEDED_THRESHOLD ) {
			$status = 'exceeded';
		} elseif ( $used_bytes * 100 >= $limit_bytes * self::CRITICAL_THRESHOLD ) {
			$status = 'critical';
		} elseif ( $used_bytes * 100 >= $limit_bytes * self::WARNING_THRESHOLD ) {
			$status = 'warning';
		}

		return array(
			'status'          => $status,
			'used_bytes'      => $used_bytes,
			'limit_bytes'     => $limit_bytes,
			'remaining_bytes' => max( 0, $limit_bytes - $used_bytes ),
			'percentage'      => $percentage,
		);
	}

	/**
	 * Measures every regular file below the WordPress root without following symlinks.
	 *
	 * @return array{success: bool, files_bytes: int, uploads_bytes: int, plugins_bytes: int, themes_bytes: int, other_files_bytes: int, complete: bool, error: string|null}
	 */
	private static function measure_files() {
		$root = rtrim( ABSPATH, DIRECTORY_SEPARATOR );
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			return array(
				'success'  => false,
				'bytes'    => 0,
				'complete' => false,
				'error'    => __( 'De WordPress-root kon niet worden geopend.', 'interbo' ),
			);
		}

		$category_paths = self::get_category_paths();
		$directories    = array( $root );
		$files_bytes    = 0;
		$category_bytes = array(
			'uploads_bytes'     => 0,
			'plugins_bytes'     => 0,
			'themes_bytes'      => 0,
			'other_files_bytes' => 0,
		);
		$complete = true;

		while ( ! empty( $directories ) ) {
			$directory = array_pop( $directories );
			$is_root   = ( $root === $directory );

			try {
				$iterator = new DirectoryIterator( $directory );
			} catch ( Throwable $exception ) {
				if ( $is_root ) {
					return array(
						'success'           => false,
						'files_bytes'       => 0,
						'uploads_bytes'     => 0,
						'plugins_bytes'     => 0,
						'themes_bytes'      => 0,
						'other_files_bytes' => 0,
						'complete'          => false,
						'error'             => __( 'De WordPress-root kon niet worden geopend.', 'interbo' ),
					);
				}

				$complete = false;
				continue;
			}

			try {
				foreach ( $iterator as $item ) {
					try {
						if ( $item->isDot() ) {
							continue;
						}

						$path = $item->getPathname();
						if ( $item->isLink() ) {
							continue;
						}

						if ( $item->isDir() ) {
							$directories[] = $path;
						} elseif ( $item->isFile() ) {
							$file_size = $item->getSize();
							if ( false === $file_size ) {
								$complete = false;
								continue;
							}

							$file_size = (int) $file_size;
							$file_path = wp_normalize_path( $path );
							$category  = self::get_file_category( $file_path, $category_paths );

							$files_bytes                += $file_size;
							$category_bytes[ $category ] += $file_size;
						}
					} catch ( Throwable $exception ) {
						$complete = false;
					}
				}
			} catch ( Throwable $exception ) {
				if ( $is_root ) {
					return array(
						'success'           => false,
						'files_bytes'       => 0,
						'uploads_bytes'     => 0,
						'plugins_bytes'     => 0,
						'themes_bytes'      => 0,
						'other_files_bytes' => 0,
						'complete'          => false,
						'error'             => __( 'De WordPress-root kon niet volledig worden gelezen.', 'interbo' ),
					);
				}

				$complete = false;
			}
		}

		return array(
			'success'           => true,
			'files_bytes'       => $files_bytes,
			'uploads_bytes'     => $category_bytes['uploads_bytes'],
			'plugins_bytes'     => $category_bytes['plugins_bytes'],
			'themes_bytes'      => $category_bytes['themes_bytes'],
			'other_files_bytes' => $category_bytes['other_files_bytes'],
			'complete'          => $complete,
			'error'             => null,
		);
	}

	/**
	 * Returns normalized category directories.
	 *
	 * @return array<string, string>
	 */
	private static function get_category_paths() {
		$upload_dir      = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$uploads_basedir = is_array( $upload_dir ) && ! empty( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';
		$paths = array(
			'uploads_bytes' => $uploads_basedir,
			'plugins_bytes' => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'themes_bytes'  => function_exists( 'get_theme_root' ) ? get_theme_root() : '',
		);

		foreach ( $paths as $key => $path ) {
			$path = is_string( $path ) ? trim( $path ) : '';
			$paths[ $key ] = '' !== $path ? trailingslashit( wp_normalize_path( $path ) ) : '';
		}

		return $paths;
	}

	/**
	 * Returns the category for a normalized file path.
	 *
	 * @param string                $file_path      Normalized file path.
	 * @param array<string, string> $category_paths Normalized category paths.
	 * @return string
	 */
	private static function get_file_category( $file_path, $category_paths ) {
		foreach ( array( 'uploads_bytes', 'plugins_bytes', 'themes_bytes' ) as $category ) {
			if ( '' !== $category_paths[ $category ] && 0 === strpos( $file_path, $category_paths[ $category ] ) ) {
				return $category;
			}
		}

		return 'other_files_bytes';
	}

	/**
	 * Measures all WordPress tables matching the configured table prefix.
	 *
	 * @return array{success: bool, bytes: int, error: string|null}
	 */
	private static function measure_database() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
			return array(
				'success' => false,
				'bytes'   => 0,
				'error'   => __( 'De databasegrootte kon niet worden gemeten.', 'interbo' ),
			);
		}

		$table_pattern = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables        = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table_pattern ) );
		if ( ! is_array( $tables ) || ! empty( $wpdb->last_error ) ) {
			return array(
				'success' => false,
				'bytes'   => 0,
				'error'   => __( 'De databasegrootte kon niet worden gemeten.', 'interbo' ),
			);
		}

		$bytes = 0;
		foreach ( $tables as $table ) {
			if ( ! is_object( $table ) || ! isset( $table->Data_length, $table->Index_length ) ) {
				return array(
					'success' => false,
					'bytes'   => 0,
					'error'   => __( 'De databasegrootte kon niet volledig worden vastgesteld.', 'interbo' ),
				);
			}

			$bytes += (int) $table->Data_length + (int) $table->Index_length;
		}

		return array(
			'success' => true,
			'bytes'   => $bytes,
			'error'   => null,
		);
	}
}