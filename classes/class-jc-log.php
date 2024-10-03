<?php
/**
 * PSR-3 logger interface.
 *
 * @package Psr\Log
 */

namespace JC_Logs;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class JC_Log
 */
class JC_Log implements LoggerInterface {

	/**
	 * Singleton instance of the class.
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
	 * Logs with an arbitrary level.
	 *
	 * @param mixed  $level   The log level (e.g., emergency, alert, critical, error, warning, notice, info, debug).
	 * @param string $message The log message.
	 * @param array  $context The log context, an array of additional information.
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
	 * @param string $message The log message with placeholders.
	 * @param array  $context An array of placeholder replacements.
	 * @return string The message with placeholders replaced by context values.
	 */
	private function interpolate( $message, array $context ) {
		$replace = array();
		foreach ( $context as $key => $val ) {
			if ( ! is_array( $val ) && ( ! is_object( $val ) || method_exists( $val, '__toString' ) ) ) {
				$replace[ '{' . $key . '}' ] = $val;
			} else {
				$replace[ '{' . $key . '}' ] = wp_json_encode( $val );
			}
		}

		return strtr( $message, $replace );
	}

	/**
	 * Write the log to a file.
	 *
	 * @param string $level   The log level (e.g., emergency, alert, critical, error, warning, notice, info, debug).
	 * @param string $message The log message.
	 */
	private function write_log_to_file( $level, $message ) {
		$date     = gmdate( 'Y-m-d' );
		$log_name = $this->log_name;

		// Pattern to search for existing log files with the same log_name and date.
		$pattern = "{$log_name}-{$date}-*.log";
		$files   = glob( $this->log_directory . $pattern );

		// Sort files by modification time descending.
		usort(
			$files,
			function ( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);

		$file_path    = '';
		$current_size = 0;
		$max_size     = 1 * 1024 * 1024; // 1 MB

		if ( ! empty( $files ) ) {
			// Get the most recent file.
			$latest_file  = $files[0];
			$current_size = filesize( $latest_file );

			if ( $current_size < $max_size ) {
				// If the latest file hasn't reached the size limit, use it.
				$file_path = $latest_file;
			}
		}

		if ( empty( $file_path ) ) {
			// If no suitable file exists, create a new one with a random string.
			$random_string = substr( md5( uniqid( wp_rand(), true ) ), 0, 10 );
			$file_name     = "{$log_name}-{$date}-{$random_string}.log";
			$file_path     = $this->log_directory . $file_name;
		}

		$current_time = current_time( 'Y-m-d H:i:s' );
		$log_entry    = "[{$current_time}] {$level}: {$message}" . PHP_EOL;
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		// Read the existing content if the file exists.
		$existing_content = '';
		if ( $wp_filesystem->exists( $file_path ) ) {
			$existing_content = $wp_filesystem->get_contents( $file_path );
		}

		// Append the new log entry.
		$new_content = $existing_content . $log_entry;

		// Write the updated content back to the file.
		$wp_filesystem->put_contents( $file_path, $new_content, FS_CHMOD_FILE );
	}

	/**
	 * Write the log to the database.
	 *
	 * @param string $level   The log level (e.g., emergency, alert, critical, error, warning, notice, info, debug).
	 * @param string $message The log message.
	 */
	private function write_log_to_database( $level, $message ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jc_logs';

		// Ensure the table exists.
		$cache_key    = 'jc_logs_table_exists';
		$table_exists = wp_cache_get( $cache_key, 'jc_logs' );

		if ( false === $table_exists ) {
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			wp_cache_set( $cache_key, $table_exists, 'jc_logs', 3600 ); // Cache for 1 hour.
		}

		if ( ! $table_exists ) {
			$this->create_logs_table();
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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
	 * Extract the base log name without the date and random string.
	 *
	 * @param string $file_name Full log file name.
	 * @return string Base log name.
	 */
	private function extract_log_name( $file_name ) {
		// Remover la extensión .log.
		$base_name = str_replace( '.log', '', $file_name );

		// Patrón para coincidir con {log_name}-{date}-{random_string}.
		if ( preg_match( '/^(.*)-\d{4}-\d{2}-\d{2}-[a-f0-9]{10}$/', $base_name, $matches ) ) {
			return $matches[1]; // Retornar el nombre base del log.
		} elseif ( preg_match( '/^(.*)-\d{4}-\d{2}-\d{2}$/', $base_name, $matches ) ) {
			return $matches[1]; // Retornar el nombre base del log sin sufijo aleatorio.
		} else {
			return $base_name;
		}
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
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		wp_cache_delete( 'jc_logs_table', 'jc_logs' );
	}
}
