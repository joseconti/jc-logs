<?php
/**
 * Class to handle the plugin's admin page.
 *
 * @package JC_Logs
 */

namespace JC_Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class JC_Log_Admin
 */
class JC_Log_Admin {

	/**
	 * The single instance of the class.
	 *
	 * @var JC_Log_Admin|null
	 */
	private static $instance = null;

	/**
	 * Directory where logs are stored.
	 *
	 * @var string
	 */
	private $log_directory;

	/**
	 * Table name for database logs.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Number of logs to display per page.
	 *
	 * @var int
	 */
	private $logs_per_page = 20; // Number of logs per page.

	/**
	 * Private constructor to implement the Singleton pattern.
	 */
	private function __construct() {
		// Initialize WordPress dependent variables.
		add_action( 'init', array( $this, 'initialize' ) );

		// Hooks for the admin area.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_jc_logs_download', array( $this, 'download_log_file' ) );
		add_action( 'admin_post_jc_logs_delete', array( $this, 'delete_log_file' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Get the single instance of the class.
	 *
	 * @return JC_Log_Admin The single instance of JC_Log_Admin.
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
		global $wpdb;

		// Always use the centralized directory.
		$this->log_directory = WP_CONTENT_DIR . '/uploads/jc-logs/';
		$this->table_name    = $wpdb->prefix . 'jc_logs';

		// Create the logs directory if it doesn't exist.
		if ( ! file_exists( $this->log_directory ) ) {
			wp_mkdir_p( $this->log_directory );
		}

		// Initialize WP_Filesystem.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();
		global $wp_filesystem;

		// Create the .htaccess file to protect the logs directory.
		$htaccess_file = trailingslashit( $this->log_directory ) . '.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess_file ) ) {
			$htaccess_content = "Deny from all\n";
			$result           = $wp_filesystem->put_contents( $htaccess_file, $htaccess_content, FS_CHMOD_FILE );

			if ( false === $result ) {
				wp_die( esc_html__( 'Unable to create the .htaccess file for log protection.', 'jc-logs' ) );
			}
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
		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Start the container.
		echo '<div class="wrap">';

		// Add the main title.
		echo '<h1>' . esc_html__( 'JC Logs', 'jc-logs' ) . '</h1>';

		// Determine the current tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'explore'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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

		// Close the container.
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
			$href  = add_query_arg(
				array(
					'page' => 'jc-logs',
					'tab'  => $tab,
				),
				admin_url( 'tools.php' )
			);
			echo '<a class="nav-tab' . esc_attr( $class ) . '" href="' . esc_url( $href ) . '">' . esc_html( $name ) . '</a>';
		}
		echo '</h2>';
	}

	/**
	 * Render the exploration page, which lists the logs or shows the content of a log.
	 */
	private function render_explore_page() {
		// Check if a log file was selected for viewing.
		if ( isset( $_GET['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Show the content of the selected log file.
			$this->render_log_content();
		} else {
			// Show the list of logs with pagination.
			$this->render_log_list();
		}
	}

	/**
	 * Render the list of logs with pagination.
	 */
	private function render_log_list() {
		global $wpdb;

		// Check if pagination is present.
		$is_paged = false;
		if ( isset( $_GET['paged'] ) ) {
			$is_paged = true;
		}

		// If pagination is present, verify the nonce.
		if ( $is_paged ) {
			$paged_nonce = isset( $_GET['jc_logs_paged_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['jc_logs_paged_nonce'] ) ) : '';
			if ( empty( $paged_nonce ) || ! wp_verify_nonce( $paged_nonce, 'jc_logs_paged_nonce' ) ) {
				wp_die( esc_html__( 'Invalid pagination request.', 'jc-logs' ) );
			}
		}

		// Get the current page number.
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

		// Get logs from files in the centralized directory.
		$log_files = glob( $this->log_directory . '*.log' );

		// Prepare an array to hold all logs.
		$all_logs = array();

		// Process log files.
		if ( ! empty( $log_files ) ) {
			foreach ( $log_files as $file ) {
				$file_name         = basename( $file );
				$creation_time     = gmdate( 'Y-m-d H:i:s', filectime( $file ) );
				$modification_time = gmdate( 'Y-m-d H:i:s', filemtime( $file ) );
				$file_size         = filesize( $file );
				$log_name          = $this->extract_log_name( $file_name );

				// Extract the date from the file name.
				$date = substr( $file_name, strlen( $log_name ) + 1, 10 ); // Extract YYYY-MM-DD.

				// Identify if there is already an entry for this log_name and date.
				$key = "{$log_name}-{$date}";
				if ( ! isset( $all_logs[ $key ] ) ) {
					$all_logs[ $key ] = array(
						'source'            => 'file',
						'log_name'          => $log_name,
						'file_names'        => array(),
						'creation_time'     => $creation_time,
						'modification_time' => $modification_time,
						'file_size'         => 0,
					);
				}

				$all_logs[ $key ]['file_names'][] = $file_name;
				$all_logs[ $key ]['file_size']   += $file_size;
				// Update modification date if the current file is more recent.
				if ( strtotime( $modification_time ) > strtotime( $all_logs[ $key ]['modification_time'] ) ) {
					$all_logs[ $key ]['modification_time'] = $modification_time;
				}
			}
		}

		// Convert the associative array to an indexed array for usort.
		$all_logs = array_values( $all_logs );

		// Sort the logs by modification date in descending order.
		usort(
			$all_logs,
			function ( $a, $b ) {
				return strtotime( $b['modification_time'] ) - strtotime( $a['modification_time'] );
			}
		);

		// Implement pagination.
		$total_logs  = count( $all_logs );
		$total_pages = ceil( $total_logs / $this->logs_per_page );

		// Get the logs for the current page.
		$offset       = ( $current_page - 1 ) * $this->logs_per_page;
		$logs_to_show = array_slice( $all_logs, $offset, $this->logs_per_page );

		// Define allowed HTML tags for $actions.
		$allowed_html = array(
			'a' => array(
				'href'    => array(),
				'class'   => array(),
				'style'   => array(),
				'onclick' => array(),
			),
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

		if ( ! empty( $logs_to_show ) ) {
			foreach ( $logs_to_show as $log ) {
				$log_name          = $log['log_name'];
				$source            = $log['source'];
				$creation_time     = $log['creation_time'];
				$modification_time = $log['modification_time'];
				$file_size         = $log['file_size'];
				$actions           = '';

				if ( 'file' === $source ) {
					// Show only the first file in the actions.
					$file_name      = $log['file_names'][0];
					$base_file_name = basename( $file_name, '.log' );
					$view_url       = add_query_arg(
						array(
							'page' => 'jc-logs',
							'tab'  => 'explore',
							'file' => rawurlencode( $base_file_name ),
						),
						admin_url( 'tools.php' )
					);

					$view_url     = wp_nonce_url( $view_url, 'view_log_nonce', 'jc_logs_nonce' );
					$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $base_file_name ) ), 'jc_logs_download', 'jc_logs_nonce' );
					$delete_url   = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $base_file_name ) ), 'jc_logs_delete', 'jc_logs_nonce' );

					$actions .= '<a class="button" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'jc-logs' ) . '</a> ';
					$actions .= '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'jc-logs' ) . '</a> ';
					$actions .= '<a class="button delete-log" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this file?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a>';

					$log_name_display = '<a href="' . esc_url( $view_url ) . '">' . esc_html( $log_name ) . '</a>';
				} elseif ( 'database' === $source ) {
					$view_url   = add_query_arg(
						array(
							'page'     => 'jc-logs',
							'tab'      => 'explore',
							'log_name' => rawurlencode( $log_name ),
						),
						admin_url( 'tools.php' )
					);
					$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete_database&log_name=' . rawurlencode( $log_name ) ), 'jc_logs_delete_database', 'jc_logs_nonce' );

					$actions .= '<a class="button" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'jc-logs' ) . '</a> ';
					$actions .= '<a class="button delete-log" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this log from the database?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a>';

					// Make the log name clickable.
					$log_name_display = '<a href="' . esc_url( $view_url ) . '">' . esc_html( $log_name ) . '</a>';
				}

				// Format dates for display.
				$creation_time_display     = ! empty( $creation_time ) ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $creation_time ) ) ) : '-';
				$modification_time_display = ! empty( $modification_time ) ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $modification_time ) ) ) : '-';

				// Format file size.
				if ( 'file' === $source ) {
					$file_size_display = size_format( $file_size, 2 );
				} else {
					$file_size_display = esc_html( $file_size );
				}

				echo '<tr>';
				// Properly escape 'log_name_display' allowing only the <a> tag.
				echo '<td>' . wp_kses( $log_name_display, array( 'a' => array( 'href' => array() ) ) ) . '</td>';
				echo '<td>' . esc_html( ucfirst( $source ) ) . '</td>';
				echo '<td>' . esc_html( $creation_time_display ) . '</td>';
				echo '<td>' . esc_html( $modification_time_display ) . '</td>';
				echo '<td>' . esc_html( $file_size_display ) . '</td>';
				echo '<td>' . wp_kses( $actions, $allowed_html ) . '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr>';
			echo '<td colspan="6">' . esc_html__( 'No logs available.', 'jc-logs' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		// Display pagination.
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav top">';
			echo '<div class="tablenav-pages">';
			$paginate_links = paginate_links(
				array(
					'base'      => add_query_arg( array( 'paged' => '%#%' ) ),
					'format'    => '',
					'prev_text' => __( '&laquo;', 'jc-logs' ),
					'next_text' => __( '&raquo;', 'jc-logs' ),
					'total'     => $total_pages,
					'current'   => $current_page,
					'add_args'  => array( 'jc_logs_paged_nonce' => wp_create_nonce( 'jc_logs_paged_nonce' ) ),
				)
			);
			echo wp_kses_post( $paginate_links );
			echo '</div>';
			echo '</div>';
		}
	}

	/**
	 * Extract the base log name without the date and random suffix.
	 *
	 * @param string $file_name Full log file name.
	 * @return string Base log name.
	 */
	private function extract_log_name( $file_name ) {
		// Remove the .log extension.
		$base_name = str_replace( '.log', '', $file_name );

		// Pattern to match {log_name}-YYYY-MM-DD-{random_string}.
		if ( preg_match( '/^(.*)-\d{4}-\d{2}-\d{2}-[a-f0-9]{10}$/', $base_name, $matches ) ) {
			return $matches[1]; // Return the base log name.
		} elseif ( preg_match( '/^(.*)-\d{4}-\d{2}-\d{2}$/', $base_name, $matches ) ) {
			return $matches[1]; // Return the base log name without random suffix.
		} else {
			return $base_name;
		}
	}

	/**
	 * Render the content of a selected log file.
	 */
	private function render_log_content() {

		$jc_logs_nonce = isset( $_GET['jc_logs_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['jc_logs_nonce'] ) ) : '';
		if ( empty( $jc_logs_nonce ) || ! wp_verify_nonce( $jc_logs_nonce, 'view_log_nonce' ) ) {
			wp_die( esc_html__( 'Invalid request. Nonce verification failed.', 'jc-logs' ) );
		}

		$file      = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file_path = $this->log_directory . $file . '.log';

		if ( file_exists( $file_path ) ) {
			// Initialize WP_Filesystem.
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();
			global $wp_filesystem;

			// Get the file content.
			$content = $wp_filesystem->get_contents( $file_path );

			if ( false === $content ) {
				wp_die( esc_html__( 'Unable to read the file.', 'jc-logs' ) );
			}

			// URLs for actions.
			$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $file ) ), 'jc_logs_download', 'jc_logs_nonce' );
			$delete_url   = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $file ) ), 'jc_logs_delete', 'jc_logs_nonce' );
			$back_url     = admin_url( 'tools.php?page=jc-logs&tab=explore' );

			// Title and buttons.
			// translators: %s is the name of the log file being viewed.
			echo '<h2>' . sprintf( esc_html__( 'Viewing log file: %s', 'jc-logs' ), esc_html( $file ) ) . '</h2>';
			echo '<p>';
			echo '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'jc-logs' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this file?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			echo '</p>';

			// Log content.
			echo '<pre style="background-color: #fff; padding: 20px; border: 1px solid #ccc; max-width: 100%; overflow: auto;">';
			echo esc_html( $content ); // Escape the content before printing.
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
		$table_name = $this->table_name;

		$log_name = isset( $_GET['log_name'] ) ? sanitize_text_field( wp_unslash( $_GET['log_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $log_name ) ) {
			echo '<h2>' . esc_html__( 'Error', 'jc-logs' ) . '</h2>';
			echo '<p>' . esc_html__( 'No log specified.', 'jc-logs' ) . '</p>';
			echo '<a class="button" href="' . esc_url( admin_url( 'tools.php?page=jc-logs&tab=explore' ) ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			return;
		}

		// Implement caching for the log entries.
		$cache_key = 'jc_logs_log_entries_' . md5( $log_name );
		$logs      = wp_cache_get( $cache_key, 'jc_logs' );

		if ( false === $logs ) {
			// Fetch log entries from the database using prepared statements.
			$logs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'jc_logs WHERE log_name = %s ORDER BY timestamp DESC', $log_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			// Cache the result for 5 minutes.
			wp_cache_set( $cache_key, $logs, 'jc_logs', 300 );
		}

		if ( ! empty( $logs ) ) {
			// translators: %s is the name of the log being viewed.
			echo '<h2>' . sprintf( esc_html__( 'Viewing log: %s', 'jc-logs' ), esc_html( $log_name ) ) . '</h2>';
			echo '<p>';
			$back_url = admin_url( 'tools.php?page=jc-logs&tab=explore' );
			echo '<a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			echo '</p>';

			// Display log entries in a table.
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
		register_setting( 'jc_logs_settings', 'jc_logs_retention_days' );

		add_settings_section(
			'jc_logs_main_section',
			__( 'Log Settings', 'jc-logs' ),
			null,
			'jc_logs_settings_page'
		);

		add_settings_field(
			'jc_logs_enable_logging',
			__( 'Enable Logging', 'jc-logs' ),
			array( $this, 'render_enable_logging_field' ),
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

	/**
	 * Render the enable logging field.
	 */
	public function render_enable_logging_field() {
		$value = get_option( 'jc_logs_enable_logging', 0 );
		echo '<label>';
		echo '<input type="checkbox" name="jc_logs_enable_logging" value="1" ' . checked( 1, $value, false ) . ' />';
		echo ' ' . esc_html__( 'Enable logging', 'jc-logs' );
		echo '</label>';
	}

	/**
	 * Render the retention period field.
	 */
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

		// echo '<h2>' . esc_html__( 'Log Settings', 'jc-logs' ) . '</h2>';

		echo '<form method="post" action="options.php">';
		// Output security fields for the registered setting "jc_logs_settings".
		settings_fields( 'jc_logs_settings' );
		// Output setting sections and their fields.
		do_settings_sections( 'jc_logs_settings_page' );
		// Output save settings button.
		submit_button();
		echo '</form>';

		// Display log directory location and size.
		echo '<h2>' . esc_html__( 'Location', 'jc-logs' ) . '</h2>';
		echo '<p>' . esc_html__( 'Log files are stored in this directory:', 'jc-logs' ) . ' <code>' . esc_html( $log_directory ) . '</code></p>';
		echo '<p>' . esc_html__( 'Directory size:', 'jc-logs' ) . ' ' . esc_html( size_format( $directory_size, 2 ) ) . '</p>';
	}

	/**
	 * Función para descargar un archivo de log.
	 */
	public function download_log_file() {
		// Verificar nonce y capacidades.
		$jc_logs_nonce = isset( $_GET['jc_logs_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['jc_logs_nonce'] ) ) : '';
		if ( empty( $jc_logs_nonce ) || ! wp_verify_nonce( $jc_logs_nonce, 'jc_logs_download' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'jc-logs' ) );
		}

		if ( isset( $_GET['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$file      = sanitize_file_name( wp_unslash( $_GET['file'] ) );
			$file_path = $this->log_directory . $file . '.log';

			if ( file_exists( $file_path ) ) {
				// Inicializar WP_Filesystem.
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				WP_Filesystem();
				global $wp_filesystem;

				// Obtener el contenido del archivo.
				$content = $wp_filesystem->get_contents( $file_path );

				if ( false === $content ) {
					wp_die( esc_html__( 'Unable to read the file.', 'jc-logs' ) );
				}

				// Enviar encabezados.
				header( 'Content-Description: File Transfer' );
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
				header( 'Expires: 0' );
				header( 'Cache-Control: must-revalidate' );
				header( 'Pragma: public' );
				header( 'Content-Length: ' . strlen( $content ) );
				flush(); // Asegura que todos los buffers de salida se envíen antes de imprimir.

				echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			} else {
				wp_die( esc_html__( 'The file does not exist.', 'jc-logs' ) );
			}
		}
	}

	/**
	 * Función para eliminar un archivo de log.
	 */
	public function delete_log_file() {
		// Verificar nonce y capacidades.
		$jc_logs_nonce = isset( $_GET['jc_logs_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['jc_logs_nonce'] ) ) : '';
		if ( empty( $jc_logs_nonce ) || ! wp_verify_nonce( $jc_logs_nonce, 'jc_logs_delete' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'jc-logs' ) );
		}

		if ( isset( $_GET['file'] ) ) {
			$file      = sanitize_file_name( wp_unslash( $_GET['file'] ) );
			$file_path = $this->log_directory . $file . '.log';

			if ( file_exists( $file_path ) ) {
				// Usar wp_delete_file para eliminar el archivo de manera segura.
				wp_delete_file( $file_path );

				// Redirigir después de la eliminación exitosa, sin verificar el retorno.
				wp_safe_redirect( admin_url( 'tools.php?page=jc-logs&tab=explore' ) );
				exit;
			} else {
				// Si el archivo no existe, no se debería intentar eliminar.
				wp_die( esc_html__( 'The file does not exist.', 'jc-logs' ) );
			}
		}
	}

	/**
	 * Obtener el tamaño total de un directorio.
	 *
	 * @param string $directory Ruta del directorio.
	 * @return int Tamaño en bytes.
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

// Initialize the class.
JC_Log_Admin::get_instance();
