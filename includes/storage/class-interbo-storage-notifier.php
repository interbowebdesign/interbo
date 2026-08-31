<?php
/**
 * Sends notifications when the storage quota status changes.
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes storage quota status transitions and notifications.
 */
class Interbo_Storage_Notifier {
	/**
	 * Option key for the last processed notification status.
	 */
	const STATE_OPTION_KEY = 'interbo_storage_notification_state';

	/**
	 * Status ranking used for upward quota transitions.
	 */
	const STATUS_RANKS = array(
		'normal'   => 0,
		'warning'  => 1,
		'critical' => 2,
		'exceeded' => 3,
	);

	/**
	 * Processes the current quota status and sends a required notification.
	 *
	 * @return array{success: bool, mail_sent: bool, old_status: string|null, new_status: string, action: string, error: string|null}
	 */
	public static function process_status_change() {
		$quota_status = Interbo_Storage_Scanner::get_quota_status();
		$new_status   = isset( $quota_status['status'] ) ? $quota_status['status'] : 'no_data';
		$state        = get_option( self::STATE_OPTION_KEY, false );

		if ( ! in_array( $new_status, array_keys( self::STATUS_RANKS ), true ) ) {
			return self::result( true, false, self::get_previous_status( $state ), $new_status, 'none', null );
		}

		if ( ! is_array( $state ) || ! isset( $state['last_status'] ) || ! isset( self::STATUS_RANKS[ $state['last_status'] ] ) ) {
			if ( ! self::save_status( $new_status ) ) {
				return self::result( false, false, null, $new_status, 'baseline', __( 'De opslagnotificatiestatus kon niet worden opgeslagen.', 'interbo' ) );
			}

			return self::result( true, false, null, $new_status, 'baseline', null );
		}

		$old_status = $state['last_status'];
		$action     = self::get_action( $old_status, $new_status );
		if ( 'none' === $action ) {
			if ( ! self::save_status( $new_status ) ) {
				return self::result( false, false, $old_status, $new_status, 'none', __( 'De opslagnotificatiestatus kon niet worden opgeslagen.', 'interbo' ) );
			}

			return self::result( true, false, $old_status, $new_status, 'none', null );
		}

		$mail_sent = self::send_notification( $action, $quota_status );
		if ( ! $mail_sent ) {
			return self::result( false, false, $old_status, $new_status, $action, __( 'De opslagnotificatie kon niet worden verzonden.', 'interbo' ) );
		}

		if ( ! self::save_status( $new_status ) ) {
			return self::result( false, true, $old_status, $new_status, $action, __( 'De mail is verzonden, maar de opslagnotificatiestatus kon niet worden opgeslagen.', 'interbo' ) );
		}

		return self::result( true, true, $old_status, $new_status, $action, null );
	}

	/**
	 * Saves the last processed notification status and verifies persistence.
	 *
	 * @param string $status Status to save.
	 * @return bool
	 */
	private static function save_status( $status ) {
		$state   = array( 'last_status' => $status );
		$updated = update_option( self::STATE_OPTION_KEY, $state, false );

		if ( ! $updated && get_option( self::STATE_OPTION_KEY, array() ) !== $state ) {
			return false;
		}

		return true;
	}

	/**
	 * Determines the notification action for a status transition.
	 *
	 * @param string $old_status Previous processed status.
	 * @param string $new_status Current status.
	 * @return string
	 */
	private static function get_action( $old_status, $new_status ) {
		if ( 'normal' === $new_status && 'normal' !== $old_status ) {
			return 'recovery';
		}

		if ( self::STATUS_RANKS[ $new_status ] > self::STATUS_RANKS[ $old_status ] ) {
			return $new_status;
		}

		return 'none';
	}

