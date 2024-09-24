<?php
namespace JC_Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class JC_Log_Admin {

	private static $instance = null;

	private $log_directory;
	private $security_token;

	private function __construct() {
		// Initialize variables dependent on WordPress.
		add_action( 'init', array( $this, 'initialize' ) );

		// Hooks for the admin area.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_jc_logs_download', array( $this, 'download_log_file' ) );
		add_action( 'admin_post_jc_logs_delete', array( $this, 'delete_log_file' ) );
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
	}

	/**
	 * Function to register the menu in the admin area.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'Logs', 'jc-logs' ),
			__( 'Logs', 'jc-logs' ),
			'manage_options',
			'jc-logs',
			array( $this, 'logs_page' )
		);
	}

	/**
	 * Extracts the base log name without the date and random token.
	 *
	 * @param string $file_name Full name of the log file.
	 * @return string Base log name.
	 */
	private function extract_log_name( $file_name ) {
		// Remove the .log extension.
		$base_name = str_replace( '.log', '', $file_name );

		// Pattern to match {log_name}-{date}-{random_string}.
		if ( preg_match( '/(.*)-\d{4}-\d{2}-\d{2}-[a-f0-9]{10}$/', $base_name, $matches ) ) {
			return $matches[1]; // Return the base log name.
		} else {
			// If the pattern doesn't match, return the original name without extension.
			return $base_name;
		}
	}

	/**
	 * Function to display the logs page.
	 */
	public function logs_page() {
		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

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
	}

	/**
	 * Render the tabs at the top of the page.
	 *
	 * @param string $current Current active tab.
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
	 * Render the explore page, which lists logs or shows log content.
	 */
	private function render_explore_page() {
		// Check if a log file is selected to view.
		if ( isset( $_GET['file'] ) ) {
			// Show the selected log content.
			$this->render_log_content();
		} else {
			// Show the list of logs.
			$this->render_log_list();
		}
	}

	/**
	 * Render the list of logs.
	 */
	private function render_log_list() {
		$log_files = glob( $this->log_directory . '*.log' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Logs', 'jc-logs' ) . '</h1>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Log Name', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'Creation Date', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'Modification Date', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'File Size', 'jc-logs' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'jc-logs' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		if ( ! empty( $log_files ) ) {
			foreach ( $log_files as $file ) {
				$file_name         = basename( $file );
				$creation_time     = gmdate( 'Y-m-d H:i:s', filectime( $file ) );
				$modification_time = gmdate( 'Y-m-d H:i:s', filemtime( $file ) );
				$file_size         = size_format( filesize( $file ), 2 );
				$download_url      = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $file_name ) ), 'jc_logs_download', 'jc_logs_nonce' );
				$delete_url        = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $file_name ) ), 'jc_logs_delete', 'jc_logs_nonce' );
				$view_url          = add_query_arg(
					array(
						'page' => 'jc-logs',
						'tab'  => 'explore',
						'file' => rawurlencode( $file_name ),
					),
					admin_url( 'tools.php' )
				);
				// Extract the base log name.
				$log_name = $this->extract_log_name( $file_name );

				echo '<tr>';
				echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html( $log_name ) . '</a></td>';
				echo '<td>' . esc_html( $creation_time ) . '</td>';
				echo '<td>' . esc_html( $modification_time ) . '</td>';
				echo '<td>' . esc_html( $file_size ) . '</td>';
				echo '<td>';
				echo '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'jc-logs' ) . '</a> ';
				echo '<a class="button delete-log" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this file?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a>';
				echo '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr>';
			echo '<td colspan="5">' . esc_html__( 'No logs available.', 'jc-logs' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Render the content of a selected log file.
	 */
	private function render_log_content() {
		$file      = sanitize_file_name( wp_unslash( $_GET['file'] ) );
		$file_path = $this->log_directory . $file;

		if ( file_exists( $file_path ) ) {
			// URLs for actions.
			$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $file ) ), 'jc_logs_download', 'jc_logs_nonce' );
			$delete_url   = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $file ) ), 'jc_logs_delete', 'jc_logs_nonce' );
			$back_url     = admin_url( 'tools.php?page=jc-logs&tab=explore' );

			// Title and buttons.
			echo '<div class="wrap">';
			echo '<h1 style="display: flex; justify-content: space-between; align-items: center;">';
			echo '<span>' . sprintf( esc_html__( 'Viewing log file: %s', 'jc-logs' ), esc_html( $file ) ) . '</span>';
			echo '<span>';
			echo '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'jc-logs' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this file?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a>';
			echo '</span>';
			echo '</h1>';

			// Log content.
			echo '<pre style="background-color: #fff; padding: 20px; border: 1px solid #ccc; max-width: 100%; overflow: auto;">';
			$content = file_get_contents( $file_path );
			echo esc_html( $content );
			echo '</pre>';

			echo '</div>';
		} else {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Error', 'jc-logs' ) . '</h1>';
			echo '<p>' . esc_html__( 'The file does not exist.', 'jc-logs' ) . '</p>';
			echo '<a class="button" href="' . esc_url( admin_url( 'tools.php?page=jc-logs&tab=explore' ) ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			echo '</div>';
		}
	}

	/**
	 * Render the settings page.
	 */
	private function render_settings_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Settings', 'jc-logs' ) . '</h1>';
		// You can add settings fields here in the future.
		echo '</div>';
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
}
