<?php
/**
 * Storage settings configuration for the Interbo plugin.
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the storage settings page and handles storage-related configuration.
 */
class Interbo_Storage_Settings {
	/**
	 * Capability required to access the storage settings.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * WordPress option key used for configurable storage settings.
	 */
	const OPTION_KEY = 'interbo_storage_settings';

	/**
	 * Registers WordPress hooks for the storage settings page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_interbo_storage_scan', array( __CLASS__, 'handle_scan' ) );
	}

	/**
	 * Adds the Storage submenu page under the Interbo menu.
	 */
	public static function register_menu() {
		add_submenu_page(
			Interbo_Admin::MENU_SLUG,
			__( 'Opslag', 'interbo' ),
			__( 'Opslag', 'interbo' ),
			self::CAPABILITY,
			'interbo-storage',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Registers the storage settings using the WordPress Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'interbo_storage_options',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'interbo_storage_settings_section',
			__( 'Opslaginstellingen', 'interbo' ),
			array( __CLASS__, 'render_section_description' ),
			'interbo-storage'
		);

		add_settings_field(
			'interbo_storage_limit',
			__( 'Opslaglimiet', 'interbo' ),
			array( __CLASS__, 'render_storage_limit_field' ),
			'interbo-storage',
			'interbo_storage_settings_section'
		);

		add_settings_field(
			'interbo_customer_email',
			__( 'E-mailadres klant', 'interbo' ),
			array( __CLASS__, 'render_customer_email_field' ),
			'interbo-storage',
			'interbo_storage_settings_section'
		);

		add_settings_field(
			'interbo_notification_email',
			__( 'E-mailadres Interbo', 'interbo' ),
			array( __CLASS__, 'render_interbo_email_field' ),
			'interbo-storage',
			'interbo_storage_settings_section'
		);
	}

