<?php
/**
 * Plugin Name: JC Logs
 * Description: A plugin to handle custom logs in WordPress, implementing PSR-3.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: jc-logs
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'plugins_loaded', 'initialize_jc_logs', 1 );
/**
 * Initialize the JC Logs plugin.
 */
function initialize_jc_logs() {

	require_once plugin_dir_path( __FILE__ ) . 'includes/Psr/Log/LoggerInterface.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/Psr/Log/LogLevel.php';
	require_once plugin_dir_path( __FILE__ ) . 'classes/class-jc-log.php';
	require_once plugin_dir_path( __FILE__ ) . 'classes/class-jc-log-admin.php';

	JC_Logs\JC_Log_Admin::get_instance();
}

// Activación y desactivación del plugin.
register_activation_hook( __FILE__, array( 'JC_Logs\JC_Log', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JC_Logs\JC_Log', 'deactivate' ) );
