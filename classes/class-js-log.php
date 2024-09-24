<?php
namespace JC_Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

class JC_Log {

	private static $instance = null;

	private $log_directory;
	private $security_token;

	private function __construct() {
		// Inicializar variables dependientes de WordPress.
		add_action( 'init', array( $this, 'initialize' ) );
	}

	/**
	 * Obtener la instancia única de la clase.
	 *
	 * @return JC_Log La instancia única de JC_Log.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Inicializar el directorio de logs y el token de seguridad.
	 */
	public function initialize() {
		$upload_dir           = wp_upload_dir();
		$this->log_directory  = trailingslashit( $upload_dir['basedir'] ) . 'jc-logs/';
		$this->security_token = wp_hash( 'jc_logs_security' );

		// Crear el directorio de logs si no existe.
		if ( ! file_exists( $this->log_directory ) ) {
			wp_mkdir_p( $this->log_directory );
		}
	}

	/**
	 * Función para registrar un log.
	 *
	 * @param string $log_name Nombre del log.
	 * @param string $text     Texto del log.
	 * @param string $type     Tipo de log: Info, warning, error, critical.
	 */
	public function log( $log_name, $text, $type = 'Info' ) {
		// Verificar que el directorio y el token estén inicializados.
		if ( empty( $this->log_directory ) || empty( $this->security_token ) ) {
			$this->initialize();
		}

		$allowed_types = array( 'Info', 'warning', 'error', 'critical' );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'Info';
		}

		$log_name      = sanitize_file_name( $log_name );
		$date          = gmdate( 'Y-m-d' );
		$random_string = substr( $this->security_token, 0, 10 );
		$file_name     = "{$log_name}-{$date}-{$random_string}.log";
		$file_path     = $this->log_directory . $file_name;

		// Verificar el tamaño del archivo (límite de 5MB).
		if ( file_exists( $file_path ) && filesize( $file_path ) > 5 * MB_IN_BYTES ) {
			$version = 1;
			do {
				$file_name = "{$log_name}-{$version}-{$date}-{$random_string}.log";
				$file_path = $this->log_directory . $file_name;
				++$version;
			} while ( file_exists( $file_path ) && filesize( $file_path ) > 5 * MB_IN_BYTES );
		}

		$current_time = current_time( 'c' );
		$log_entry    = "{$current_time} {$type} {$text}" . PHP_EOL;
		file_put_contents( $file_path, $log_entry, FILE_APPEND | LOCK_EX );
	}

	// Otros métodos relacionados con logging pueden ser añadidos aquí.
}
