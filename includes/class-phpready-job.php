<?php
/**
 * Escaneo por tandas de TODO el sitio: cola de plugins + tema activo (y su padre).
 *
 * El navegador pide "escaneá unos segundos" una y otra vez; el servidor guarda dónde quedó,
 * así ninguna petición pasa del corte del hosting (30-60 s en compartidos).
 *
 * Estado en tres opciones de WordPress (separadas a propósito):
 *  - phpready_job        cola de ítems, progreso, contadores y mensajes (se escribe una vez por tanda)
 *  - phpready_job_files  lista de archivos del ítem en curso (una escritura por ítem)
 *  - phpready_job_cursor archivo en curso (chiquito, se escribe antes de CADA archivo: si el servidor
 *                   mata la petición, la siguiente tanda sabe qué archivo fue y lo salta)
 *  - phpready_job_facts  clases vistas hasta ahora (core + plugins); grande, se escribe solo cuando
 *                   una tanda aporta clases nuevas, nunca dentro de phpready_job
 *  - phpready_job_lock   (fila en wp_options, insertada de forma atómica) evita que dos pestañas o el
 *                   cron corran tandas a la vez
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHPReady_Job {

	/** Segundos de escaneo por petición. */
	const BUDGET_SECONDS = 8;

	public static function init() {
		add_action( 'wp_ajax_phpready_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_phpready_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_phpready_cancel', array( __CLASS__, 'ajax_cancel' ) );
	}

	public static function get() {
		$job = get_option( 'phpready_job', null );
		// Un estado guardado por una versión anterior del plugin no sirve: lo descartamos.
		if ( $job && ( ! is_array( $job ) || ! isset( $job['id'], $job['items'], $job['status'], $job['current_item'], $job['target'], $job['steps'] ) || ! is_array( $job['items'] ) || ! isset( $job['items'][ $job['current_item'] ] ) ) ) {
			self::clear();
			return null;
		}
		return $job;
	}

	private static function save( $job ) {
		$job['updated'] = time();
		update_option( 'phpready_job', $job, false );
	}

	private static function clear() {
		delete_option( 'phpready_job' );
		delete_option( 'phpready_job_files' );
		delete_option( 'phpready_job_cursor' );
		delete_option( 'phpready_job_facts' );
		// El candado NO se toca: si otra petición está a mitad de una tanda, es suyo; al guardar
		// verá que el job ya no es el mismo y descartará su resultado.
	}

	/** Valor del candado que tomó ESTA petición (hora|token). Solo se borra el propio. */
	private static $lock_value = null;

	/**
	 * Candado atómico: INSERT en wp_options (option_name es UNIQUE), así dos peticiones que llegan
	 * a la vez no pueden tomarlo las dos (un transient se lee y se escribe en dos pasos y sí pueden).
	 * Un candado viejo (petición matada sin shutdown) vence solo a los BUDGET_SECONDS + 30 s.
	 * Cada petición guarda un token y solo suelta el candado si sigue siendo el suyo: así el
	 * shutdown de una petición larga (un cron que después corre otras cosas) no suelta el de otra.
	 */
	public static function lock() {
		global $wpdb;
		$ttl   = self::BUDGET_SECONDS + 30;
		$value = time() . '|' . wp_generate_password( 12, false );
		for ( $try = 0; $try < 2; $try++ ) {
			$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'phpready_job_lock', $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- candado atómico
			if ( $ok ) {
				self::$lock_value = $value;
				wp_cache_delete( 'phpready_job_lock', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				return true;
			}
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'phpready_job_lock' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $since && time() - $since < $ttl ) {
				return false; // alguien lo tiene y está vigente
			}
			self::unlock( true ); // vencido: lo soltamos y reintentamos una vez
		}
		return false;
	}

	/** Suelta el candado propio; con $force, cualquiera (solo para uno vencido). */
	public static function unlock( $force = false ) {
		global $wpdb;
		if ( $force ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'phpready_job_lock' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} elseif ( self::$lock_value ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'phpready_job_lock', 'option_value' => self::$lock_value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::$lock_value = null;
		} else {
			return;
		}
		wp_cache_delete( 'phpready_job_lock', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/** Estado inicial de un escaneo nuevo. */
	private static function new_job( $target, $include_inactive ) {
		self::clear();
		update_option( 'phpready_job_facts', (array) get_option( self::core_facts_key(), array() ), false );
		return array(
			'id'           => wp_generate_uuid4(),
			'target'       => $target,
			'current'      => phpready_current_version(),
			'inactive'     => $include_inactive,
			'items'        => self::build_queue( $include_inactive ),
			'current_item' => 0,
			'status'       => 'running',
			'steps'        => 0,
			'started'      => time(),
		);
	}

	/** Lista de archivos de un ítem (determinista: se puede recalcular si se perdió). */
	private static function item_files( array $item ) {
		if ( 'core' === $item['type'] ) {
			return self::core_files();
		}
		if ( ! empty( $item['files'] ) ) {
			return $item['files'];
		}
		return $item['single'] ? array( $item['single'] ) : PHPReady_Scanner::list_php_files( $item['base'] );
	}

	/** Resumen de progreso para el JS / WP-CLI. */
	private static function progress( array $job, $wait = false ) {
		$cur = $job['items'][ $job['current_item'] ];
		return array(
			'done'      => 'done' === $job['status'],
			'wait'      => $wait,
			'item_name' => $cur['name'],
			'item_num'  => $job['current_item'] + 1,
			'items'     => count( $job['items'] ),
			'index'     => $cur['index'],
			'total'     => $cur['total'],
		);
	}

	public static function core_facts_key() {
		return 'phpready_core_facts_' . str_replace( '.', '_', get_bloginfo( 'version' ) );
	}

	/** Archivos del core que declaran clases: wp-includes y wp-admin/includes. */
	private static function core_files() {
		$files = array();
		foreach ( array( ABSPATH . WPINC, ABSPATH . 'wp-admin/includes' ) as $dir ) {
			if ( is_dir( $dir ) ) {
				$files = array_merge( $files, PHPReady_Scanner::list_php_files( $dir ) );
			}
		}
		return $files;
	}

	private static function check_request() {
		if ( ! current_user_can( phpready_cap() ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		check_ajax_referer( 'phpready_ajax', 'nonce' );
	}

	/** Qué hay que escanear: todos los plugins (menos este) + tema activo + tema padre. */
	public static function build_queue( $include_inactive = false ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php'; // WP-CLI no carga las funciones de admin
		}
		$items = array();
		$self  = plugin_basename( PHPREADY_DIR . 'upgravo.php' );

		// Primero el core de WordPress, solo para conocer sus clases (padres de muchas clases de
		// plugins). No produce hallazgos. Se cachea por versión de WP.
		if ( ! get_option( self::core_facts_key(), null ) ) {
			$items[] = array(
				'type'    => 'core',
				'key'     => 'wordpress',
				'name'    => 'WordPress ' . get_bloginfo( 'version' ),
				'version' => get_bloginfo( 'version' ),
				'active'  => true,
				'base'    => ABSPATH,
				'single'  => null,
			);
		}

		// Modo red: el Pro pasa la lista de plugins activos en cualquier sitio de la red.
		$network_active = apply_filters( 'phpready_active_plugins', null );
		foreach ( get_plugins() as $key => $data ) {
			if ( $key === $self ) {
				continue;
			}
			$single = ( '.' === dirname( $key ) );
			$active = is_plugin_active( $key ) || ( is_array( $network_active ) && in_array( $key, $network_active, true ) );
			if ( ! $include_inactive && ! $active ) {
				continue;
			}
			$items[] = array(
				'type'    => 'plugin',
				'key'     => $key,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => $active,
				'base'    => $single ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . '/' . dirname( $key ),
				'single'  => $single ? WP_PLUGIN_DIR . '/' . $key : null,
			);
		}

		// Must-use plugins (siempre activos, nadie los revisa) y drop-ins (advanced-cache.php,
		// object-cache.php, db.php...: código que corre antes que nada y rompe sin aviso).
		if ( is_dir( WPMU_PLUGIN_DIR ) && PHPReady_Scanner::list_php_files( WPMU_PLUGIN_DIR ) ) {
			$items[] = array(
				'type'    => 'mu',
				'key'     => 'mu-plugins',
				'name'    => __( 'Must-use plugins (mu-plugins)', 'upgravo' ),
				'version' => '',
				'active'  => true,
				'base'    => WPMU_PLUGIN_DIR,
				'single'  => null,
			);
		}
		$dropins = array();
		foreach ( array_keys( _get_dropins() ) as $f ) {
			if ( file_exists( WP_CONTENT_DIR . '/' . $f ) ) {
				$dropins[] = WP_CONTENT_DIR . '/' . $f;
			}
		}
		if ( $dropins ) {
			$items[] = array(
				'type'    => 'dropin',
				'key'     => 'drop-ins',
				'name'    => __( 'Drop-ins', 'upgravo' ) . ' (' . implode( ', ', array_map( 'basename', $dropins ) ) . ')',
				'version' => '',
				'active'  => true,
				'base'    => WP_CONTENT_DIR,
				'single'  => null,
				'files'   => $dropins,
			);
		}

		// Tema activo (y su padre). Modo red: el Pro pasa los temas que usa cualquier sitio.
		$stylesheets = array_unique( (array) apply_filters( 'phpready_theme_stylesheets', array( get_stylesheet() ) ) );
		$seen        = array();
		foreach ( $stylesheets as $ss ) {
			$theme = wp_get_theme( $ss );
			if ( ! $theme->exists() ) {
				continue;
			}
			foreach ( array( $theme, $theme->parent() ) as $i => $t ) {
				if ( ! $t || isset( $seen[ $t->get_stylesheet() ] ) ) {
					continue;
				}
				$seen[ $t->get_stylesheet() ] = true;
				$items[] = array(
					'type'    => 'theme',
					'key'     => $t->get_stylesheet(),
					'name'    => $t->get( 'Name' ) . ( $i ? ' (' . __( 'parent theme', 'upgravo' ) . ')' : '' ),
					'version' => $t->get( 'Version' ),
					'active'  => true,
					'base'    => $t->get_stylesheet_directory(),
					'single'  => null,
				);
			}
		}

		$upd_p = get_site_transient( 'update_plugins' );
		$upd_t = get_site_transient( 'update_themes' );
		foreach ( $items as &$it ) {
			$it['update'] = '';
			if ( 'plugin' === $it['type'] && ! empty( $upd_p->response[ $it['key'] ]->new_version ) ) {
				$it['update'] = $upd_p->response[ $it['key'] ]->new_version;
			} elseif ( 'theme' === $it['type'] && ! empty( $upd_t->response[ $it['key'] ]['new_version'] ) ) {
				$it['update'] = $upd_t->response[ $it['key'] ]['new_version'];
			}
			$it += array(
				'status'   => 'pending', // pending | running | done
				'total'    => 0,
				'index'    => 0,
				'errors'   => 0,
				'warnings' => 0,
				'messages' => array(),
				'skipped'  => array(),
				'failed'   => array(),
				'seconds'  => 0,
				'peak_mb'  => 0,
				'candidates' => array(),
				'callables'  => array(),
			);
		}
		return $items;
	}

	public static function ajax_start() {
		self::check_request();
		check_ajax_referer( 'phpready_ajax', 'nonce' );

		$target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
		if ( ! in_array( $target, phpready_target_versions(), true ) ) {
			wp_send_json_error( 'invalid version' );
		}

		$job = self::new_job( $target, ! empty( $_POST['inactive'] ) );
		self::save( $job );
		wp_send_json_success( array( 'items' => count( $job['items'] ) ) );
	}

	public static function ajax_step() {
		self::check_request();
		check_ajax_referer( 'phpready_ajax', 'nonce' );

		$r = self::step_once();
		if ( null === $r ) {
			// Nadie corriendo: o no hay escaneo, o lo terminó otro (el cron, otra pestaña) entre
			// dos pedidos del navegador. En ese caso le devolvemos "terminado" para que recargue.
			$job = self::get();
			if ( $job && 'done' === $job['status'] ) {
				wp_send_json_success( self::progress( $job ) );
			}
			wp_send_json_error( 'no scan in progress' );
		}
		wp_send_json_success( $r );
	}

	/** Una tanda. Devuelve el resumen de progreso. */
	public static function run_step( $job ) {
		// admin-ajax no sube el límite de memoria solo; el panel sí. Pedimos lo mismo que el panel.
		wp_raise_memory_limit( 'admin' );
		@set_time_limit( self::BUDGET_SECONDS + 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- la tanda dura 8 s; esto solo evita que un max_execution_time de 10 s la corte a mitad de archivo.

		$ci   = (int) $job['current_item'];
		$item = &$job['items'][ $ci ];

		// Lista de archivos del ítem: se arma al primer contacto; si se perdió (otra pestaña la borró
		// al terminar el ítem), se recalcula: es determinista.
		$files = array();
		try {
			if ( 'pending' === $item['status'] ) {
				$files = self::item_files( $item );
				update_option( 'phpready_job_files', $files, false );
				$item['total']  = count( $files );
				$item['status'] = 'running';
			} else {
				$files = get_option( 'phpready_job_files', array() );
				if ( ! $files && $item['total'] > 0 ) {
					$files = self::item_files( $item );
					update_option( 'phpready_job_files', $files, false );
				}
			}
		} catch ( \Throwable $e ) {
			// Directorio ilegible, symlink roto, plugin borrado a mitad del escaneo: el ítem queda
			// marcado como fallido y el escaneo sigue con el siguiente.
			$item['failed'][] = $e->getMessage();
			$item['status']   = 'running';
			$item['total']    = 0;
			$files            = array();
		}

		$index = (int) $item['index'];

		// ¿La tanda anterior murió a mitad de un archivo? El cursor lo delata. Como el resultado de esa
		// tanda no se guardó, la repetimos desde el mismo punto pero saltando el archivo culpable
		// (que puede estar en cualquier posición de la tanda, no solo al principio).
		$skip   = -1;
		$cursor = get_option( 'phpready_job_cursor', null );
		if ( is_array( $cursor ) && (int) $cursor['item'] === $ci && (int) $cursor['index'] >= $index ) {
			$skip = (int) $cursor['index'];
		}

		$res = PHPReady_Scanner::scan_batch(
			$files,
			$index,
			$job['target'],
			$item['base'],
			self::BUDGET_SECONDS,
			function ( $i, $rel ) use ( $ci ) {
				update_option( 'phpready_job_cursor', array( 'item' => $ci, 'index' => $i, 'rel' => $rel ), false );
			},
			'core' === $item['type'],
			$skip,
			__( 'the server aborted the request while analyzing it', 'upgravo' )
		);

		if ( $res['facts'] ) {
			$facts = PHPReady_Classes::merge_facts( (array) get_option( 'phpready_job_facts', array() ), $res['facts'] );
			update_option( 'phpready_job_facts', $facts, false );
		}
		$item['candidates'] = array_merge( $item['candidates'], $res['candidates'] );
		$item['callables']  = array_merge( $item['callables'], $res['callables'] );

		$item['index']     = $res['next_index'];
		$item['errors']   += $res['errors'];
		$item['warnings'] += $res['warnings'];
		$item['messages']  = array_merge( $item['messages'], $res['messages'] );
		$item['skipped']   = array_merge( $item['skipped'], $res['skipped'] );
		$item['failed']    = array_merge( $item['failed'], $res['failed'] );
		$item['seconds']  += $res['seconds'];
		$item['peak_mb']   = max( $item['peak_mb'], $res['peak_mb'] );
		$job['steps']++;

		if ( $item['index'] >= $item['total'] ) {
			$item['status'] = 'done';
			if ( 'core' === $item['type'] ) {
				update_option( self::core_facts_key(), (array) get_option( 'phpready_job_facts', array() ), false );
			}
			if ( $ci + 1 < count( $job['items'] ) ) {
				$job['current_item'] = $ci + 1;
				delete_option( 'phpready_job_files' );
			} else {
				$job['status']   = 'done';
				$job['finished'] = time();
				self::finish( $job );
			}
		}
		unset( $item );

		// Si mientras corríamos esta tanda el usuario canceló o arrancó otro escaneo, lo nuestro ya no
		// vale: no pisamos el estado nuevo.
		$live = get_option( 'phpready_job', null );
		if ( ! is_array( $live ) || empty( $live['id'] ) || $live['id'] !== $job['id'] ) {
			return array( 'done' => true, 'wait' => false, 'item_name' => '', 'item_num' => 0, 'items' => 0, 'index' => 0, 'total' => 0 );
		}
		self::save( $job );
		// El cursor se borra DESPUÉS de guardar: si algo revienta entre el escaneo y el guardado, el
		// reintento sigue sabiendo qué archivo saltar.
		delete_option( 'phpready_job_cursor' );
		if ( 'done' === $job['status'] ) {
			delete_option( 'phpready_job_files' );
			delete_option( 'phpready_job_facts' );
		}

		return self::progress( $job );
	}

	/** Al terminar todo: cruzamos candidatos contra todas las clases vistas (core + todos los plugins). */
	private static function finish( &$job ) {
		$facts = (array) get_option( 'phpready_job_facts', array() );
		foreach ( $job['items'] as &$it ) {
			$extra = array_merge(
				PHPReady_Classes::resolve_candidates( $it['candidates'], $facts ),
				PHPReady_Classes::callable_messages( $it['callables'] )
			);
			$it['messages']  = array_merge( $it['messages'], $extra );
			$it['warnings'] += count( $extra );
			$it['candidates'] = array();
			$it['callables']  = array();
		}
		unset( $it );
	}

	/**
	 * Arranca un escaneo nuevo sin correr ninguna tanda (para procesos en segundo plano que
	 * después llaman a step_once() hasta que 'done' sea true).
	 */
	public static function start( $target, $include_inactive, $origin = 'manual' ) {
		$job           = self::new_job( $target, $include_inactive );
		$job['origin'] = $origin;
		self::save( $job );
		return $job;
	}

	/**
	 * Corre UNA tanda del escaneo en curso respetando el candado (mismo comportamiento que una
	 * petición AJAX). Devuelve el progreso, o null si no hay escaneo en curso.
	 */
	public static function step_once() {
		$job = self::get();
		if ( ! $job || 'running' !== $job['status'] ) {
			return null;
		}
		if ( ! self::lock() ) {
			return self::progress( $job, true );
		}
		register_shutdown_function( array( __CLASS__, 'unlock' ) );
		$r = self::run_step( $job );
		self::unlock();
		return $r;
	}

	/** Para WP-CLI: arranca y corre tandas hasta terminar, sin AJAX. */
	public static function run_sync( $target, $include_inactive, $progress = null ) {
		self::save( self::new_job( $target, $include_inactive ) );
		while ( true ) {
			$job = self::get();
			if ( ! $job || 'running' !== $job['status'] ) {
				break;
			}
			if ( ! self::lock() ) {
				sleep( 2 ); // otra petición (panel o cron) está con una tanda: esperamos
				continue;
			}
			$r = self::run_step( $job );
			self::unlock();
			if ( $progress ) {
				call_user_func( $progress, $r );
			}
		}
		return self::get();
	}

	public static function ajax_cancel() {
		self::check_request();
		check_ajax_referer( 'phpready_ajax', 'nonce' );
		self::clear();
		wp_send_json_success();
	}
}