	/**
	 * Sends a plain-text quota notification.
	 *
	 * @param string               $action Notification action.
	 * @param array<string, mixed> $quota_status Current quota status.
	 * @return bool
	 */
	private static function send_notification( $action, $quota_status ) {
		$settings       = get_option( 'interbo_storage_settings', array() );
		$customer_email = is_array( $settings ) && isset( $settings['customer_email'] ) ? sanitize_email( $settings['customer_email'] ) : '';
		$customer_email = is_email( $customer_email ) ? $customer_email : '';
		$recipient      = $customer_email ? $customer_email : INTERBO_NOTIFICATION_EMAIL;
		$headers        = $customer_email ? array( 'Cc: ' . INTERBO_NOTIFICATION_EMAIL ) : array();
		$site_name      = get_bloginfo( 'name' );
		$domain         = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $domain ) || ! is_string( $domain ) ) {
			$domain = home_url();
		}
		$status_labels  = array(
			'normal'   => __( 'Normaal', 'interbo' ),
			'warning'  => __( 'Waarschuwing', 'interbo' ),
			'critical' => __( 'Kritiek', 'interbo' ),
			'exceeded' => __( 'Opslaglimiet overschreden', 'interbo' ),
		);
		$status         = 'recovery' === $action ? 'normal' : $action;
		$used           = size_format( (int) $quota_status['used_bytes'] );
		$limit          = size_format( (int) $quota_status['limit_bytes'] );
		$remaining      = size_format( (int) $quota_status['remaining_bytes'] );
		$subject        = self::get_subject( $action, $site_name );
		$message        = sprintf(
			__( "Beste klant,\n\nVoor %1\$s (%2\$s) is momenteel %3\$s opslag in gebruik van een limiet van %4\$s.\nResterende ruimte: %5\$s.\nStatus: %6\$s.\n\nHet opslaggebruik valt weer binnen de normale marge.", 'interbo' ),
			$site_name,
			$domain,
			$used,
			$limit,
			$remaining,
			$status_labels[ $status ]
		);

		if ( 'recovery' !== $action ) {
			$upload_message = 'exceeded' === $action
				? __( "De opslaglimiet is bereikt. Koop extra opslag bij via Interbo of ruim bestanden en uploads op. Nieuwe uploads kunnen worden geblokkeerd totdat er weer voldoende opslagruimte beschikbaar is.", 'interbo' )
				: __( "Je kunt extra opslag bijkopen via Interbo of bestanden en uploads opruimen.\n\nHoud er rekening mee dat bij het bereiken van de opslaglimiet nieuwe uploads naar de website kunnen worden geblokkeerd totdat er weer voldoende opslagruimte beschikbaar is.", 'interbo' );
			$message = sprintf(
				__( "Beste klant,\n\nVoor %1\$s (%2\$s) is momenteel %3\$s opslag in gebruik van een limiet van %4\$s.\nResterende ruimte: %5\$s.\nStatus: %6\$s.\n\n%7\$s", 'interbo' ),
				$site_name,
				$domain,
				$used,
				$limit,
				$remaining,
				$status_labels[ $status ],
				$upload_message
			);
		}

		return wp_mail( $recipient, $subject, $message, $headers );
	}

	/**
	 * Creates the notification subject.
	 *
	 * @param string $action Notification action.
	 * @param string $site_name Site name.
	 * @return string
	 */
	private static function get_subject( $action, $site_name ) {
		$subjects = array(
			'warning'  => __( 'Opslagwaarschuwing voor %s', 'interbo' ),
			'critical' => __( 'Kritieke opslagwaarschuwing voor %s', 'interbo' ),
			'exceeded' => __( 'Opslaglimiet bereikt voor %s', 'interbo' ),
			'recovery' => __( 'Opslaggebruik weer normaal voor %s', 'interbo' ),
		);

		return isset( $subjects[ $action ] ) ? sprintf( $subjects[ $action ], $site_name ) : '';
	}

	/**
	 * Returns a normalized previous status.
	 *
	 * @param mixed $state Stored notification state.
	 * @return string|null
	 */
	private static function get_previous_status( $state ) {
		return is_array( $state ) && isset( $state['last_status'] ) && is_string( $state['last_status'] ) ? $state['last_status'] : null;
	}

	/**
	 * Creates a consistent process result.
	 *
	 * @param bool        $success Process result.
	 * @param bool        $mail_sent Whether mail was sent.
	 * @param string|null $old_status Previous status.
	 * @param string      $new_status Current status.
	 * @param string      $action Process action.
	 * @param string|null $error Error message.
	 * @return array{success: bool, mail_sent: bool, old_status: string|null, new_status: string, action: string, error: string|null}
	 */
	private static function result( $success, $mail_sent, $old_status, $new_status, $action, $error ) {
		return array(
			'success'    => $success,
			'mail_sent'  => $mail_sent,
			'old_status' => $old_status,
			'new_status' => $new_status,
			'action'     => $action,
			'error'      => $error,
		);
	}
}
