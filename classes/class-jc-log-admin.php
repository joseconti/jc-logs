<?php
namespace JC_Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class JC_Log_Admin {

	private static $instance = null;
	private $log_directory;
	private $logs_per_page = 20; // Número de logs por página.

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
	 * Inicializar el directorio de logs.
	 */
	public function initialize() {
		$upload_dir          = wp_upload_dir();
		$this->log_directory = trailingslashit( $upload_dir['basedir'] ) . 'jc-logs/';

		// Crear el directorio de logs si no existe.
		if ( ! file_exists( $this->log_directory ) ) {
			wp_mkdir_p( $this->log_directory );
		}
	}

	/**
	 * Función para añadir el menú en el área de administración.
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
	 * Función para mostrar la página de logs.
	 */
	public function logs_page() {
		// Verificar capacidades.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Iniciar el contenedor.
		echo '<div class="wrap">';

		// Añadir el título principal.
		echo '<h1>' . esc_html__( 'JC Logs', 'jc-logs' ) . '</h1>';

		// Determinar la pestaña actual.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'explore';

		// Mostrar las pestañas.
		$this->render_tabs( $tab );

		// Manejar el contenido basado en la pestaña activa.
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

		// Cerrar el contenedor.
		echo '</div>';
	}

	/**
	 * Renderizar las pestañas en la parte superior de la página.
	 *
	 * @param string $current Pestaña activa.
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
	 * Renderizar la página de exploración, que lista los logs o muestra el contenido de un log.
	 */
	private function render_explore_page() {
		// Verificar si se seleccionó un archivo de log para ver.
		if ( isset( $_GET['file'] ) ) {
			// Mostrar el contenido del archivo de log seleccionado.
			$this->render_log_content();
		} elseif ( isset( $_GET['log_name'] ) ) {
			// Mostrar el contenido del log seleccionado desde la base de datos.
			$this->render_log_content_database();
		} else {
			// Mostrar la lista de logs con paginación.
			$this->render_log_list();
		}
	}

	/**
	 * Renderizar la lista de logs con paginación.
	 */
	private function render_log_list() {
		// Obtener logs desde archivos.
		$log_files = glob( $this->log_directory . '*.log' );

		// Obtener logs desde la base de datos.
		global $wpdb;
		$table_name    = $wpdb->prefix . 'jc_logs';
		$database_logs = $wpdb->get_results( "SELECT log_name, MIN(timestamp) as creation_time, MAX(timestamp) as modification_time FROM {$table_name} GROUP BY log_name" );

		// Preparar un array para contener todos los logs.
		$all_logs = array();

		// Procesar logs de archivos.
		if ( ! empty( $log_files ) ) {
			foreach ( $log_files as $file ) {
				$file_name         = basename( $file );
				$creation_time     = gmdate( 'Y-m-d H:i:s', filectime( $file ) );
				$modification_time = gmdate( 'Y-m-d H:i:s', filemtime( $file ) );
				$file_size         = filesize( $file );
				$log_name          = $this->extract_log_name( $file_name );

				// Extraer la fecha del nombre del archivo
				// Suponiendo que el formato es {log_name}-YYYY-MM-DD-{random_string}.log
				$date = substr( $file_name, strlen( $log_name ) + 1, 10 ); // Extrae YYYY-MM-DD

				// Identificar si ya existe una entrada para este log_name y date
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
				// Actualizar la fecha de modificación si el archivo actual es más reciente
				if ( strtotime( $modification_time ) > strtotime( $all_logs[ $key ]['modification_time'] ) ) {
					$all_logs[ $key ]['modification_time'] = $modification_time;
				}
			}
		}

		// Procesar logs de la base de datos.
		if ( ! empty( $database_logs ) ) {
			foreach ( $database_logs as $log ) {
				$log_name          = $log->log_name;
				$creation_time     = $log->creation_time;
				$modification_time = $log->modification_time;
				// Dado que el tamaño del archivo no aplica, establecer en '-'.
				$file_size = '-';

				$all_logs[] = array(
					'source'            => 'database',
					'log_name'          => $log_name,
					'file_names'        => array(),
					'creation_time'     => $creation_time,
					'modification_time' => $modification_time,
					'file_size'         => $file_size,
				);
			}
		}

		// Convertir el array asociativo a un array indexado para usort
		$all_logs = array_values( $all_logs );

		// Ordenar los logs por fecha de modificación descendente
		usort(
			$all_logs,
			function ( $a, $b ) {
				return strtotime( $b['modification_time'] ) - strtotime( $a['modification_time'] );
			}
		);

		// Implementar Paginación
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$total_logs   = count( $all_logs );
		$total_pages  = ceil( $total_logs / $this->logs_per_page );

		// Obtener los logs para la página actual.
		$offset       = ( $current_page - 1 ) * $this->logs_per_page;
		$logs_to_show = array_slice( $all_logs, $offset, $this->logs_per_page );

		// Mostrar todos los logs en una tabla.
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
					// Mostrar solo el primer archivo en las acciones
					$file_name    = $log['file_names'][0];
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

					// Hacer que el nombre del log sea clicable
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

					// Hacer que el nombre del log sea clicable
					$log_name_display = '<a href="' . esc_url( $view_url ) . '">' . esc_html( $log_name ) . '</a>';
				}

