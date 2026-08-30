<?php
/**
 * Plugin Name:       Interbo
 * Description:       Basisplugin voor Interbo Webdesign.
 * Version:           0.2.3
 * Author:            Interbo Webdesign
 * Text Domain:       interbo
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INTERBO_PLUGIN_VERSION', '0.2.3' );
define( 'INTERBO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-interbo-update-checker.php';
Interbo_Update_Checker::init();

if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-interbo-admin.php';

	Interbo_Admin::init();
}
