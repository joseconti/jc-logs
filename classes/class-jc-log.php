<?php
namespace JC_Logs;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class JC_Log implements LoggerInterface {

	private static $instance = null;
	private $log_directory;
	private $security_token;
	private $log_name = 'default'; // Default log name.

	/**
	 * Private constructor to implement Singleton pattern.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'initialize' ) );
	}

	/**
	 * Get the singleton instance of the class.
	 *
	 * @return JC_Log
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the logs directory and security token.
	 */
	public function initialize() {
		$upload_dir           = wp_upload_dir();
		$this->log_directory  = trailingslashit( $upload_dir['basedir'] ) . 'jc-logs/';
		$this->security_token = wp_hash( 'jc_logs_security' );

		// Create the logs directory if it doesn't exist.
		if ( ! file_exists( $this->log_directory ) ) {
			wp_mkdir_p( $this->log_directory );
		}

		// Register the shutdown function to capture fatal errors.
		register_shutdown_function( array( $this, 'handle_shutdown' ) );
	}

	/**
	 * Set the log name.
	 *
	 * @param string $log_name The name of the log file.
	 */
	public function set_log_name( $log_name ) {
		$this->log_name = sanitize_file_name( $log_name );
	}

	/**
	 * Handle script shutdown and check for fatal errors.
	 */
	public function handle_shutdown() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			if ( empty( $this->log_directory ) ) {
				$this->initialize();
			}

			$message = sprintf(
				'Fatal error: %s in %s on line %d',
				$error['message'],
				$error['file'],
				$error['line']
			);

			$this->set_log_name( 'fatal-error' );
			$this->critical( $message );
		}
	}

	// Implementing PSR-3 methods.

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

	/**
	 * Logs with an arbitrary level.
	 *
	 * @param mixed  $level
	 * @param string $message
	 * @param array  $context
	 */
	public function log( $level, $message, array $context = array() ) {
		if ( empty( $this->log_directory ) ) {
			$this->initialize();
		}

		if ( ! get_option( 'jc_logs_enable_logging', 0 ) ) {
			return;
		}

		$storage_method = get_option( 'jc_logs_storage_method', 'file' );
		$message        = $this->interpolate( $message, $context );

		if ( 'file' === $storage_method ) {
			$this->write_log_to_file( $level, $message );
		} elseif ( 'database' === $storage_method ) {
			$this->write_log_to_database( $level, $message );
		}
	}

	/**
	 * Interpolates context values into the message placeholders.
	 *
	 * @param string $message
	 * @param array  $context
	 * @return string
	 */
	private function interpolate( $message, array $context ) {
		$replace = array();
		foreach ( $context as $key => $val ) {
			if ( ! is_array( $val ) && ( ! is_object( $val ) || method_exists( $val, '__toString' ) ) ) {
				$replace[ '{' . $key . '}' ] = $val;
			} else {
				$replace[ '{' . $key . '}' ] = json_encode( $val );
			}
		}

		return strtr( $message, $replace );
	}

	/**
	 * Write the log to a file.
	 *
	 * @param string $level
	 * @param string $message
	 */
	private function write_log_to_file( $level, $message ) {
		$date     = gmdate( 'Y-m-d' );
		$log_name = $this->log_name;

		// Generate a random string for security.
		$random_string = substr( md5( uniqid( rand(), true ) ), 0, 10 );
		$file_name     = "{$log_name}-{$date}-{$random_string}.log";
		$file_path     = $this->log_directory . $file_name;

		// Check file size limit (e.g., 1MB).
		if ( file_exists( $file_path ) && filesize( $file_path ) > 1 * 1024 * 1024 ) { // 1 MB
			$version = 1;
			do {
				$file_name = "{$log_name}-{$date}-{$random_string}-{$version}.log";
				$file_path = $this->log_directory . $file_name;
				++$version;
			} while ( file_exists( $file_path ) && filesize( $file_path ) > 1 * 1024 * 1024 );
		}

		$current_time = current_time( 'Y-m-d H:i:s' );
		$log_entry    = "[{$current_time}] {$level}: {$message}" . PHP_EOL;
		file_put_contents( $file_path, $log_entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Write the log to the database.
	 *
	 * @param string $level
	 * @param string $message
	 */
	private function write_log_to_database( $level, $message ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jc_logs';

		// Ensure the table exists.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			$this->create_logs_table();
		}

		$wpdb->insert(
			$table_name,
			array(
				'log_name'  => $this->log_name,
				'level'     => $level,
				'message'   => $message,
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
	 * Create the logs table in the database.
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
	 * Activation hook to create the logs table.
	 */
	public static function activate() {
		$instance = self::get_instance();
		$instance->initialize();
		$instance->create_logs_table();
	}

	/**
	 * Deactivation hook to drop the logs table.
	 */
	public static function deactivate() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jc_logs';
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name};" );
	}
}