				// Formatear fechas para la visualización
				$creation_time_display     = ! empty( $creation_time ) ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $creation_time ) ) ) : '-';
				$modification_time_display = ! empty( $modification_time ) ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $modification_time ) ) ) : '-';

				// Formatear tamaño del archivo
				if ( 'file' === $source ) {
					$file_size_display = size_format( $file_size, 2 );
				} else {
					$file_size_display = esc_html( $file_size );
				}

				echo '<tr>';
				echo '<td>' . $log_name_display . '</td>';
				echo '<td>' . esc_html( ucfirst( $source ) ) . '</td>';
				echo '<td>' . $creation_time_display . '</td>';
				echo '<td>' . $modification_time_display . '</td>';
				echo '<td>' . esc_html( $file_size_display ) . '</td>';
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

		// Mostrar Paginación
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav top">';
			echo '<div class="tablenav-pages">';
			echo paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => __( '&laquo;', 'jc-logs' ),
					'next_text' => __( '&raquo;', 'jc-logs' ),
					'total'     => $total_pages,
					'current'   => $current_page,
				)
			);
			echo '</div>';
			echo '</div>';
		}
	}

	/**
	 * Extraer el nombre base del log sin la fecha y el sufijo aleatorio.
	 *
	 * @param string $file_name Nombre completo del archivo de log.
	 * @return string Nombre base del log.
	 */
	private function extract_log_name( $file_name ) {
		// Remover la extensión .log
		$base_name = str_replace( '.log', '', $file_name );

		// Patrón para coincidir con {log_name}-YYYY-MM-DD-{random_string}
		if ( preg_match( '/^(.*)-\d{4}-\d{2}-\d{2}-[a-f0-9]{10}$/', $base_name, $matches ) ) {
			return $matches[1]; // Retornar el nombre base del log.
		} elseif ( preg_match( '/^(.*)-\d{4}-\d{2}-\d{2}$/', $base_name, $matches ) ) {
			return $matches[1]; // Retornar el nombre base del log sin sufijo aleatorio.
		} else {
			return $base_name;
		}
	}

	/**
	 * Renderizar el contenido de un archivo de log seleccionado.
	 */
	private function render_log_content() {
		$file      = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$file_path = $this->log_directory . $file;

		if ( file_exists( $file_path ) ) {
			// URLs para acciones.
			$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_download&file=' . rawurlencode( $file ) ), 'jc_logs_download', 'jc_logs_nonce' );
			$delete_url   = wp_nonce_url( admin_url( 'admin-post.php?action=jc_logs_delete&file=' . rawurlencode( $file ) ), 'jc_logs_delete', 'jc_logs_nonce' );
			$back_url     = admin_url( 'tools.php?page=jc-logs&tab=explore' );

			// Título y botones.
			echo '<h2>' . sprintf( esc_html__( 'Viewing log file: %s', 'jc-logs' ), esc_html( $file ) ) . '</h2>';
			echo '<p>';
			echo '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'jc-logs' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $delete_url ) . '" style="background-color: #dc3232; color: #fff;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this file?', 'jc-logs' ) ) . '\');">' . esc_html__( 'Delete', 'jc-logs' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			echo '</p>';

			// Contenido del log.
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
	 * Renderizar el contenido de un log seleccionado desde la base de datos.
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

		// Recuperar entradas de log desde la base de datos.
		$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE log_name = %s ORDER BY timestamp DESC", $log_name ) );

		if ( ! empty( $logs ) ) {
			echo '<h2>' . sprintf( esc_html__( 'Viewing log: %s', 'jc-logs' ), esc_html( $log_name ) ) . '</h2>';
			echo '<p>';
			$back_url = admin_url( 'tools.php?page=jc-logs&tab=explore' );
			echo '<a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to list', 'jc-logs' ) . '</a>';
			echo '</p>';

			// Mostrar las entradas de log en una tabla.
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
	 * Registrar configuraciones, secciones y campos para la página de configuración.
	 */
	public function register_settings() {
		// Registrar configuraciones.
		register_setting( 'jc_logs_settings', 'jc_logs_enable_logging' );
		register_setting( 'jc_logs_settings', 'jc_logs_storage_method' );
		register_setting( 'jc_logs_settings', 'jc_logs_retention_days' );

		// Añadir sección de configuraciones.
		add_settings_section(
			'jc_logs_main_section',
			__( 'Log Settings', 'jc-logs' ),
			null,
			'jc_logs_settings_page'
		);

		// Añadir campos de configuraciones.
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
	 * Renderizar la página de configuraciones.
	 */
	private function render_settings_page() {
		// Obtener la ruta del directorio de logs y el tamaño.
		$log_directory  = $this->log_directory;
		$directory_size = $this->get_directory_size( $log_directory );

		echo '<h2>' . esc_html__( 'Log Settings', 'jc-logs' ) . '</h2>';

		echo '<form method="post" action="options.php">';
		// Salida de campos de seguridad para la configuración registrada "jc_logs_settings".
		settings_fields( 'jc_logs_settings' );
		// Salida de secciones de configuración y sus campos.
		do_settings_sections( 'jc_logs_settings_page' );
		// Salida del botón de guardar configuraciones.
		submit_button();
		echo '</form>';

		// Mostrar ubicación y tamaño del directorio.
		echo '<h2>' . esc_html__( 'Location', 'jc-logs' ) . '</h2>';
		echo '<p>' . esc_html__( 'Log files are stored in this directory:', 'jc-logs' ) . ' <code>' . esc_html( $log_directory ) . '</code></p>';
		echo '<p>' . esc_html__( 'Directory size:', 'jc-logs' ) . ' ' . esc_html( size_format( $directory_size, 2 ) ) . '</p>';
	}

	/**
	 * Función para descargar un archivo de log.
	 */
	public function download_log_file() {
		// Verificar nonce y capacidades.
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
	 * Función para eliminar un archivo de log.
	 */
	public function delete_log_file() {
		// Verificar nonce y capacidades.
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
	 * Función para eliminar logs desde la base de datos.
	 */
	public function delete_log_database() {
		// Verificar nonce y capacidades.
		if ( ! isset( $_GET['jc_logs_nonce'] ) || ! wp_verify_nonce( $_GET['jc_logs_nonce'], 'jc_logs_delete_database' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'jc-logs' ) );
		}

		if ( isset( $_GET['log_name'] ) ) {
			$log_name = sanitize_text_field( wp_unslash( $_GET['log_name'] ) );

			global $wpdb;
			$table_name = $wpdb->prefix . 'jc_logs';

			// Eliminar entradas de la base de datos.
			$wpdb->delete( $table_name, array( 'log_name' => $log_name ), array( '%s' ) );

			wp_redirect( admin_url( 'tools.php?page=jc-logs&tab=explore' ) );
			exit;
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

// Inicializar la clase.
JC_Log_Admin::get_instance();
