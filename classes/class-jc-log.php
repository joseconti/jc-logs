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
		// Define the directory where logs will be stored.
		$this->log_directory = WP_CONTENT_DIR . '/uploads/jc-logs/';
		// Generate a security token using a random string.
		$this->security_token = bin2hex( random_bytes( 16 ) );

		// Initialize the WP_Filesystem for interacting with the file system.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();
		global $wp_filesystem;

		// Check if the logs directory exists; if not, attempt to create it.
		if ( ! $wp_filesystem->is_dir( $this->log_directory ) ) {
			// Attempt to create the logs directory using wp_mkdir_p().
			if ( ! wp_mkdir_p( $this->log_directory ) ) {
				wp_die( esc_html__( 'Unable to create the logs directory. Please check the permissions.', 'jc-logs' ) );
			}
		}

		// Verify if the logs directory is writable.
		if ( ! $wp_filesystem->is_writable( $this->log_directory ) ) {
			wp_die( esc_html__( 'The logs directory is not writable. Please check the permissions.', 'jc-logs' ) );
		}

		// Test write operation: create a temporary file to ensure the directory is writable.
		$test_file_path = trailingslashit( $this->log_directory ) . 'test_write.log';
		$test_content   = 'This is a test write operation.';

		// Try to write content to the test file.
		if ( ! $wp_filesystem->put_contents( $test_file_path, $test_content, FS_CHMOD_FILE ) ) {
			wp_die( esc_html__( 'Unable to write to the logs directory. Please check the permissions.', 'jc-logs' ) );
		}

		// Remove the test file after the write operation is confirmed.
		$wp_filesystem->delete( $test_file_path );

		// Register a shutdown function to capture and handle any fatal errors that occur.
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
		// Initialize the logging directory if not already set.
		if ( empty( $this->log_directory ) ) {
			$this->initialize();
		}

		// Check if logging is enabled.
		if ( ! get_option( 'jc_logs_enable_logging', 1 ) ) {
			return;
		}

		// Verify the storage method, defaulting to 'file' if not set.
		$storage_method = get_option( 'jc_logs_storage_method', 'file' );

		// Ensure 'file' is used as the default if an invalid method is specified.
		if ( ! in_array( $storage_method, array( 'file', 'database' ), true ) ) {
			$storage_method = 'file';
		}

		// Encode the entire context as JSON.
		$context_json = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		// Append the JSON-encoded context to the message.
		$message .= ' ' . $context_json;

		$this->write_log_to_file( $level, $message );
	}

	/**
	 * Write a log entry to a file.
	 *
	 * @param string $level   The severity level of the log (e.g., 'INFO', 'ERROR').
	 * @param string $message The log message to write.
	 */
	private function write_log_to_file( $level, $message ) {
		$date     = current_time( 'Y-m-d' ); // Use WordPress time for the date.
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
			$random_string = substr( md5( uniqid( random_int( 0, PHP_INT_MAX ), true ) ), 0, 10 );
			$file_name     = "{$log_name}-{$date}-{$random_string}.log";
			$file_path     = $this->log_directory . $file_name;
		}

		$current_time = current_time( 'Y-m-d H:i:s' ); // Use WordPress time for the log entry.
		$log_entry    = "[{$current_time}] {$level}: {$message}" . PHP_EOL;

		// Initialize the WP_Filesystem for handling file operations.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		// Check if the directory is writable before proceeding.
		if ( ! $wp_filesystem->is_writable( $this->log_directory ) ) {
			wp_die( esc_html__( 'The logs directory is not writable. Please check the permissions.', 'jc-logs' ) );
		}

		// Check if the file exists and read its content if it does.
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
	 *
	 * @param bool $network_wide Whether to activate the plugin for all sites in the network.
	 */
	public static function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			// Activar el plugin para toda la red.
			$sites = get_sites();
			foreach ( $sites as $site ) {
				switch_to_blog( $site->blog_id );
				$instance = self::get_instance();
				$instance->initialize();
				restore_current_blog();
			}
		} else {
			// Activar el plugin para un solo sitio.
			$instance = self::get_instance();
			$instance->initialize();
		}
	}
}
