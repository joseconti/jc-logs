<?php
/*
Plugin Name: JC Logs
Description: Librería para generar y gestionar logs en WordPress.
Version: 1.0.0
Author: Tu Nombre
Text Domain: jc-logs
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

// Definir una constante para la ruta del plugin.
define( 'JC_LOGS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Incluir las clases.
require_once JC_LOGS_PLUGIN_DIR . 'classes/class-jc-log.php';
require_once JC_LOGS_PLUGIN_DIR . 'classes/class-jc-log-admin.php';

// Inicializar el plugin.
function jc_logs_init() {
	// Inicializar el singleton del logger.
	\JC_Logs\JC_Log::get_instance();

	// Inicializar la administración solo en el área de administración.
	if ( is_admin() ) {
		\JC_Logs\JC_Log_Admin::get_instance();
	}
}
add_action( 'plugins_loaded', 'jc_logs_init', 5 ); // Prioridad baja para cargar antes que otros plugins.
