<?php
namespace JC_Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class JC_Log_Admin {

	private static $instance = null;

	private $log_directory;

	private function __construct() {
		// Initialize variables dependent on WordPress.
		add_action( 'init', array( $this, 'initialize' ) );

		// Hooks for the admin area.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_jc_logs_download', array( $this, 'download_log_file' ) );
		add_action( 'admin_post_jc_logs_delete', array( $this, 'delete_log_file' ) );
		add_action( 'admin_post_jc_logs_delete_database', array( $this, 'delete_log_database' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Get the singleton instance of the class.
	 *
	 * @return JC_Log_Admin The singleton instance of JC_Log_Admin.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the logs directory.
	 */
	public function initialize() {
		$upload_dir          = wp_upload_dir();
		$this->log_directory = trailingslashit( $upload_dir['basedir'] ) . 'jc-logs/';

		// Create the logs directory if it doesn't exist.
		if ( ! file_exists( $this->log_directory ) ) {
			wp_mkdir_p( $this->log_directory );
		}
	}

	/**
	 * Function to add the menu in the admin area.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'JC Logs', 'jc-logs' ),
			__( 'JC Logs', 'jc-logs' ),
			'manage_options',
			'jc-logs',
			array( $this, 'logs_page' )
		);
	}

	/**
	 * Function to display the logs page.
	 */
	public function logs_page() {
		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Start the wrap.
		echo '<div class="wrap">';

		// Add the main title.
		echo '<h1>' . esc_html__( 'JC Logs', 'jc-logs' ) . '</h1>';

		// Determine the current tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'explore';

		// Display the tabs.
		$this->render_tabs( $tab );

		// Handle the content based on the active tab.
		switch ( $tab ) {
			case 'explore':
				$this->render_explore_page();
				break;
			case 'settings':
				$this->render_settings_page();
				break;
			default:
				$this->render_explore_page();
				break;
		}

		// Close the wrap.
		echo '</div>';
	}

	/**
	 * Render the tabs at the top of the page.
	 *
	 * @param string $current Active tab.
	 */
	private function render_tabs( $current = 'explore' ) {
		$tabs = array(
			'explore'  => __( 'Explore', 'jc-logs' ),
			'settings' => __( 'Settings', 'jc-logs' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $tab => $name ) {
			$class = ( $tab === $current ) ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $class ) . '" href="?page=jc-logs&tab=' . esc_attr( $tab ) . '">' . esc_html( $name ) . '</a>';
		}
		echo '</h2>';
	}

	/**
	 * Render the explore page, which lists logs or displays the content of a log.
	 */
	private function render_explore_page() {
		// Check if a log file was selected for viewing.
		if ( isset( $_GET['file'] ) ) {
			// Display the content of the selected log.
			$this->render_log_content();
		} elseif ( isset( $_GET['log_name'] ) ) {
			// Display the content of the selected log from the database.
			$this->render_log_content_database();
		} else {
			// Display the list of logs.
			$this->render_log_list();
		}
	}

	/**
	 * Render the list of logs.
	 */
	private function render_log_list() {
		// Get logs from files.
		$log_files = glob( $this->log_directory . '*.log' );

		// Get logs from database.
		global $wpdb;
		$table_name    = $wpdb->prefix . 'jc_logs';
		$database_logs = $wpdb->get_results( "SELECT log_name, MIN(timestamp) as creation_time, MAX(timestamp) as modification_time FROM {$table_name} GROUP BY log_name" );

		// Prepare an array to hold all logs.
		$all_logs = array();

		// Process log files.
		if ( ! empty( $log_files ) ) {
			foreach ( $log_files as $file ) {
				$file_name         = basename( $file );
				$creation_time     = gmdate( 'Y-m-d H:i:s', filectime( $file ) );
				$modification_time = gmdate( 'Y-m-d H:i:s', filemtime( $file ) );
				$file_size         = size_format( filesize( $file ), 2 );
				$log_name          = $this->extract_log_name( $file_name );

				$all_logs[] = array(
					'source'            => 'file',
					'log_name'          => $log_name,
					'file_name'         => $file_name,
					'creation_time'     => $creation_time,
					'modification_time' => $modification_time,
					'file_size'         => $file_size,
				);
			}
		}

		// Process database logs.
		if ( ! empty( $database_logs ) ) {
			foreach ( $database_logs as $log ) {
				$log_name          = $log->log_name;
				$creation_time     = $log->creation_time;
				$modification_time = $log->modification_time;
				// Since file size doesn't apply, we can set to '-'.
				$file_size = '-';

				$all_logs[] = array(
					'source'            => 'database',
					'log_name'          => $log_name,
					'file_name'         => '', // Not applicable
					'creation_time'     => $creation_time,
					'modification_time' => $modification_time,
					'file_size'         => $file_size,
				);
			}
		}

		// Sort the logs by modification time descending
		usort(
			$all_logs,
			function ( $a, $b ) {
				return strtotime( $b['modification_time'] ) - strtotime( $a['modification_time'] );
			}
		);

		// Display all logs in a table.
		echo '<h2>' . esc_html__( 'Available Logs', 'jc-logs' ) . '</h2>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Log Name', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'Creation Date', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'Modification Date', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'File Size', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'jc-logs' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		if ( ! empty( $all_logs ) ) {
			foreach ( $all_logs as $log ) {
				$log_name          = $log['log_name'];
				$source            = $log['source'];
				$creation_time     = $log['creation_time'];
				$modification_time = $log['modification_time'];
				$file_size         = $log['file_size'];
				$actions           = '';

				if ( 'file' === $source ) {
					$file_name    = $log['file_name'];
					$view_url     = add_query_arg(
						array(
							'page' => 'jc-logs',
							'tab'  => 'explore',
							'file' => rawurlencode( $file_name ),
						),
						admin_url( 'tools.php' )
					);
					$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $file_name ) ), 'jc_logs_download', 'jc_logs_nonce' );
					$delete_url   = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $file_name ) ), 'jc_logs_delete', 'jc_logs_nonce' );

					$actions .= '<a class="button" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'jc-logs' ) . '</a> ';
					$actions .= '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'jc-logs' ) . '</a> ';
					$actions .= '<a class="button delete-log" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this file?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a>';

					// Make log name clickable
					$log_name_display = '<a href="' . esc_url( $view_url ) . '">' . esc_html( $log_name ) . '</a>';

				} elseif ( 'database' === $source ) {
					$view_url   = add_query_arg(
						array(
							'page'     => 'jc-logs',
							'tab'      => 'explore',
							'log_name' => urlencode( $log_name ),
						),
						admin_url( 'tools.php' )
					);
					$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete_database&log_name=' . urlencode( $log_name ) ), 'jc_logs_delete_database', 'jc_logs_nonce' );

					$actions .= '<a class="button" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'jc-logs' ) . '</a> ';
					$actions .= '<a class="button delete-log" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this log from the database?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a>';

					// Make log name clickable
					$log_name_display = '<a href="' . esc_url( $view_url ) . '">' . esc_html( $log_name ) . '</a>';
				}

