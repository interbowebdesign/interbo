<?php
/**
 * Background GitHub release update checker for the Interbo plugin.
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the latest GitHub release and compares it with the locally installed plugin version.
 */
class Interbo_Update_Checker {
	/**
	 * GitHub endpoint for the latest published release.
	 *
	 * @var string
	 */
	const GITHUB_LATEST_RELEASE_URL = 'https://api.github.com/repos/interbowebdesign/interbo/releases/latest';

	/**
	 * Name of the transient used to cache the release result.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'interbo_github_latest_release';

	/**
	 * Cache lifetime for successful release checks.
	 *
	 * @var int
	 */
	const CACHE_DURATION = 12 * HOUR_IN_SECONDS;

	/**
	 * Cache lifetime for failed release checks.
	 *
	 * @var int
	 */
	const FAILURE_CACHE_DURATION = 15 * MINUTE_IN_SECONDS;

	/**
	 * Registers WordPress update hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_plugin_update_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugin_information' ), 10, 3 );
	}

	/**
	 * Supplies plugin metadata for the standard WordPress plugin information modal.
	 *
	 * @param false|object $result The plugin information result.
	 * @param string       $action The type of information being requested.
	 * @param object       $args   Plugin API arguments.
	 * @return false|object
	 */
	public static function filter_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! is_object( $args ) || empty( $args->slug ) || 'interbo' !== sanitize_key( $args->slug ) ) {
			return $result;
		}

		$status = self::get_status();
		$github_version = ! empty( $status['github_version'] ) ? $status['github_version'] : INTERBO_PLUGIN_VERSION;

		return (object) array(
			'slug'          => 'interbo',
			'name'          => 'Interbo',
			'version'       => $github_version,
			'author'        => '<a href="https://interbo.nl">Interbo Webdesign</a>',
			'homepage'      => 'https://github.com/interbowebdesign/interbo',
			'plugin'        => 'interbo/interbo.php',
			'sections'      => array(
				'description' => 'Interbo is a WordPress plugin developed by Interbo Webdesign.',
			),
		);
	}

	/**
	 * Fills the WordPress plugin update transient when a newer GitHub release is available.
	 *
	 * @param object|false $value Existing plugin update transient value.
	 * @return object|false
	 */
	public static function filter_plugin_update_transient( $value ) {
		if ( ! is_object( $value ) ) {
			$value = new stdClass();
		}

		if ( ! isset( $value->response ) || ! is_array( $value->response ) ) {
			$value->response = array();
		}

		$status = self::get_status();
		if ( 'update_available' !== $status['status'] ) {
			return $value;
		}

		$plugin_basename = self::get_plugin_basename();
		if ( empty( $plugin_basename ) ) {
			return $value;
		}

		$value->response[ $plugin_basename ] = (object) array(
			'slug'          => 'interbo',
			'plugin'        => $plugin_basename,
			'new_version'   => $status['github_version'],
			'url'           => 'https://github.com/interbowebdesign/interbo',
			'package'       => '',
			'tested'        => '',
			'requires_php'  => '',
		);

		return $value;
	}

	/**
	 * Returns the plugin basename for the plugin update transient.
	 *
	 * @return string
	 */
	private static function get_plugin_basename() {
		if ( defined( 'INTERBO_PLUGIN_BASENAME' ) && ! empty( INTERBO_PLUGIN_BASENAME ) ) {
			return INTERBO_PLUGIN_BASENAME;
		}

		return plugin_basename( dirname( __DIR__ ) . '/interbo.php' );
	}

	/**
	 * Returns the latest cached or freshly fetched update-check result.
	 *
	 * @return array{
	 *   status: string,
	 *   current_version: string|null,
	 *   github_version: string|null,
	 *   github_tag: string|null,
	 *   message: string|null,
	 *   update_available: bool
	 * }
	 */
	public static function get_status() {
		$result = get_transient( self::TRANSIENT_KEY );

		if ( false === $result ) {
			$result = self::fetch_latest_release_status();
			$cache_duration = ( 'update_check_failed' === $result['status'] ) ? self::FAILURE_CACHE_DURATION : self::CACHE_DURATION;
			set_transient( self::TRANSIENT_KEY, $result, $cache_duration );
		}

		return self::normalize_result( $result );
	}

	/**
	 * Checks whether a newer GitHub release is available.
	 *
	 * @return bool
	 */
	public static function is_update_available() {
		$status = self::get_status();

		return ! empty( $status['update_available'] );
	}

	/**
	 * Fetches the latest GitHub release and compares it against the local plugin version.
	 *
	 * @return array{
	 *   status: string,
	 *   current_version: string|null,
	 *   github_version: string|null,
	 *   github_tag: string|null,
	 *   message: string|null,
	 *   update_available: bool
	 * }
	 */
	public static function fetch_latest_release_status() {
		$response = wp_remote_get(
			self::GITHUB_LATEST_RELEASE_URL,
			array(
				'timeout'    => 10,
				'headers'    => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Interbo/' . INTERBO_PLUGIN_VERSION . ' (+https://interbo.nl)',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'          => 'update_check_failed',
				'current_version' => self::normalize_version( INTERBO_PLUGIN_VERSION ),
				'github_version'  => null,
				'github_tag'      => null,
				'message'         => $response->get_error_message(),
				'update_available' => false,
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return array(
				'status'          => 'update_check_failed',
				'current_version' => self::normalize_version( INTERBO_PLUGIN_VERSION ),
				'github_version'  => null,
				'github_tag'      => null,
				'message'         => sprintf( 'Unexpected GitHub HTTP status: %d.', absint( $status_code ) ),
				'update_available' => false,
			);
		}

		$raw_body = wp_remote_retrieve_body( $response );
		if ( empty( $raw_body ) ) {
			return array(
				'status'          => 'update_check_failed',
				'current_version' => self::normalize_version( INTERBO_PLUGIN_VERSION ),
				'github_version'  => null,
				'github_tag'      => null,
				'message'         => 'GitHub API response body is empty.',
				'update_available' => false,
			);
		}

		$data = json_decode( $raw_body );
		if ( ! is_object( $data ) ) {
			return array(
				'status'          => 'update_check_failed',
				'current_version' => self::normalize_version( INTERBO_PLUGIN_VERSION ),
				'github_version'  => null,
				'github_tag'      => null,
				'message'         => 'GitHub API response was not valid JSON.',
				'update_available' => false,
			);
		}

		$tag_name = isset( $data->tag_name ) ? $data->tag_name : '';
		if ( ! is_string( $tag_name ) || '' === trim( $tag_name ) ) {
			return array(
				'status'          => 'update_check_failed',
				'current_version' => self::normalize_version( INTERBO_PLUGIN_VERSION ),
				'github_version'  => null,
				'github_tag'      => null,
				'message'         => 'GitHub release is missing a valid tag_name.',
				'update_available' => false,
			);
		}

		$github_version = self::normalize_version( $tag_name );
		if ( null === $github_version ) {
			return array(
				'status'          => 'update_check_failed',
				'current_version' => self::normalize_version( INTERBO_PLUGIN_VERSION ),
				'github_version'  => null,
				'github_tag'      => $tag_name,
				'message'         => 'GitHub release tag is in an unexpected format.',
				'update_available' => false,
			);
		}

		$current_version = self::normalize_version( INTERBO_PLUGIN_VERSION );
		if ( null === $current_version ) {
			return array(
				'status'          => 'update_check_failed',
				'current_version' => null,
				'github_version'  => $github_version,
				'github_tag'      => $tag_name,
				'message'         => 'Local plugin version is invalid.',
				'update_available' => false,
			);
		}

		$update_available = version_compare( $current_version, $github_version, '<' );

		return array(
			'status'          => $update_available ? 'update_available' : 'no_update',
			'current_version' => $current_version,
			'github_version'  => $github_version,
			'github_tag'      => $tag_name,
			'message'         => null,
			'update_available' => $update_available,
		);
	}

	/**
	 * Normalizes a version string so it can be compared consistently.
	 *
	 * @param string $version Raw version or tag.
	 * @return string|null
	 */
	private static function normalize_version( $version ) {
		if ( ! is_string( $version ) ) {
			return null;
		}

		$version = trim( $version );
		if ( '' === $version ) {
			return null;
		}

		$version = preg_replace( '/^v/i', '', $version );
		$version = trim( $version );

		if ( ! preg_match( '/^\d+(?:\.\d+){1,2}(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) {
			return null;
		}

		return $version;
	}

	/**
	 * Makes sure all result arrays expose the same structure.
	 *
	 * @param mixed $result Incoming transient result.
	 * @return array{
	 *   status: string,
	 *   current_version: string|null,
	 *   github_version: string|null,
	 *   github_tag: string|null,
	 *   message: string|null,
	 *   update_available: bool
	 * }
	 */
	private static function normalize_result( $result ) {
		if ( ! is_array( $result ) ) {
			return array(
				'status'          => 'update_check_failed',
				'current_version' => self::normalize_version( INTERBO_PLUGIN_VERSION ),
				'github_version'  => null,
				'github_tag'      => null,
				'message'         => 'GitHub release result is invalid.',
				'update_available' => false,
			);
		}

		return array(
			'status'          => isset( $result['status'] ) ? sanitize_key( $result['status'] ) : 'update_check_failed',
			'current_version' => isset( $result['current_version'] ) ? self::normalize_version( $result['current_version'] ) : self::normalize_version( INTERBO_PLUGIN_VERSION ),
			'github_version'  => isset( $result['github_version'] ) ? self::normalize_version( $result['github_version'] ) : null,
			'github_tag'      => isset( $result['github_tag'] ) ? sanitize_text_field( $result['github_tag'] ) : null,
			'message'         => isset( $result['message'] ) ? sanitize_text_field( $result['message'] ) : null,
			'update_available' => ! empty( $result['update_available'] ),
		);
	}
}
