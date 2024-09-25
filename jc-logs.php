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

// Include necessary files.
require_once plugin_dir_path( __FILE__ ) . 'includes/Psr/Log/LoggerInterface.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Psr/Log/LogLevel.php';
require_once plugin_dir_path( __FILE__ ) . 'classes/class-jc-log.php';
require_once plugin_dir_path( __FILE__ ) . 'classes/class-jc-log-admin.php';

// Initialize classes.
JC_Logs\JC_Log::get_instance();
JC_Logs\JC_Log_Admin::get_instance();

// Activation hook to create database table.
register_activation_hook( __FILE__, array( 'JC_Logs\JC_Log', 'activate' ) );

// Deactivation hook to clean up.
register_deactivation_hook( __FILE__, array( 'JC_Logs\JC_Log', 'deactivate' ) );
