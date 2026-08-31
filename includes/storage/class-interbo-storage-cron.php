<?php
/**
 * Schedules and executes the daily storage scan for the Interbo plugin.
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the daily storage scan hook.
 */
class Interbo_Storage_Cron {
	/**
	 * Hook name for the daily storage scan.
	 */
	const EVENT_HOOK = 'interbo_storage_daily_scan';

	/**
	 * Register the cron callback.
	 */
	public static function init() {
		add_action( self::EVENT_HOOK, array( __CLASS__, 'run_daily_scan' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Ensures the daily storage scan event exists for active installs.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::EVENT_HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedules the daily scan unless it is already queued.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::EVENT_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT_HOOK );
		}
	}

	/**
	 * Clears the scheduled daily scan event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::EVENT_HOOK );
	}

	/**
	 * Runs the full storage scan and only triggers the notification flow on success.
	 */
	public static function run_daily_scan() {
		$result = Interbo_Storage_Scanner::scan_and_save();
		if ( empty( $result['success'] ) ) {
			return;
		}

		Interbo_Storage_Notifier::process_status_change();
	}
}
