<?php
/**
 * Lo que rodea al escaneo: resumen "qué hacer primero", export CSV, prueba en Salud del sitio,
 * comando WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHPReady_Extras {

	public static function init() {
		add_action( 'admin_post_phpready_csv', array( __CLASS__, 'csv' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health_register' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugins_screen_meta' ), 20, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'upgravo scan', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * ¿La ruta es una librería de terceros incluida en el plugin (no código del autor)?
	 * Solo carpetas que casi siempre son ajenas. `lib/` NO: en Wordfence, Jetpack o All-in-One WP
	 * Migration es el código propio del plugin, y decirle al cliente que "lo arregla la librería"
	 * sería falso.
	 */
	public static function is_bundled( $rel ) {
		return (bool) preg_match( '#(^|/)(vendor|vendor_prefixed|vendor-prefixed|third-party|third_party|node_modules|dependencies|deps)/#i', $rel );
	}

	/**
	 * Evalúa todos los ítems de un job terminado una sola vez. Devuelve filas listas para la
	 * pantalla, el CSV, Salud del sitio y WP-CLI, para que todos cuenten lo mismo.
	 */
	public static function evaluate( array $job ) {
		$rows = array();
		foreach ( $job['items'] as $i => $it ) {
			if ( 'core' === $it['type'] ) {
				continue;
			}
			$f = PHPReady_Filter::apply( $it['messages'], $job['current'], $job['target'], array( 'item' => $it['key'], 'type' => $it['type'] ) );
			$bundled = 0;
			foreach ( $f['messages'] as &$m ) {
				$m['bundled'] = self::is_bundled( $m['file'] );
				if ( $m['bundled'] && 'hidden' !== $m['bucket'] ) {
					$bundled++;
				}
			}
			unset( $m );
			$rows[] = array( 'idx' => $i, 'item' => $it, 'filtered' => $f, 'bundled' => $bundled );
		}
		usort( $rows, function ( $a, $b ) {
			$ca = $a['filtered']['counts'];
			$cb = $b['filtered']['counts'];
			if ( $ca['breaks'] !== $cb['breaks'] ) {
				return $cb['breaks'] <=> $ca['breaks'];
			}
			if ( $a['item']['active'] !== $b['item']['active'] ) {
				return $a['item']['active'] ? -1 : 1;
			}
			return ( $cb['deprecated'] <=> $ca['deprecated'] ) ?: strcasecmp( $a['item']['name'], $b['item']['name'] );
		} );
		return $rows;
	}

	/** evaluate() del último escaneo terminado, una sola vez por petición. */
	public static function last_rows() {
		static $memo = null;
		if ( null === $memo ) {
			$job  = PHPReady_Job::get();
			$memo = ( $job && 'done' === $job['status'] ) ? array( 'job' => $job, 'rows' => self::evaluate( $job ) ) : false;
		}
		return $memo;
	}

	// ------------------------------------------------------ Pantalla Plugins

	/**
	 * En la pantalla Plugins, debajo de cada plugin escaneado: "PHP 8.4: 2 breaks" con enlace a su
	 * detalle en Upgravo. Si el plugin cambió de versión desde el escaneo, lo dice.
	 */
	public static function plugins_screen_meta( $links, $file ) {
		if ( ! is_admin() || ! current_user_can( phpready_cap() ) ) {
			return $links;
		}
		$last = self::last_rows();
		if ( ! $last ) {
			return $links;
		}
		foreach ( $last['rows'] as $r ) {
			$it = $r['item'];
			if ( 'plugin' !== $it['type'] || $it['key'] !== $file ) {
				continue;
			}
			$c      = $r['filtered']['counts'];
			$target = $last['job']['target'];
			if ( $c['breaks'] ) {
				$color = '#b32d2e';
				/* translators: 1: PHP version, 2: number of problems */
				$label = sprintf( _n( 'PHP %1$s: %2$d break', 'PHP %1$s: %2$d breaks', $c['breaks'], 'upgravo' ), $target, $c['breaks'] );
			} elseif ( $c['deprecated'] ) {
				$color = '#dba617';
				/* translators: 1: PHP version, 2: number of deprecation notices */
				$label = sprintf( _n( 'PHP %1$s: works, %2$d notice', 'PHP %1$s: works, %2$d notices', $c['deprecated'], 'upgravo' ), $target, $c['deprecated'] );
			} elseif ( $it['skipped'] ) {
				$color = '#996800';
				/* translators: %s: PHP version */
				$label = sprintf( __( 'PHP %s: not fully checked', 'upgravo' ), $target );
			} else {
				$color = '#00843d';
				/* translators: %s: PHP version */
				$label = sprintf( __( 'PHP %s: OK', 'upgravo' ), $target );
			}
			$extra = array();
			if ( function_exists( 'get_plugins' ) ) {
				$installed = get_plugins(); // WordPress la cachea: no relee los archivos en cada fila
				if ( isset( $installed[ $file ] ) && $installed[ $file ]['Version'] !== $it['version'] ) {
					/* translators: %s: plugin version */
					$extra[] = sprintf( __( 'checked version %s', 'upgravo' ), $it['version'] );
				}
			}
			foreach ( PHPReady_Maint::signals( $it, $target ) as $s ) {
				$extra[] = $s['text'];
			}
			$url     = phpready_page_url() . '#phpready-det-' . (int) $r['idx'];
			$links[] = '<a href="' . esc_url( $url ) . '" style="white-space:nowrap"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . esc_attr( $color ) . '"></span> ' . esc_html( $label ) . '</a>'
				. ( $extra ? ' <span style="color:#646970">· ' . esc_html( implode( ' · ', $extra ) ) . '</span>' : '' );
			break;
		}
		return $links;
	}

	// ---------------------------------------------------------- Resumen

	/**
	 * Resumen en texto plano para pegar en el mail al cliente o en el ticket del hosting.
	 * Sale en el idioma del administrador.
	 */
	public static function summary_text( array $job, array $rows, $title ) {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array();
		$lines[] = get_bloginfo( 'name' ) . ' (' . $host . ') · PHP ' . $job['current'] . ' → ' . $job['target'];
		$lines[] = $title;
		$files   = 0;
		foreach ( $rows as $r ) {
			$files += $r['item']['total'];
		}
		/* translators: 1: date, 2: number of plugins and themes, 3: number of files */
		$lines[] = sprintf( __( 'Checked on %1$s with Upgravo (static code analysis): %2$d plugins and themes, %3$d PHP files.', 'upgravo' ), wp_date( get_option( 'date_format' ), $job['finished'] ), count( $rows ), $files );

		$name = function ( $it ) {
			return trim( $it['name'] . ' ' . $it['version'] ) . ( $it['active'] ? '' : ' (' . __( 'inactive', 'upgravo' ) . ')' );
		};
		$breaks = array();
		$notes  = array();
		$part   = array();
		$maint  = array();
		$ok     = array();
		foreach ( $rows as $r ) {
			$it = $r['item'];
			$c  = $r['filtered']['counts'];
			if ( $c['breaks'] ) {
				/* translators: %d: number of problems */
				$l = '- ' . $name( $it ) . ': ' . sprintf( _n( '%d problem', '%d problems', $c['breaks'], 'upgravo' ), $c['breaks'] );
				if ( ! empty( $it['update'] ) ) {
					/* translators: %s: plugin version */
					$l .= ' · ' . sprintf( __( 'update available: %s', 'upgravo' ), $it['update'] );
				}
				$breaks[] = $l;
			} elseif ( $c['deprecated'] ) {
				$notes[] = '- ' . $name( $it ) . ': ' . (int) $c['deprecated'];
			} elseif ( ! $it['skipped'] ) {
				$ok[] = $it['name'];
			}
			if ( $it['skipped'] ) {
				/* translators: %d: number of files */
				$part[] = '- ' . $name( $it ) . ': ' . sprintf( _n( '%d file not checked', '%d files not checked', count( $it['skipped'] ), 'upgravo' ), count( $it['skipped'] ) );
			}
			foreach ( PHPReady_Maint::signals( $it, $job['target'] ) as $s ) {
				$maint[] = '- ' . $it['name'] . ': ' . $s['text'];
			}
		}
		$block = function ( $head, $list ) use ( &$lines ) {
			if ( $list ) {
				$lines[] = '';
				$lines[] = $head;
				foreach ( $list as $l ) {
					$lines[] = $l;
				}
			}
		};
		/* translators: %s: PHP version */
		$block( sprintf( __( 'Breaks on PHP %s (fatal errors):', 'upgravo' ), $job['target'] ), $breaks );
		$block( __( 'Deprecation notices (the code still runs):', 'upgravo' ), $notes );
		$block( __( 'Could not be fully checked:', 'upgravo' ), $part );
		$block( __( 'Maintenance:', 'upgravo' ), $maint );
		if ( $ok ) {
			$lines[] = '';
			$lines[] = __( 'No problems found:', 'upgravo' ) . ' ' . implode( ', ', $ok );
		}
		$steps = self::next_steps( $rows, $job['target'] );
		if ( $steps ) {
			$lines[] = '';
			$lines[] = __( 'What to do first', 'upgravo' ) . ':';
			foreach ( $steps as $i => $s ) {
				$lines[] = ( $i + 1 ) . '. ' . $s;
			}
		}
		return implode( "\n", $lines );
	}

	/** "Qué hacer primero": lista corta y accionable, derivada de los datos. */
	public static function next_steps( array $rows, $target ) {
		$steps = array();
		foreach ( $rows as $r ) {
			$c  = $r['filtered']['counts'];
			$it = $r['item'];
			if ( ! $c['breaks'] ) {
				continue;
			}
			$who = $it['name'] . ( $it['active'] ? '' : ' (' . __( 'inactive', 'upgravo' ) . ')' );
			if ( ! empty( $it['update'] ) ) {
				/* translators: 1: plugin name, 2: new version, 3: PHP version */
				$steps[] = sprintf( __( 'Update %1$s to version %2$s and scan again: the installed version breaks on PHP %3$s.', 'upgravo' ), $who, $it['update'], $target );
			} elseif ( ! $it['active'] ) {
				/* translators: 1: plugin name, 2: PHP version */
				$steps[] = sprintf( __( '%1$s breaks on PHP %2$s but is inactive: it has no effect until activated. If unused, delete it.', 'upgravo' ), $who, $target );
			} else {
				/* translators: 1: plugin name, 2: PHP version */
				$steps[] = sprintf( __( '%1$s breaks on PHP %2$s and has no update available: find a replacement or ask the author for a compatible version before upgrading.', 'upgravo' ), $who, $target );
			}
		}
		foreach ( $rows as $r ) {
			if ( $r['item']['skipped'] && $r['item']['active'] ) {
				/* translators: 1: plugin name, 2: number of files */
				$steps[] = sprintf( __( '%1$s: %2$d file(s) could not be analyzed (too big for the memory limit, a syntax error on the current PHP, or the server aborted the request). The verdict does not cover them: expand the row to see which ones and why.', 'upgravo' ), $r['item']['name'], count( $r['item']['skipped'] ) );
			}
		}
		return $steps;
	}

	/** Veredicto global: 'ok' | 'warn' | 'break' | 'incomplete'. */
	public static function verdict( array $rows ) {
		$v = 'ok';
		foreach ( $rows as $r ) {
			$c = $r['filtered']['counts'];
			if ( $c['breaks'] && $r['item']['active'] ) {
				return 'break';
			}
			if ( $r['item']['skipped'] && $r['item']['active'] ) {
				$v = 'incomplete';
			} elseif ( $c['deprecated'] && 'incomplete' !== $v ) {
				$v = 'warn';
			}
		}
		return $v;
	}

	// ---------------------------------------------------------------- CSV

	public static function csv() {
		if ( ! current_user_can( phpready_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'upgravo' ) );
		}
		check_admin_referer( 'phpready_csv' );
		$job = PHPReady_Job::get();
		if ( ! $job || 'done' !== $job['status'] ) {
			wp_die( esc_html__( 'There is no scan result to export.', 'upgravo' ) );
		}
		$site = preg_replace( '/[^a-z0-9]+/i', '-', wp_parse_url( home_url(), PHP_URL_HOST ) );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="phpready-' . $site . '-' . $job['target'] . '.csv"' );
		$rows = array( array( __( 'Item', 'upgravo' ), __( 'Version', 'upgravo' ), __( 'Active', 'upgravo' ), __( 'Category', 'upgravo' ), __( 'File', 'upgravo' ), __( 'Line', 'upgravo' ), __( 'Bundled library', 'upgravo' ), __( 'Message', 'upgravo' ), __( 'Rule', 'upgravo' ), __( 'Hidden reason', 'upgravo' ) ) );
		foreach ( self::evaluate( $job ) as $r ) {
			foreach ( $r['filtered']['messages'] as $m ) {
				$rows[] = array(
					$r['item']['name'],
					$r['item']['version'],
					$r['item']['active'] ? __( 'yes', 'upgravo' ) : __( 'no', 'upgravo' ),
					'breaks' === $m['bucket'] ? __( 'BREAKS', 'upgravo' ) : ( 'deprecated' === $m['bucket'] ? __( 'NOTICE', 'upgravo' ) : __( 'hidden', 'upgravo' ) ),
					$m['file'],
					$m['line'],
					$m['bundled'] ? __( 'yes', 'upgravo' ) : __( 'no', 'upgravo' ),
					$m['message'],
					str_replace( 'PHPCompatibility.', '', $m['source'] ),
					$m['reason'] ? PHPReady_Filter::reason_label( $m['reason'] ) : '',
				);
			}
		}
		echo "\xEF\xBB\xBF"; // BOM: Excel abre UTF-8 bien
		foreach ( $rows as $row ) {
			echo implode( ',', array_map( array( __CLASS__, 'csv_cell' ), $row ) ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, no HTML; cada celda va entrecomillada en csv_cell().
		}
		exit;
	}

	/** Una celda CSV: siempre entre comillas, comillas internas dobladas; neutraliza fórmulas de Excel (=, +, -, @, tab, CR). */
	public static function csv_cell( $v ) {
		$v = (string) $v;
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$v = "'" . $v;
		}
		return '"' . str_replace( '"', '""', $v ) . '"';
	}

	// -------------------------------------------------------- Salud del sitio

	public static function site_health_register( $tests ) {
		if ( phpready_is_network() && ! is_main_site() ) {
			return $tests; // en modo red el resultado vive en el sitio principal
		}
		$tests['direct']['phpready_php_upgrade'] = array(
			'label' => __( 'PHP upgrade readiness', 'upgravo' ),
			'test'  => array( __CLASS__, 'site_health_test' ),
		);
		return $tests;
	}

	public static function site_health_test() {
		$url    = phpready_page_url();
		$result = array(
			'label'       => __( 'Compatibility with a newer PHP version has not been checked yet', 'upgravo' ),
			'status'      => 'recommended',
			'badge'       => array( 'label' => 'PHP', 'color' => 'blue' ),
			'description' => '<p>' . esc_html__( 'Scan plugins and theme before raising the PHP version on your hosting.', 'upgravo' ) . '</p>',
			'actions'     => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Go to Upgravo', 'upgravo' ) . '</a>',
			'test'        => 'phpready_php_upgrade',
		);
		$job = PHPReady_Job::get();
		if ( ! $job || 'done' !== $job['status'] ) {
			return $result;
		}
		$rows = self::evaluate( $job );
		$v    = self::verdict( $rows );
		$when = date_i18n( get_option( 'date_format' ), $job['finished'] );
		switch ( $v ) {
			case 'ok':
				$result['status'] = 'good';
				/* translators: 1: PHP version, 2: date */
				$result['label']  = sprintf( __( 'The site is ready for PHP %1$s (checked on %2$s)', 'upgravo' ), $job['target'], $when );
				$result['description'] = '<p>' . esc_html__( 'No active plugin or theme breaks.', 'upgravo' ) . '</p>';
				break;
			case 'warn':
				$result['status'] = 'good';
				/* translators: 1: PHP version, 2: date */
				$result['label']  = sprintf( __( 'The site is ready for PHP %1$s, with deprecation notices (checked on %2$s)', 'upgravo' ), $job['target'], $when );
				$result['description'] = '<p>' . esc_html__( 'Nothing breaks; some plugins will log deprecation notices.', 'upgravo' ) . '</p>';
				break;
			case 'incomplete':
				$result['status'] = 'recommended';
				/* translators: %s: PHP version */
				$result['label']  = sprintf( __( 'Incomplete check for PHP %s', 'upgravo' ), $job['target'] );
				$result['description'] = '<p>' . esc_html__( 'Some files could not be analyzed.', 'upgravo' ) . '</p>';
				break;
			default:
				$result['status'] = 'critical';
				$result['badge']['color'] = 'red';
				/* translators: 1: PHP version, 2: date */
				$result['label']  = sprintf( __( 'Something breaks when upgrading to PHP %1$s (checked on %2$s)', 'upgravo' ), $job['target'], $when );
				$result['description'] = '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', self::next_steps( $rows, $job['target'] ) ) ) . '</li></ul>';
		}
		return $result;
	}

	// ------------------------------------------------------------------ WP-CLI

	/**
	 * Scans plugins and theme against a PHP version.
	 *
	 * ## OPTIONS
	 *
	 * [--target=<version>]
	 * : Target PHP version. Defaults to the highest supported.
	 *
	 * [--inactive]
	 * : Include inactive plugins.
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp upgravo scan --target=8.4
	 */
	public static function cli( $args, $assoc ) {
		$versions = phpready_target_versions();
		$target   = isset( $assoc['target'] ) ? (string) $assoc['target'] : end( $versions );
		if ( ! in_array( $target, $versions, true ) ) {
			WP_CLI::error( 'Unsupported version: ' . $target . '. Use one of: ' . implode( ', ', $versions ) );
		}
		$format = $assoc['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( 'Unsupported format: ' . $format . '. Use table, json or csv.' );
		}
		$quiet  = 'table' !== $format; // json/csv van a stdout limpio para que `| jq` funcione
		$job    = PHPReady_Job::run_sync( $target, isset( $assoc['inactive'] ), function ( $r ) use ( $quiet ) {
			if ( ! $r['done'] && ! $quiet ) {
				WP_CLI::log( sprintf( '  %s (%d/%d) %d/%d', $r['item_name'], $r['item_num'], $r['items'], $r['index'], $r['total'] ) );
			}
		} );
		if ( ! $job || 'done' !== $job['status'] ) {
			WP_CLI::error( 'The scan did not finish (it was cancelled or replaced from the admin screen).' );
		}
		$rows  = self::evaluate( $job );
		$table = array();
		foreach ( $rows as $r ) {
			$c       = $r['filtered']['counts'];
			$table[] = array(
				'item'     => $r['item']['name'],
				'version'  => $r['item']['version'],
				'active'   => $r['item']['active'] ? 'yes' : 'no',
				'breaks'   => $c['breaks'],
				'notices'  => $c['deprecated'],
				'hidden'   => $c['hidden'],
				'files'    => $r['item']['total'],
				'update'   => $r['item']['update'] ?? '',
			);
		}
		WP_CLI\Utils\format_items( $format, $table, array( 'item', 'version', 'active', 'breaks', 'notices', 'hidden', 'files', 'update' ) );
		$v = self::verdict( $rows );
		if ( 'break' === $v ) {
			foreach ( self::next_steps( $rows, $target ) as $s ) {
				WP_CLI::warning( $s ); // STDERR: no ensucia json/csv
			}
			WP_CLI::halt( 1 );
		}
		if ( ! $quiet ) {
			WP_CLI::success( 'ok' === $v ? "Nothing breaks on PHP $target." : "Nothing breaks on PHP $target (there are deprecation notices)." );
		}
	}
}
