<?php
/**
 * Plugin Name: JC Logs
 * Description: A plugin to handle custom logs in WordPress, implementing PSR-3.
 * Version: 1.0.0
 * Author: José Conti
 * Author URI: https://plugins.joseconti.com
 * Text Domain: jc-logs
 * Network: true
 * Domain Path: /languages
 * Requires at least: 5.2
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package JC_Logs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( class_exists( 'JC_Logs\\JC_Log' ) ) {
	return;
}

require_once 'includes/Psr/Log/logger-interface.php';
require_once 'includes/Psr/Log/class-loglevel.php';
require_once 'classes/class-jc-log.php';
require_once 'classes/class-jc-log-admin.php';

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
