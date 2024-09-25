<?php
namespace JC_Logs;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class JC_Log implements LoggerInterface {

	/**
	 * The singleton instance of the class.
	 *
	 * @var JC_Log|null
	 */
	private static $instance = null;

	/**
	 * Directory where logs are stored.
	 *
	 * @var string
	 */
	private $log_directory;

	/**
	 * Security token for log operations.
	 *
	 * @var string
	 */
	private $security_token;

	/**
	 * Name of the log file.
	 *
	 * @var string
	 */
	private $log_name = 'default';

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Initialize variables dependent on WordPress.
		add_action( 'init', array( $this, 'initialize' ) );
	}

	/**
	 * Get the singleton instance of the class.
	 *
	 * @return JC_Log The singleton instance of JC_Log.
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
	 * Handle script shutdown and check for fatal errors.
	 */
	public function handle_shutdown() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ) ) ) {
			// Ensure the class is initialized.
			if ( empty( $this->log_directory ) ) {
				$this->initialize();
			}

			// Format the error message.
			$message = sprintf(
				'Fatal error: %s in %s on line %d',
				$error['message'],
				$error['file'],
				$error['line']
			);

			// Log the error.
			$this->logMessage( 'fatal-error', LogLevel::CRITICAL, $message );
		}
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
	 * Generic method to log a message with a specified log name.
	 *
	 * @param string $log_name The name of the log file.
	 * @param string $level    The log level.
	 * @param string $message  The log message.
	 * @param array  $context  The log context.
	 */
	public function logMessage( $log_name, $level, $message, array $context = array() ) {
		// Set the log name.
		$this->set_log_name( $log_name );

		// Call the PSR-3 log method.
		$this->log( $level, $message, $context );
	}

	/**
	 * Logs an emergency message.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function emergency( $message, array $context = array() ) {
		$this->log( LogLevel::EMERGENCY, $message, $context );
	}

	/**
	 * Logs an alert message.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function alert( $message, array $context = array() ) {
		$this->log( LogLevel::ALERT, $message, $context );
	}

	/**
	 * Logs a critical message.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function critical( $message, array $context = array() ) {
		$this->log( LogLevel::CRITICAL, $message, $context );
	}

	/**
	 * Logs an error message.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function error( $message, array $context = array() ) {
		$this->log( LogLevel::ERROR, $message, $context );
	}

	/**
	 * Logs a warning message.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( LogLevel::WARNING, $message, $context );
	}

	/**
	 * Logs a notice message.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function notice( $message, array $context = array() ) {
		$this->log( LogLevel::NOTICE, $message, $context );
	}

	/**
	 * Logs an info message.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function info( $message, array $context = array() ) {
		$this->log( LogLevel::INFO, $message, $context );
	}

	/**
	 * Logs a debug message.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( LogLevel::DEBUG, $message, $context );
	}

	/**
	 * Logs a message with a given level.
	 *
	 * @param string $level   The log level.
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 */
	public function log( $level, $message, array $context = array() ) {
		// Verify that the directory is initialized.
		if ( empty( $this->log_directory ) ) {
			$this->initialize();
		}

		// Check if logging is enabled.
		if ( ! get_option( 'jc_logs_enable_logging', 0 ) ) {
			return; // Logging is disabled; exit the function.
		}

		// Determine the storage method.
		$storage_method = get_option( 'jc_logs_storage_method', 'file' );

		// Interpolate context values into the message.
		$message = $this->interpolate( $message, $context );

		if ( 'file' === $storage_method ) {
			$this->write_log_to_file( $level, $message );
		} elseif ( 'database' === $storage_method ) {
			$this->write_log_to_database( $level, $message );
		}
	}

	/**
	 * Interpolate context values into the message placeholders.
	 *
	 * @param string $message The log message.
	 * @param array  $context The log context.
	 * @return string
	 */
	private function interpolate( $message, array $context ) {
		// Build a replacement array with braces around the context keys.
		$replace = array();
		foreach ( $context as $key => $val ) {
			// Check that the value can be cast to string.
			if ( ! is_array( $val ) && ( ! is_object( $val ) || method_exists( $val, '__toString' ) ) ) {
				$replace[ '{' . $key . '}' ] = $val;
			}
		}

		// Interpolate replacement values into the message.
		return strtr( $message, $replace );
	}

	/**
	 * Write the log to a file.
	 *
	 * @param string $level   The log level.
	 * @param string $message The log message.
	 */
	private function write_log_to_file( $level, $message ) {
		$date          = gmdate( 'Y-m-d' );
		$log_name      = $this->log_name;
		$random_string = substr( md5( uniqid( rand(), true ) ), 0, 10 );
		$file_name     = "{$log_name}-{$date}-{$random_string}.log";
		$file_path     = $this->log_directory . $file_name;

		// Check file size limit (e.g., 1MB).
		if ( file_exists( $file_path ) && filesize( $file_path ) > 1 * MB_IN_BYTES ) {
			$version = 1;
			do {
				$file_name = "{$log_name}-{$date}-{$version}-{$random_string}.log";
				$file_path = $this->log_directory . $file_name;
				++$version;
			} while ( file_exists( $file_path ) && filesize( $file_path ) > 1 * MB_IN_BYTES );
		}

		$current_time = current_time( 'Y-m-d H:i:s' );
		$log_entry    = "[{$current_time}] {$level}: {$message}" . PHP_EOL;
		file_put_contents( $file_path, $log_entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Write the log to the database.
	 *
	 * @param string $level   The log level.
	 * @param string $message The log message.
	 */
	private function write_log_to_database( $level, $message ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jc_logs';

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
	 * Create the logs table in the database.
	 */
	public static function create_logs_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'jc_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            log_name varchar(255) NOT NULL,
            timestamp datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Activate the plugin.
	 */
	public static function activate() {
		// Create the logs table.
		self::create_logs_table();

		// Schedule the daily cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'jc_logs_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'jc_logs_daily_cleanup' );
		}
	}

	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate() {
		// Clear the scheduled cleanup event.
		wp_clear_scheduled_hook( 'jc_logs_daily_cleanup' );
	}

	/**
	 * Delete old logs based on retention period.
	 */
	public function delete_old_logs() {
		$retention_days = get_option( 'jc_logs_retention_days', '30' );
		$cutoff_time    = strtotime( '-' . intval( $retention_days ) . ' days' );

		$storage_method = get_option( 'jc_logs_storage_method', 'file' );

		if ( 'file' === $storage_method ) {
			// Delete old log files.
			$log_files = glob( $this->log_directory . '*.log' );
			foreach ( $log_files as $file ) {
				if ( filemtime( $file ) < $cutoff_time ) {
					unlink( $file );
				}
			}
		} elseif ( 'database' === $storage_method ) {
			// Delete old logs from the database.
			global $wpdb;
			$table_name = $wpdb->prefix . 'jc_logs';
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE timestamp < %s", date( 'Y-m-d H:i:s', $cutoff_time ) ) );
		}
	}
}

// Hook the delete_old_logs method to the scheduled event.
add_action( 'jc_logs_daily_cleanup', array( JC_Log::get_instance(), 'delete_old_logs' ) );
