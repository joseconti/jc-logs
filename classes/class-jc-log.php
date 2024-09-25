<?php
namespace JC_Logs;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

class JC_Log implements LoggerInterface {

	private $log_directory;
	private $security_token;
	private $log_name = 'default'; // Nombre de log por defecto.

	/**
	 * Constructor público para JC_Log.
	 */
	public function __construct() {
		// Inicializar variables dependientes de WordPress.
		add_action( 'init', array( $this, 'initialize' ) );
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

		// Registrar la función de apagado para capturar errores fatales.
		register_shutdown_function( array( $this, 'handle_shutdown' ) );
	}

	/**
	 * Establecer el nombre del log.
	 *
	 * @param string $log_name El nombre del archivo de log.
	 */
	public function set_log_name( $log_name ) {
		$this->log_name = sanitize_file_name( $log_name );
	}

	/**
	 * Manejar el apagado del script y verificar si hay errores fatales.
	 */
	public function handle_shutdown() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			// Asegurarse de que la clase esté inicializada.
			if ( empty( $this->log_directory ) ) {
				$this->initialize();
			}

			// Formatear el mensaje de error.
			$message = sprintf(
				'Fatal error: %s in %s on line %d',
				$error['message'],
				$error['file'],
				$error['line']
			);

			// Establecer el nombre del log a 'fatal-error'.
			$this->set_log_name( 'fatal-error' );

			// Registrar el error.
			$this->critical( $message );
		}
	}

	// Implementación de los métodos de PSR-3.

	public function emergency( $message, array $context = array() ) {
		$this->log( LogLevel::EMERGENCY, $message, $context );
	}

	public function alert( $message, array $context = array() ) {
		$this->log( LogLevel::ALERT, $message, $context );
	}

	public function critical( $message, array $context = array() ) {
		$this->log( LogLevel::CRITICAL, $message, $context );
	}

	public function error( $message, array $context = array() ) {
		$this->log( LogLevel::ERROR, $message, $context );
	}

	public function warning( $message, array $context = array() ) {
		$this->log( LogLevel::WARNING, $message, $context );
	}

	public function notice( $message, array $context = array() ) {
		$this->log( LogLevel::NOTICE, $message, $context );
	}

	public function info( $message, array $context = array() ) {
		$this->log( LogLevel::INFO, $message, $context );
	}

	public function debug( $message, array $context = array() ) {
		$this->log( LogLevel::DEBUG, $message, $context );
	}

	public function log( $level, $message, array $context = array() ) {
		// Verificar que el directorio esté inicializado.
		if ( empty( $this->log_directory ) ) {
			$this->initialize();
		}

		// Verificar si el logging está habilitado.
		if ( ! get_option( 'jc_logs_enable_logging', 0 ) ) {
			return; // El logging está deshabilitado; salir de la función.
		}

		// Determinar el método de almacenamiento.
		$storage_method = get_option( 'jc_logs_storage_method', 'file' );

		// Interpolar los valores de contexto en el mensaje.
		$message = $this->interpolate( $message, $context );

		if ( 'file' === $storage_method ) {
			$this->write_log_to_file( $level, $message );
		} elseif ( 'database' === $storage_method ) {
			$this->write_log_to_database( $level, $message );
		}
	}

	/**
	 * Interpolar los valores del contexto en los marcadores del mensaje.
	 *
	 * @param string $message
	 * @param array  $context
	 * @return string
	 */
	private function interpolate( $message, array $context ) {
		// Construir un array de reemplazo con llaves alrededor de los índices del contexto.
		$replace = array();
		foreach ( $context as $key => $val ) {
			// Verificar que el valor pueda ser convertido a string.
			if ( ! is_array( $val ) && ( ! is_object( $val ) || method_exists( $val, '__toString' ) ) ) {
				$replace[ '{' . $key . '}' ] = $val;
			} else {
				$replace[ '{' . $key . '}' ] = json_encode( $val );
			}
		}

		// Reemplazar los marcadores en el mensaje.
		return strtr( $message, $replace );
	}

	/**
	 * Escribir el log en un archivo.
	 *
	 * @param string $level   El nivel del log.
	 * @param string $message El mensaje del log.
	 */
	private function write_log_to_file( $level, $message ) {
		$date     = gmdate( 'Y-m-d' );
		$log_name = $this->log_name;

		// Generar el nombre del archivo sin cadena aleatoria.
		$file_name = "{$log_name}-{$date}.log";
		$file_path = $this->log_directory . $file_name;

		// Verificar el límite de tamaño del archivo (e.g., 1MB).
		if ( file_exists( $file_path ) && filesize( $file_path ) > 1 * 1024 * 1024 ) { // 1 MB
			// Añadir un sufijo de versión si el archivo excede el tamaño.
			$version = 1;
			do {
				$file_name = "{$log_name}-{$date}-{$version}.log";
				$file_path = $this->log_directory . $file_name;
				++$version;
			} while ( file_exists( $file_path ) && filesize( $file_path ) > 1 * 1024 * 1024 );
		}

		$current_time = current_time( 'Y-m-d H:i:s' );
		$log_entry    = "[{$current_time}] {$level}: {$message}" . PHP_EOL;
		file_put_contents( $file_path, $log_entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Escribir el log en la base de datos.
	 *
	 * @param string $level   El nivel del log.
	 * @param string $message El mensaje del log.
	 */
	private function write_log_to_database( $level, $message ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jc_logs';

		// Asegurarse de que la tabla existe.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			$this->create_logs_table();
		}

		$wpdb->insert(
			$table_name,
			array(
				'level'     => $level,
				'message'   => $message,
				'log_name'  => $this->log_name,
				'timestamp' => current_time( 'mysql' ),
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * Crear la tabla de logs en la base de datos.
	 */
	public function create_logs_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'jc_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            log_name varchar(255) NOT NULL,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            timestamp datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY log_name (log_name)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Métodos de activación del plugin.
	 */
	public static function activate() {
		$logger = new self();
		$logger->initialize();
		$logger->create_logs_table();
	}

	/**
	 * Método de desactivación del plugin.
	 */
	public static function deactivate() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jc_logs';
		$sql        = "DROP TABLE IF EXISTS {$table_name};";
		$wpdb->query( $sql );
	}
}