	/**
	 * Renders the section description.
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'Configureer de opslaginstellingen voor de Interbo-plugin.', 'interbo' ) . '</p>';
	}

	/**
	 * Returns the current storage settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$defaults = array(
			'storage_limit'   => '',
			'customer_email'  => '',
		);

		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Sanitizes and validates the submitted storage settings.
	 *
	 * @param mixed $input Submitted form value.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $input ) {
		$stored_settings = get_option( self::OPTION_KEY, array() );
		$sanitized       = is_array( $stored_settings ) ? $stored_settings : array();

		if ( ! is_array( $input ) ) {
			return $sanitized;
		}

		if ( array_key_exists( 'storage_limit', $input ) ) {
			$storage_limit = null;
			if ( ! is_scalar( $input['storage_limit'] ) ) {
				add_settings_error(
					self::OPTION_KEY,
					'interbo_storage_limit',
					__( 'De opslaglimiet moet een getal groter dan 0 zijn.', 'interbo' )
				);
			} else {
				$storage_limit = trim( wp_unslash( $input['storage_limit'] ) );
				if ( '' === $storage_limit ) {
					$sanitized['storage_limit'] = '';
					$storage_limit = null;
				}
			}

			if ( null !== $storage_limit && '' !== $storage_limit ) {
				$storage_limit = str_replace( ',', '.', $storage_limit );
				if ( is_numeric( $storage_limit ) && (float) $storage_limit > 0 ) {
					$sanitized['storage_limit'] = sanitize_text_field( $storage_limit );
				} else {
					add_settings_error(
						self::OPTION_KEY,
						'interbo_storage_limit',
						__( 'De opslaglimiet moet een getal groter dan 0 zijn.', 'interbo' )
					);
				}
			}
		}

		if ( array_key_exists( 'customer_email', $input ) ) {
			if ( ! is_scalar( $input['customer_email'] ) ) {
				add_settings_error(
					self::OPTION_KEY,
					'interbo_customer_email',
					__( 'Voer een geldig e-mailadres van de klant in.', 'interbo' )
				);
			} else {
				$customer_email = trim( wp_unslash( $input['customer_email'] ) );
			}

			if ( isset( $customer_email ) && '' !== $customer_email ) {
				$customer_email = sanitize_email( $customer_email );
				if ( is_email( $customer_email ) ) {
					$sanitized['customer_email'] = $customer_email;
				} else {
					add_settings_error(
						self::OPTION_KEY,
						'interbo_customer_email',
						__( 'Voer een geldig e-mailadres van de klant in.', 'interbo' )
					);
				}
			} elseif ( isset( $customer_email ) ) {
				$sanitized['customer_email'] = '';
			}
		}

		return $sanitized;
	}

	/**
	 * Renders the storage limit field.
	 */
	public static function render_storage_limit_field() {
		$settings = self::get_settings();
		$value = isset( $settings['storage_limit'] ) ? $settings['storage_limit'] : '';
		?>
		<input
			type="number"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[storage_limit]"
			id="interbo_storage_limit"
			value="<?php echo esc_attr( $value ); ?>"
			step="0.01"
			min="0.01"
			class="regular-text"
		/>
		<span class="description"><?php echo esc_html__( 'GB', 'interbo' ); ?></span>
		<p class="description">
			<?php echo esc_html__( 'Laat leeg om geen opslaglimiet op te geven. Gebruik een waarde groter dan 0 wanneer een limiet is ingesteld.', 'interbo' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the customer email field.
	 */
	public static function render_customer_email_field() {
		$settings = self::get_settings();
		$value = isset( $settings['customer_email'] ) ? $settings['customer_email'] : '';
		?>
		<input
			type="email"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[customer_email]"
			id="interbo_customer_email"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		/>
		<p class="description">
			<?php echo esc_html__( 'Leeg laten als er geen klant-e-mailadres bekend is.', 'interbo' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the read-only Interbo email field.
	 */
	public static function render_interbo_email_field() {
		?>
		<input
			type="text"
			value="<?php echo esc_attr( INTERBO_NOTIFICATION_EMAIL ); ?>"
			class="regular-text"
			readonly
			disabled
			aria-disabled="true"
		/>
		<p class="description">
			<?php echo esc_html__( 'Dit e-mailadres wordt door de Interbo-plugin beheerd en wordt niet in de database opgeslagen.', 'interbo' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the storage settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze pagina te bekijken.', 'interbo' ) );
		}

		$usage = Interbo_Storage_Scanner::get_usage();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Opslag', 'interbo' ); ?></h1>
			<?php self::render_scan_notice(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'interbo_storage_options' );
				do_settings_sections( 'interbo-storage' );
				submit_button();
				?>
			</form>
			<hr />
			<h2><?php echo esc_html__( 'Opslaggebruik', 'interbo' ); ?></h2>
			<?php self::render_usage( $usage ); ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( 'interbo_storage_scan' ); ?>
				<input type="hidden" name="action" value="interbo_storage_scan" />
				<?php submit_button( __( 'Opslag opnieuw berekenen', 'interbo' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles a manually requested storage scan.
	 */
	public static function handle_scan() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze actie uit te voeren.', 'interbo' ) );
		}

		check_admin_referer( 'interbo_storage_scan' );
		$result = Interbo_Storage_Scanner::scan_and_save();
		$args   = array( 'page' => 'interbo-storage' );

		if ( ! empty( $result['success'] ) ) {
			$args['interbo_scan'] = 'success';
		} else {
			$args['interbo_scan'] = 'error';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders the result of the last storage scan.
	 *
	 * @param mixed $usage Stored usage result.
	 */
	private static function render_usage( $usage ) {
		if ( ! is_array( $usage ) || empty( $usage['scanned_at'] ) ) {
			echo '<p>' . esc_html__( 'Er is nog geen opslagscan uitgevoerd.', 'interbo' ) . '</p>';
			return;
		}

		$files_bytes    = isset( $usage['files_bytes'] ) ? (int) $usage['files_bytes'] : 0;
		$database_bytes = isset( $usage['database_bytes'] ) ? (int) $usage['database_bytes'] : 0;
		$total_bytes    = isset( $usage['total_bytes'] ) ? (int) $usage['total_bytes'] : 0;
		?>
		<table class="widefat striped" style="max-width: 700px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Bestandsgrootte', 'interbo' ); ?></th>
					<td><?php echo esc_html( size_format( $files_bytes ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Databasegrootte', 'interbo' ); ?></th>
					<td><?php echo esc_html( size_format( $database_bytes ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Totaal gebruik', 'interbo' ); ?></th>
					<td><?php echo esc_html( size_format( $total_bytes ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Laatste scan', 'interbo' ); ?></th>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $usage['scanned_at'] ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
		if ( ! empty( $usage['scan_status'] ) && 'complete' !== $usage['scan_status'] ) {
			echo '<p class="description">' . esc_html__( 'De scan is gedeeltelijk uitgevoerd omdat niet alle bestanden of mappen konden worden gelezen.', 'interbo' ) . '</p>';
		}
	}

	/**
	 * Renders an admin notice after a scan redirect.
	 */
	private static function render_scan_notice() {
		if ( empty( $_GET['interbo_scan'] ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['interbo_scan'] ) );
		if ( 'success' === $status ) {
			add_settings_error( self::OPTION_KEY, 'interbo_scan_success', __( 'De opslag is opnieuw berekend.', 'interbo' ), 'updated' );
		} elseif ( 'error' === $status ) {
			add_settings_error( self::OPTION_KEY, 'interbo_scan_error', __( 'De opslag kon niet volledig worden berekend.', 'interbo' ), 'error' );
		}

		settings_errors( self::OPTION_KEY );
	}
}
