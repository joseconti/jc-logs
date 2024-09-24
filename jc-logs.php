<?php
/*
Plugin Name: JC Logs
Description: Librería para generar y gestionar logs en WordPress.
Version: 1.0.0
Author: Tu Nombre
Text Domain: jc-logs
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

if ( ! class_exists( 'JC_Logs' ) ) {

	class JC_Logs {

		private $log_directory;
		private $security_token;

		public function __construct() {
			// Mueve la asignación de $security_token a un hook.
			add_action( 'init', array( $this, 'initialize' ) );

			// Hooks para el área de administración.
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_post_jc_logs_download', array( $this, 'download_log_file' ) );
			add_action( 'admin_post_jc_logs_delete', array( $this, 'delete_log_file' ) );
		}

		/**
		 * Inicializa variables que dependen de WordPress.
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
		 * Función para mostrar la página de logs.
		 */
		public function logs_page() {
			// Verificar capacidad.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// Obtener lista de archivos de log.
			$log_files = glob( $this->log_directory . '*.log' );

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Logs', 'jc-logs' ); ?></h1>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nombre Log', 'jc-logs' ); ?></th>
							<th><?php esc_html_e( 'Fecha Creación', 'jc-logs' ); ?></th>
							<th><?php esc_html_e( 'Fecha Modificación', 'jc-logs' ); ?></th>
							<th><?php esc_html_e( 'Tamaño Archivo', 'jc-logs' ); ?></th>
							<th><?php esc_html_e( 'Acciones', 'jc-logs' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $log_files ) ) : ?>
							<?php foreach ( $log_files as $file ) : ?>
								<?php
								$file_name         = basename( $file );
								$creation_time     = gmdate( 'Y-m-d H:i:s', filectime( $file ) );
								$modification_time = gmdate( 'Y-m-d H:i:s', filemtime( $file ) );
								$file_size         = size_format( filesize( $file ), 2 );
								$download_url      = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $file_name ) ), 'jc_logs_download', 'jc_logs_nonce' );
								$delete_url        = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $file_name ) ), 'jc_logs_delete', 'jc_logs_nonce' );
								$view_url          = add_query_arg(
									array(
										'page' => 'jc-logs',
										'view' => 'log',
										'file' => rawurlencode( $file_name ),
									),
									admin_url( 'tools.php' )
								);
								?>
								<tr>
									<td><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $file_name ); ?></a></td>
									<td><?php echo esc_html( $creation_time ); ?></td>
									<td><?php echo esc_html( $modification_time ); ?></td>
									<td><?php echo esc_html( $file_size ); ?></td>
									<td>
										<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Descargar', 'jc-logs' ); ?></a>
										<a class="button delete-log" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( '¿Estás seguro de que deseas eliminar este archivo?', 'jc-logs' ); ?>');"><?php esc_html_e( 'Eliminar', 'jc-logs' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="5"><?php esc_html_e( 'No hay logs disponibles.', 'jc-logs' ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<?php
				// Vista del contenido del log.
				if ( isset( $_GET['view'] ) && $_GET['view'] === 'log' && isset( $_GET['file'] ) ) {
					$file      = sanitize_file_name( wp_unslash( $_GET['file'] ) );
					$file_path = $this->log_directory . $file;

					if ( file_exists( $file_path ) ) {
						echo '<h2>' . esc_html( $file ) . '</h2>';
						echo '<pre style="background-color: #fff; padding: 20px; border: 1px solid #ccc;">';
						$content = file_get_contents( $file_path );
						echo esc_html( $content );
						echo '</pre>';
					} else {
						echo '<p>' . esc_html__( 'El archivo no existe.', 'jc-logs' ) . '</p>';
					}
				}
				?>
			</div>
			<?php
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

		/**
		 * Función para registrar un log.
		 *
		 * @param string $nombre_log  Nombre del log.
		 * @param string $texto       Texto del log.
		 * @param string $tipo        Tipo de log: Info, warning, error, critical.
		 */
		public function log( $nombre_log, $texto, $tipo = 'Info' ) {
			// Verificar que el directorio y el token estén inicializados.
			if ( empty( $this->log_directory ) || empty( $this->security_token ) ) {
				$this->initialize();
			}
			$allowed_types = array( 'Info', 'warning', 'error', 'critical' );
			if ( ! in_array( $tipo, $allowed_types, true ) ) {
				$tipo = 'Info';
			}

			$nombre_log    = sanitize_file_name( $nombre_log );
			$fecha         = gmdate( 'Y-m-d' );
			$random_string = substr( $this->security_token, 0, 10 );
			$file_name     = "{$nombre_log}-{$fecha}-{$random_string}.log";
			$file_path     = $this->log_directory . $file_name;

			// Verificar el tamaño del archivo.
			if ( file_exists( $file_path ) && filesize( $file_path ) > 5 * MB_IN_BYTES ) { // 5MB límite de ejemplo.
				$version = 1;
				do {
					$file_name = "{$nombre_log}-{$version}-{$fecha}-{$random_string}.log";
					$file_path = $this->log_directory . $file_name;
					++$version;
				} while ( file_exists( $file_path ) && filesize( $file_path ) > 5 * MB_IN_BYTES );
			}

			$current_time = current_time( 'c' );
			$log_entry    = "{$current_time} {$tipo} {$texto}" . PHP_EOL;
			file_put_contents( $file_path, $log_entry, FILE_APPEND | LOCK_EX );
		}
	}
}

// Instanciar la clase después de que WordPress esté listo.
function jc_logs_init() {
	if ( ! isset( $GLOBALS['jc_logs'] ) ) {
		$GLOBALS['jc_logs'] = new JC_Logs();
	}
}
add_action( 'plugins_loaded', 'jc_logs_init' );