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
			'files_bytes'    => (int) $file_result['bytes'],
			'database_bytes' => (int) $database_result['bytes'],
			'total_bytes'    => (int) $file_result['bytes'] + (int) $database_result['bytes'],
			'scanned_at'     => time(),
			'scan_status'    => ! empty( $file_result['complete'] ) ? 'complete' : 'partial',
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
	 * Measures every regular file below the WordPress root without following symlinks.
	 *
	 * @return array{success: bool, bytes: int, complete: bool, error: string|null}
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

		$directories = array( $root );
		$bytes       = 0;
		$complete    = true;

		while ( ! empty( $directories ) ) {
			$directory = array_pop( $directories );
			$is_root   = ( $root === $directory );

			try {
				$iterator = new DirectoryIterator( $directory );
			} catch ( Throwable $exception ) {
				if ( $is_root ) {
					return array(
						'success'  => false,
						'bytes'    => 0,
						'complete' => false,
						'error'    => __( 'De WordPress-root kon niet worden geopend.', 'interbo' ),
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

							$bytes += (int) $file_size;
						}
					} catch ( Throwable $exception ) {
						$complete = false;
					}
				}
			} catch ( Throwable $exception ) {
				if ( $is_root ) {
					return array(
						'success'  => false,
						'bytes'    => 0,
						'complete' => false,
						'error'    => __( 'De WordPress-root kon niet volledig worden gelezen.', 'interbo' ),
					);
				}

				$complete = false;
			}
		}

		return array(
			'success'  => true,
			'bytes'    => $bytes,
			'complete' => $complete,
			'error'    => null,
		);
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