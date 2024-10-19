<?php
/**
 * Plugin Name: JC Logs
 * Description: A plugin to handle custom logs in WordPress, implementing PSR-3.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: jc-logs
 * Network: true
 * Domain Path: /languages
 *
 * @package JC_Logs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'JC_LOGS_VERSION', '1.0.0' );
define( 'JC_LOGS_PATH', plugin_dir_path( __FILE__ ) );
define( 'JC_LOGS_URL', plugin_dir_url( __FILE__ ) );


require_once JC_LOGS_PATH . '/includes/Psr/Log/logger-interface.php';
require_once JC_LOGS_PATH . '/includes/Psr/Log/class-loglevel.php';
require_once JC_LOGS_PATH . '/classes/class-jc-log.php';
require_once JC_LOGS_PATH . '/classes/class-jc-log-admin.php';

// Initialize the plugin after WordPress has fully loaded.
add_action( 'plugins_loaded', 'initialize_jc_logs', 20 );

/**
 * Initialize the JC Logs plugin.
 */
function initialize_jc_logs() {
	// Require the necessary class files immediately.
	JC_Logs\JC_Log_Admin::get_instance();
}

// Activación y desactivación del plugin.
register_activation_hook( __FILE__, array( 'JC_Logs\\JC_Log', 'activate' ) );
