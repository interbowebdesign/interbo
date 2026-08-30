<?php
/**
 * Admin functionality for the Interbo plugin.
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Interbo admin page.
 */
class Interbo_Admin {
	/**
	 * Capability required to access the Interbo admin page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Menu slug for the Interbo admin page.
	 */
	const MENU_SLUG = 'interbo';

	/**
	 * Registers WordPress admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Adds the Interbo top-level admin menu.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Interbo', 'interbo' ),
			__( 'Interbo', 'interbo' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-admin-generic'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Overzicht', 'interbo' ),
			__( 'Overzicht', 'interbo' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renders the Interbo admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze pagina te bekijken.', 'interbo' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Interbo', 'interbo' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: Plugin version number. */
					esc_html__( 'Versie %s', 'interbo' ),
					esc_html( INTERBO_PLUGIN_VERSION )
				);
				?>
			</p>
			<p><?php echo esc_html__( 'Interbo plugin is actief.', 'interbo' ); ?></p>
		</div>
		<?php
	}
}
