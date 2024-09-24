<?php
namespace JC_Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

class JC_Log_Admin {

	private static $instance = null;

	private $log_directory;
	private $security_token;

	private function __construct() {
		// Inicializar variables dependientes de WordPress.
		add_action( 'init', array( $this, 'initialize' ) );

		// Hooks para el área de administración.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_jc_logs_download', array( $this, 'download_log_file' ) );
		add_action( 'admin_post_jc_logs_delete', array( $this, 'delete_log_file' ) );
	}

	/**
	 * Obtener la instancia única de la clase.
	 *
	 * @return JC_Log_Admin La instancia única de JC_Log_Admin.
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
	 * Función para registrar el menú en el área de administración.
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
	 * Extrae el nombre base del log sin la fecha y el token aleatorio.
	 *
	 * @param string $file_name Nombre completo del archivo de log.
	 * @return string Nombre base del log.
	 */
	private function extract_log_name( $file_name ) {
		// Remover la extensión .log.
		$base_name = str_replace( '.log', '', $file_name );

		// Patrón para coincidir con {nombre_log}-{fecha}-{cadena_aleatoria}.
		if ( preg_match( '/(.*)-\d{4}-\d{2}-\d{2}-[a-f0-9]{10}$/', $base_name, $matches ) ) {
			return $matches[1]; // Retorna la parte del nombre del log.
		} else {
			// Si el patrón no coincide, retornar el nombre original sin extensión.
			return $base_name;
		}
	}

	/**
	 * Función para mostrar la página de logs.
	 */
	public function logs_page() {
		// Verificar capacidad.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verificar si se ha seleccionado un archivo para ver.
		if ( isset( $_GET['file'] ) ) {
			// Mostrar el contenido del log seleccionado.
			$file      = sanitize_file_name( wp_unslash( $_GET['file'] ) );
			$file_path = $this->log_directory . $file;

			if ( file_exists( $file_path ) ) {
				// URLs para acciones.
				$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $file ) ), 'jc_logs_download', 'jc_logs_nonce' );
				$delete_url   = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $file ) ), 'jc_logs_delete', 'jc_logs_nonce' );
				$back_url     = admin_url( 'tools.php?page=jc-logs' );

				// Título y botones.
				echo '<div class="wrap">';
				echo '<h1 style="display: flex; justify-content: space-between; align-items: center;">';
				echo '<span>' . sprintf( esc_html__( 'Viendo el registro del archivo %s', 'jc-logs' ), esc_html( $file ) ) . '</span>';
				echo '<span>';
				echo '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Descargar', 'jc-logs' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( '¿Estás seguro de que deseas eliminar este archivo?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Eliminar', 'jc-logs' ) . '</a>';
				echo '</span>';
				echo '</h1>';

				// Contenido del log.
				echo '<pre style="background-color: #fff; padding: 20px; border: 1px solid #ccc; max-width: 100%; overflow: auto;">';
				$content = file_get_contents( $file_path );
				echo esc_html( $content );
				echo '</pre>';

				echo '</div>';
			} else {
				echo '<div class="wrap">';
				echo '<h1>' . esc_html__( 'Error', 'jc-logs' ) . '</h1>';
				echo '<p>' . esc_html__( 'El archivo no existe.', 'jc-logs' ) . '</p>';
				echo '<a class="button" href="' . esc_url( admin_url( 'tools.php?page=jc-logs' ) ) . '">' . esc_html__( 'Volver a la lista', 'jc-logs' ) . '</a>';
				echo '</div>';
			}
		} else {
			// Mostrar la lista de logs como antes.
			$log_files = glob( $this->log_directory . '*.log' );

			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Logs', 'jc-logs' ) . '</h1>';
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Nombre Log', 'jc-logs' ) . '</th>';
			echo '<th>' . esc_html__( 'Fecha Creación', 'jc-logs' ) . '</th>';
			echo '<th>' . esc_html__( 'Fecha Modificación', 'jc-logs' ) . '</th>';
			echo '<th>' . esc_html__( 'Tamaño Archivo', 'jc-logs' ) . '</th>';
			echo '<th>' . esc_html__( 'Acciones', 'jc-logs' ) . '</th>';
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
							'file' => rawurlencode( $file_name ),
						),
						admin_url( 'tools.php' )
					);
					// Extraer el nombre base del log.
					$log_name = $this->extract_log_name( $file_name );

					echo '<tr>';
					echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html( $log_name ) . '</a></td>';
					echo '<td>' . esc_html( $creation_time ) . '</td>';
					echo '<td>' . esc_html( $modification_time ) . '</td>';
					echo '<td>' . esc_html( $file_size ) . '</td>';
					echo '<td>';
					echo '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Descargar', 'jc-logs' ) . '</a> ';
					echo '<a class="button delete-log" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( '¿Estás seguro de que deseas eliminar este archivo?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Eliminar', 'jc-logs' ) . '</a>';
					echo '</td>';
					echo '</tr>';
				}
			} else {
				echo '<tr>';
				echo '<td colspan="5">' . esc_html__( 'No hay logs disponibles.', 'jc-logs' ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
			echo '</div>';
		}
	}

	/**
	 * Función para descargar un archivo de log.
	 */
	public function download_log_file() {
		// Verificar nonce y capacidad.
		if ( ! isset( $_GET['jc_logs_nonce'] ) || ! wp_verify_nonce( $_GET['jc_logs_nonce'], 'jc_logs_download' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'No tienes permiso para realizar esta acción.', 'jc-logs' ) );
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
				wp_die( __( 'El archivo no existe.', 'jc-logs' ) );
			}
		}
	}

	/**
	 * Función para eliminar un archivo de log.
	 */
	public function delete_log_file() {
		// Verificar nonce y capacidad.
		if ( ! isset( $_GET['jc_logs_nonce'] ) || ! wp_verify_nonce( $_GET['jc_logs_nonce'], 'jc_logs_delete' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'No tienes permiso para realizar esta acción.', 'jc-logs' ) );
		}

		if ( isset( $_GET['file'] ) ) {
			$file      = sanitize_file_name( wp_unslash( $_GET['file'] ) );
			$file_path = $this->log_directory . $file;

			if ( file_exists( $file_path ) ) {
				unlink( $file_path );
				wp_redirect( admin_url( 'tools.php?page=jc-logs' ) );
				exit;
			} else {
				wp_die( __( 'El archivo no existe.', 'jc-logs' ) );
			}
		}
	}
}