				// Format dates for display
				$creation_time_display     = ! empty( $creation_time ) ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $creation_time ) ) ) : '-';
				$modification_time_display = ! empty( $modification_time ) ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $modification_time ) ) ) : '-';

				echo '<tr>';
				echo '<td>' . $log_name_display . '</td>';
				echo '<td>' . esc_html( ucfirst( $source ) ) . '</td>';
				echo '<td>' . $creation_time_display . '</td>';
				echo '<td>' . $modification_time_display . '</td>';
				echo '<td>' . esc_html( $file_size ) . '</td>';
				echo '<td>' . $actions . '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr>';
			echo '<td colspan="6">' . esc_html__( 'No logs available.', 'jc-logs' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Extract the base log name without the date and extension.
	 *
	 * @param string $file_name Full log file name.
	 * @return string Base log name.
	 */
	private function extract_log_name( $file_name ) {
		// Remove the .log extension.
		$base_name = str_replace( '.log', '', $file_name );

		// Pattern to match {log_name}-{date}-{random_string}.
		if ( preg_match( '/^(.*)-\d{4}-\d{2}-\d{2}-[a-f0-9]{10}/', $base_name, $matches ) ) {
			return $matches[1]; // Return the base log name.
		} else {
			return $base_name;
		}
	}

	/**
	 * Render the content of a selected log file.
	 */
	private function render_log_content() {
		$file      = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$file_path = $this->log_directory . $file;

		if ( file_exists( $file_path ) ) {
			// URLs for actions.
			$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $file ) ), 'jc_logs_download', 'jc_logs_nonce' );
			$delete_url   = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $file ) ), 'jc_logs_delete', 'jc_logs_nonce' );
			$back_url     = admin_url( 'tools.php?page=jc-logs&tab=explore' );

			// Title and buttons.
			echo '<h2>' . sprintf( esc_html__( 'Viewing log file: %s', 'jc-logs' ), esc_html( $file ) ) . '</h2>';
			echo '<p>';
			echo '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'jc-logs' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this file?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			echo '</p>';

			// Log content.
			echo '<pre style="background-color: #fff; padding: 20px; border: 1px solid #ccc; max-width: 100%; overflow: auto;">';
			$content = file_get_contents( $file_path );
			echo esc_html( $content );
			echo '</pre>';
		} else {
			echo '<h2>' . esc_html__( 'Error', 'jc-logs' ) . '</h2>';
			echo '<p>' . esc_html__( 'The file does not exist.', 'jc-logs' ) . '</p>';
			echo '<a class="button" href="' . esc_url( admin_url( 'tools.php?page=jc-logs&tab=explore' ) ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
		}
	}

	/**
	 * Render the content of a selected log from the database.
	 */
	private function render_log_content_database() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jc_logs';

		$log_name = isset( $_GET['log_name'] ) ? sanitize_text_field( wp_unslash( $_GET['log_name'] ) ) : '';

		if ( empty( $log_name ) ) {
			echo '<h2>' . esc_html__( 'Error', 'jc-logs' ) . '</h2>';
			echo '<p>' . esc_html__( 'No log specified.', 'jc-logs' ) . '</p>';
			echo '<a class="button" href="' . esc_url( admin_url( 'tools.php?page=jc-logs&tab=explore' ) ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			return;
		}

		// Retrieve log entries from the database.
		$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE log_name = %s ORDER BY timestamp DESC", $log_name ) );

		if ( ! empty( $logs ) ) {
			echo '<h2>' . sprintf( esc_html__( 'Viewing log: %s', 'jc-logs' ), esc_html( $log_name ) ) . '</h2>';
			echo '<p>';
			$back_url = admin_url( 'tools.php?page=jc-logs&tab=explore' );
			echo '<a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			echo '</p>';

			// Display the log entries in a table.
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Timestamp', 'jc-logs' ) . '</th>';
			echo '<th>' . esc_html__( 'Level', 'jc-logs' ) . '</th>';
			echo '<th>' . esc_html__( 'Message', 'jc-logs' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $logs as $log_entry ) {
				echo '<tr>';
				echo '<td>' . esc_html( $log_entry->timestamp ) . '</td>';
				echo '<td>' . esc_html( strtoupper( $log_entry->level ) ) . '</td>';
				echo '<td>' . esc_html( $log_entry->message ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
		} else {
			echo '<h2>' . esc_html__( 'No entries found for this log.', 'jc-logs' ) . '</h2>';
			echo '<a class="button" href="' . esc_url( admin_url( 'tools.php?page=jc-logs&tab=explore' ) ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
		}
	}

	/**
	 * Register settings, sections, and fields for the settings page.
	 */
	public function register_settings() {
		// Register settings.
		register_setting( 'jc_logs_settings', 'jc_logs_enable_logging' );
		register_setting( 'jc_logs_settings', 'jc_logs_storage_method' );
		register_setting( 'jc_logs_settings', 'jc_logs_retention_days' );

		// Add settings section.
		add_settings_section(
			'jc_logs_main_section',
			__( 'Log Settings', 'jc-logs' ),
			null,
			'jc_logs_settings_page'
		);

		// Add settings fields.
		add_settings_field(
			'jc_logs_enable_logging',
			__( 'Enable Logging', 'jc-logs' ),
			array( $this, 'render_enable_logging_field' ),
			'jc_logs_settings_page',
			'jc_logs_main_section'
		);

		add_settings_field(
			'jc_logs_storage_method',
			__( 'Log Storage', 'jc-logs' ),
			array( $this, 'render_storage_method_field' ),
			'jc_logs_settings_page',
			'jc_logs_main_section'
		);

		add_settings_field(
			'jc_logs_retention_days',
			__( 'Retention Period', 'jc-logs' ),
			array( $this, 'render_retention_period_field' ),
			'jc_logs_settings_page',
			'jc_logs_main_section'
		);
	}

	public function render_enable_logging_field() {
		$value = get_option( 'jc_logs_enable_logging', 0 );
		echo '<label>';
		echo '<input type="checkbox" name="jc_logs_enable_logging" value="1" ' . checked( 1, $value, false ) . ' />';
		echo ' ' . esc_html__( 'Enable logging', 'jc-logs' );
		echo '</label>';
	}

	public function render_storage_method_field() {
		$value = get_option( 'jc_logs_storage_method', 'file' );
		echo '<label>';
		echo '<input type="radio" name="jc_logs_storage_method" value="file" ' . checked( 'file', $value, false ) . ' />';
		echo ' ' . esc_html__( 'File System (default)', 'jc-logs' );
		echo '</label><br />';
		echo '<label>';
		echo '<input type="radio" name="jc_logs_storage_method" value="database" ' . checked( 'database', $value, false ) . ' />';
		echo ' ' . esc_html__( 'Database (not recommended on production sites)', 'jc-logs' );
		echo '</label>';
		echo '<p><em>' . esc_html__( 'Please note that if this setting is changed, existing log entries will remain stored in their current location and will not be moved.', 'jc-logs' ) . '</em></p>';
	}

	public function render_retention_period_field() {
		$value = get_option( 'jc_logs_retention_days', '30' );
		echo '<label>';
		echo esc_html__( 'Retention period: ', 'jc-logs' );
		echo '<input type="number" name="jc_logs_retention_days" value="' . esc_attr( $value ) . '" min="1" style="width: 80px;" /> ';
		echo esc_html__( 'days', 'jc-logs' );
		echo '</label>';
	}

	/**
	 * Render the settings page.
	 */
	private function render_settings_page() {
		// Get the log directory path and size.
		$log_directory  = $this->log_directory;
		$directory_size = $this->get_directory_size( $log_directory );

		echo '<h2>' . esc_html__( 'Log Settings', 'jc-logs' ) . '</h2>';

		echo '<form method="post" action="options.php">';
		// Output security fields for the registered setting "jc_logs_settings".
		settings_fields( 'jc_logs_settings' );
		// Output setting sections and their fields.
		do_settings_sections( 'jc_logs_settings_page' );
		// Output save settings button.
		submit_button();
		echo '</form>';

		// Display location and directory size.
		echo '<h2>' . esc_html__( 'Location', 'jc-logs' ) . '</h2>';
		echo '<p>' . esc_html__( 'Log files are stored in this directory:', 'jc-logs' ) . ' <code>' . esc_html( $log_directory ) . '</code></p>';
		echo '<p>' . esc_html__( 'Directory size:', 'jc-logs' ) . ' ' . esc_html( size_format( $directory_size, 2 ) ) . '</p>';
	}

	/**
	 * Function to download a log file.
	 */
	public function download_log_file() {
		// Verify nonce and capability.
		if ( ! isset( $_GET['jc_logs_nonce'] ) || ! wp_verify_nonce( $_GET['jc_logs_nonce'], 'jc_logs_download' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'jc-logs' ) );
		}

		if ( isset( $_GET['file'] ) ) {
			$file      = sanitize_file_name( wp_unslash( $_GET['file'] ) );
			$file_path = $this->log_directory . $file;

			if ( file_exists( $file_path ) ) {
				header( 'Content-Description: File Transfer' );
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
				header( 'Expires: 0' );
				header( 'Cache-Control: must-revalidate' );
				header( 'Pragma: public' );
				header( 'Content-Length: ' . filesize( $file_path ) );
				flush();
				readfile( $file_path );
				exit;
			} else {
				wp_die( __( 'The file does not exist.', 'jc-logs' ) );
			}
		}
	}

	/**
	 * Function to delete a log file.
	 */
	public function delete_log_file() {
		// Verify nonce and capability.
		if ( ! isset( $_GET['jc_logs_nonce'] ) || ! wp_verify_nonce( $_GET['jc_logs_nonce'], 'jc_logs_delete' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'jc-logs' ) );
		}

		if ( isset( $_GET['file'] ) ) {
			$file      = sanitize_file_name( wp_unslash( $_GET['file'] ) );
			$file_path = $this->log_directory . $file;

			if ( file_exists( $file_path ) ) {
				unlink( $file_path );
				wp_redirect( admin_url( 'tools.php?page=jc-logs&tab=explore' ) );
				exit;
			} else {
				wp_die( __( 'The file does not exist.', 'jc-logs' ) );
			}
		}
	}

	/**
	 * Function to delete logs from the database.
	 */
	public function delete_log_database() {
		// Verify nonce and capability.
		if ( ! isset( $_GET['jc_logs_nonce'] ) || ! wp_verify_nonce( $_GET['jc_logs_nonce'], 'jc_logs_delete_database' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'jc-logs' ) );
		}

		if ( isset( $_GET['log_name'] ) ) {
			$log_name = sanitize_text_field( wp_unslash( $_GET['log_name'] ) );

			global $wpdb;
			$table_name = $wpdb->prefix . 'jc_logs';

			// Delete entries from database.
			$wpdb->delete( $table_name, array( 'log_name' => $log_name ), array( '%s' ) );

			wp_redirect( admin_url( 'tools.php?page=jc-logs&tab=explore' ) );
			exit;
		}
	}

	/**
	 * Get the total size of a directory.
	 *
	 * @param string $directory Directory path.
	 * @return int Size in bytes.
	 */
	private function get_directory_size( $directory ) {
		$size = 0;
		foreach ( glob( $directory . '*', GLOB_NOSORT ) as $file ) {
			if ( is_file( $file ) ) {
				$size += filesize( $file );
			}
		}
		return $size;
	}
}
