<?php
/**
 * Plugin Name:       Interbo
 * Description:       Basisplugin voor Interbo Webdesign.
 * Version:           0.1.0
 * Author:            Interbo Webdesign
 * Text Domain:       interbo
 *
 * @package Interbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INTERBO_PLUGIN_VERSION', '0.1.0' );

if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-interbo-admin.php';

	Interbo_Admin::init();
}
