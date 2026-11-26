<?php
/**
 * Motor de escaneo: envuelve PHP_CodeSniffer + PHPCompatibility y los corre
 * dentro del mismo proceso PHP (sin exec), archivo por archivo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHPReady_Scanner {

	/** Medido: PHPCS consume ~0,75 KB por token; usamos 1 KB para ir sobre seguro. */
	const BYTES_PER_TOKEN = 1024;

	/** Por debajo de este tamaño ni medimos: siempre entra. */
	const SMALL_FILE_BYTES = 64 * 1024;

	/** Colchón que dejamos libre para WordPress y para armar el resultado. */
	const RESERVE_BYTES = 12 * 1048576;

	private static $booted = false;

	/** memory_limit en bytes (-1 = sin límite → tratamos como 1 GB). */
	public static function memory_limit_bytes() {
		$v = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $v || '-1' === $v ) {
			return 1024 * 1048576;
		}
		$n = (int) $v;
		switch ( strtolower( substr( $v, -1 ) ) ) {
			case 'g':
				$n *= 1024; // fallthrough
			case 'm':
				$n *= 1024; // fallthrough
			case 'k':
				$n *= 1024;
		}
		return $n;
	}

	/**
	 * ¿Entra este archivo en la memoria que queda? Devuelve true, o un texto con el motivo.
	 * Para archivos grandes contamos tokens (token_get_all es barato: ~0,25 KB/token y se libera).
	 */
	public static function fits_in_memory( $path ) {
		$size = filesize( $path );
		if ( $size <= self::SMALL_FILE_BYTES ) {
			// Chicos: siempre entran, pero igual validamos la sintaxis (un archivo roto chico
			// también hace que PHPCS no vea nada y saldría como "limpio").
			try {
				token_get_all( file_get_contents( $path ), TOKEN_PARSE );
			} catch ( \ParseError $e ) {
				/* translators: 1: PHP version, 2: line number, 3: parser message */
				return sprintf( __( 'does not compile on PHP %1$s today: line %2$d, %3$s', 'upgravo' ), PHP_VERSION, $e->getLine(), $e->getMessage() );
			} catch ( \Throwable $e ) {
				return true;
			}
			return true;
		}
		$available = self::memory_limit_bytes() - memory_get_usage( true ) - self::RESERVE_BYTES;

		// Ni siquiera podemos tokenizar con comodidad.
		if ( $size * 4 > $available ) {
			/* translators: 1: file size in KB, 2: available memory in MB */
			return sprintf( __( '%1$d KB, does not fit in available memory (%2$d MB)', 'upgravo' ), $size / 1024, $available / 1048576 );
		}
		// TOKEN_PARSE: además de contar tokens, el parser de PHP valida la sintaxis. Un archivo
		// que no compila en el PHP de HOY no se puede analizar (PHPCS descarrila y devuelve
		// hallazgos falsos): lo anotamos como no comprobado con el motivo real.
		try {
			$tokens = count( token_get_all( file_get_contents( $path ), TOKEN_PARSE ) );
		} catch ( \ParseError $e ) {
			/* translators: 1: PHP version, 2: line number, 3: parser message */
			return sprintf( __( 'does not compile on PHP %1$s today: line %2$d, %3$s', 'upgravo' ), PHP_VERSION, $e->getLine(), $e->getMessage() );
		} catch ( \Throwable $e ) {
			$tokens = count( token_get_all( file_get_contents( $path ) ) );
		}
		$needed = $tokens * self::BYTES_PER_TOKEN;
		// Tokenizar deja memoria reservada en el proceso: medimos lo que queda ahora, no antes.
		$available = self::memory_limit_bytes() - memory_get_usage( true ) - self::RESERVE_BYTES;
		if ( $needed > $available && self::raise_memory( $needed - $available ) ) {
			$available = self::memory_limit_bytes() - memory_get_usage( true ) - self::RESERVE_BYTES;
		}
		if ( $needed > $available ) {
			/* translators: 1: number of tokens, 2: estimated MB, 3: available MB */
			return sprintf( __( '%1$d tokens ≈ %2$d MB, %3$d MB available', 'upgravo' ), $tokens, $needed / 1048576, $available / 1048576 );
		}
		return true;
	}

	/**
	 * Pide al servidor exactamente la memoria que falta para este archivo (redondeada a 16 MB).
	 * Muchos hostings dejan subir memory_limit con ini_set hasta su tope; otros no. Si no lo
	 * aceptan, devolvemos false y el archivo queda como no comprobado, igual que antes.
	 */
	private static function raise_memory( $missing_bytes ) {
		$current = self::memory_limit_bytes();
		if ( '-1' === trim( (string) ini_get( 'memory_limit' ) ) ) {
			return false; // sin límite: si no entra ahí, no entra.
		}
		$want = (int) ceil( ( $current + $missing_bytes ) / ( 16 * 1048576 ) ) * 16; // en MB
		if ( $want > 2048 ) {
			return false;
		}
		// Por la vía oficial de WordPress: wp_raise_memory_limit() lee el filtro "{contexto}_memory_limit"
		// y hace el ini_set si el valor supera el límite actual (y respeta WP_MAX_MEMORY_LIMIT si el host
		// puso uno mayor).
		$value = $want . 'M';
		$cb    = function () use ( $value ) {
			return $value;
		};
		add_filter( 'phpready_memory_limit', $cb );
		wp_raise_memory_limit( 'phpready' );
		remove_filter( 'phpready_memory_limit', $cb );
		return self::memory_limit_bytes() >= $want * 1048576;
	}

	/** Carga las librerías una sola vez y registra el estándar PHPCompatibility. */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		$vendor = dirname( __DIR__ ) . '/vendor';
		require_once $vendor . '/autoload.php';
		require_once $vendor . '/squizlabs/php_codesniffer/autoload.php';

		if ( ! defined( 'PHP_CODESNIFFER_CBF' ) ) {
			define( 'PHP_CODESNIFFER_CBF', false );
		}
		if ( ! defined( 'PHP_CODESNIFFER_VERBOSITY' ) ) {
			define( 'PHP_CODESNIFFER_VERBOSITY', 0 );
		}

		// Le decimos a PHPCS dónde están los estándares, sin tocar ningún archivo de configuración.
		$paths = $vendor . '/phpcompatibility/php-compatibility,' . $vendor . '/phpcsstandards/phpcsutils';
		// PHPCS 4: setConfigData() es de instancia; los datos quedan en memoria (estáticos) y valen
		// para todas las Config que se creen después. temp = true: nunca escribe CodeSniffer.conf.
		$bootstrap = new \PHP_CodeSniffer\Config( array( '-q' ) );
		$bootstrap->setConfigData( 'installed_paths', $paths, true );
		unset( $bootstrap );

		self::$booted = true;
	}

	/**
	 * Comprobaciones de VERSIÓN (version_compare, PHP_VERSION...): no nombran una función, así
	 * que valen para cualquier hallazgo de la rama, como hasta 0.1.9.
	 */
	const VERSION_GUARD_REGEX = '/version_compare|PHP_VERSION|PHP_MAJOR_VERSION|PHP_MINOR_VERSION|LIBXML_VERSION|OPENSSL_VERSION/i';

	/**
	 * Comprobaciones de EXISTENCIA: desde 0.1.10 solo protegen lo que nombran.
	 * function_exists('a') alrededor de b() ya no cuenta como protección de b().
	 */
	const EXISTS_REGEX = '/\b(function_exists|extension_loaded|defined|class_exists|interface_exists|method_exists|is_callable)\s*\(/i';

	/** Nombres que menciona el hallazgo: "Function mysql_connect() is...", 'The constant "E_STRICT"...'. */
	public static function names_in_message( $message ) {
		$names = array();
		if ( preg_match_all( '/([A-Za-z_][A-Za-z0-9_]*)\s*\(\)/', $message, $x ) ) {
			$names = array_merge( $names, $x[1] );
		}
		if ( preg_match_all( '/["\']\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)["\']/', $message, $x ) ) {
			$names = array_merge( $names, $x[1] );
		}
		if ( preg_match_all( '/\b(?:class|interface)\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)/i', $message, $x ) ) {
			$names = array_merge( $names, $x[1] );
		}
		return array_values( array_unique( array_map( 'strtolower', $names ) ) );
	}

	/** ¿La comprobación de existencia $kind( '$arg' ) se refiere a alguno de los nombres marcados? */
	private static function guard_matches( $kind, $arg, array $names ) {
		$arg = strtolower( ltrim( $arg, '\\' ) );
		if ( '' === $arg ) {
			return false;
		}
		foreach ( $names as $n ) {
			if ( $n === $arg ) {
				return true;
			}
			// extension_loaded('mcrypt') protege mcrypt_encrypt(); el mensaje de extensión dice 'mysql_'.
			if ( 'extension_loaded' === $kind && ( 0 === strpos( $n, rtrim( $arg, '_' ) . '_' ) || rtrim( $n, '_' ) === $arg ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lee una condición. Devuelve null si no protege lo marcado, o
	 * array( 'positive' => true|false|null ):
	 *   true  la rama del if corre solo si lo marcado existe (function_exists('x') ...);
	 *   false está negada (! function_exists('x')): la rama protegida es la del else;
	 *   null  comprobación de versión: vale para cualquier rama.
	 */
	private static function analyze_condition( $cond, array $names ) {
		if ( $names && preg_match_all( self::EXISTS_REGEX, $cond, $mm, PREG_OFFSET_CAPTURE ) ) {
			$len = strlen( $cond );
			foreach ( $mm[0] as $k => $hit ) {
				$kind  = strtolower( $mm[1][ $k ][0] );
				$start = $hit[1];
				$open  = $start + strlen( $hit[0] ) - 1;
				$depth = 0;
				$close = null;
				for ( $i = $open; $i < $len; $i++ ) {
					if ( '(' === $cond[ $i ] ) {
						$depth++;
					} elseif ( ')' === $cond[ $i ] && 0 === --$depth ) {
						$close = $i;
						break;
					}
				}
				if ( null === $close || ! preg_match_all( '/["\']([^"\']+)["\']/', substr( $cond, $open + 1, $close - $open - 1 ), $lit ) ) {
					continue;
				}
				if ( ! self::guard_matches( $kind, end( $lit[1] ), $names ) ) {
					continue;
				}
				$before  = substr( $cond, 0, $start );
				$after   = (string) substr( $cond, $close + 1 );
				$negated = preg_match( '/(?:!|\bfalse\s*===?)\s*\(*\s*$/i', $before )
					|| preg_match( '/^\s*\)*\s*(?:===?\s*false\b|!==?\s*true\b)/i', $after );
				return array( 'positive' => ! $negated );
			}
		}
		if ( preg_match( self::VERSION_GUARD_REGEX, $cond ) ) {
			return array( 'positive' => null );
		}
		return null;
	}

	/**
	 * Si el hallazgo $message de la línea $line está protegido por una comprobación que se
	 * refiere a ESA función/constante/clase (o por una de versión), devuelve la condición
	 * recortada. Si no, null. Se mira por hallazgo y por rama: otra llamada igual en el mismo
	 * archivo sin protección se sigue mostrando.
	 * Se llama mientras el archivo todavía está tokenizado, así que es gratis.
	 */
	public static function guard_for_line( $file, $line, $message = '' ) {
		$tokens = $file->getTokens();
		$names  = self::names_in_message( $message );
		$ptr    = null;
		foreach ( $tokens as $i => $t ) {
			if ( $t['line'] === $line ) {
				$ptr = $i;
				break;
			}
			if ( $t['line'] > $line ) {
				break;
			}
		}
		if ( null === $ptr ) {
			return null;
		}

		// Caso 1: el hallazgo está en la misma línea que la comprobación.
		$line_text = '';
		for ( $j = $ptr; isset( $tokens[ $j ] ) && $tokens[ $j ]['line'] === $line; $j++ ) {
			$line_text .= $tokens[ $j ]['content'];
		}
		$a = self::analyze_condition( $line_text, $names );
		if ( $a ) {
			$is_cond = preg_match( '/^\s*(if|elseif|\}?\s*else\s*if|while|case|switch)\b/', $line_text );
			$and     = preg_match( '/&&|\?|\band\b/i', $line_text );
			$or      = preg_match( '/\|\||\bor\b/i', $line_text );
			// function_exists('f') && f()  ·  ! function_exists('f') || f()  ·  if ( function_exists('f') ) { f(); }
			if ( ( null === $a['positive'] && ( $is_cond || $and || $or ) )
				|| ( true === $a['positive'] && ( $is_cond || $and ) )
				|| ( false === $a['positive'] && $or ) ) {
				return trim( substr( $line_text, 0, 80 ) );
			}
		}

		// Caso 1c: el hallazgo está dentro de la condición de un if que ocupa varias líneas:
		// if ( defined( 'C' )
		//      && ... C ) {
		if ( isset( $tokens[ $ptr ]['nested_parenthesis'] ) ) {
			foreach ( $tokens[ $ptr ]['nested_parenthesis'] as $po => $pc ) {
				$owner = $tokens[ $po ]['parenthesis_owner'] ?? null;
				if ( null === $owner || ! in_array( $tokens[ $owner ]['code'], array( T_IF, T_ELSEIF, T_WHILE ), true ) ) {
					continue;
				}
				$cond = preg_replace( '/\s+/', ' ', $file->getTokensAsString( $po, $pc - $po + 1 ) );
				$a    = self::analyze_condition( $cond, $names );
				if ( $a && ( null === $a['positive']
					|| ( true === $a['positive'] && preg_match( '/&&|\band\b/i', $cond ) )
					|| ( false === $a['positive'] && preg_match( '/\|\||\bor\b/i', $cond ) ) ) ) {
					return trim( substr( $cond, 0, 80 ) );
				}
			}
		}

		// Caso 2: alguna de las condiciones que envuelven el token, en la rama correcta.
		$conds = isset( $tokens[ $ptr ]['conditions'] ) ? array_reverse( $tokens[ $ptr ]['conditions'], true ) : array();
		foreach ( $conds as $cond_ptr => $code ) {
			if ( ! in_array( $code, array( T_IF, T_ELSEIF, T_ELSE, T_SWITCH, T_CASE, T_WHILE ), true ) ) {
				continue;
			}
			$if_ptr  = $cond_ptr;
			$in_else = false;
			if ( T_ELSE === $code ) {
				// La condición está en el if de este else, y lo protegido es lo contrario.
				$in_else = true;
				$prev    = $file->findPrevious( T_CLOSE_CURLY_BRACKET, $cond_ptr - 1 );
				if ( $prev && isset( $tokens[ $prev ]['scope_condition'] ) ) {
					$if_ptr = $tokens[ $prev ]['scope_condition'];
				}
			} elseif ( T_CASE === $code ) {
				$sw = $file->findPrevious( T_SWITCH, $cond_ptr - 1 );
				if ( $sw ) {
					$if_ptr = $sw;
				}
			}
			if ( ! isset( $tokens[ $if_ptr ]['parenthesis_opener'], $tokens[ $if_ptr ]['parenthesis_closer'] ) ) {
				continue;
			}
			$o    = $tokens[ $if_ptr ]['parenthesis_opener'];
			$c    = $tokens[ $if_ptr ]['parenthesis_closer'];
			$cond = preg_replace( '/\s+/', ' ', $file->getTokensAsString( $o, $c - $o + 1 ) );
			$text = $cond;
			$a    = self::analyze_condition( $cond, $names );
			// Guard guardado en una variable: $existe = function_exists('f'); if ( $existe ) { f(); }
			if ( ! $a && preg_match_all( '/\$\w+/', $cond, $vars ) ) {
				foreach ( array_unique( $vars[0] ) as $var ) {
					if ( '$this' === $var ) {
						continue;
					}
					$assign = self::find_assignment( $file, $var, $if_ptr );
					$a      = $assign ? self::analyze_condition( $assign, $names ) : null;
					if ( $a ) {
						if ( null !== $a['positive'] && preg_match( '/!\s*\(*\s*' . preg_quote( $var, '/' ) . '\b/', $cond ) ) {
							$a['positive'] = ! $a['positive'];
						}
						$text = $var . ' = ' . $assign;
						break;
					}
				}
			}
			if ( ! $a ) {
				continue;
			}
			if ( null === $a['positive'] || ( $in_else ? ! $a['positive'] : $a['positive'] ) ) {
				return trim( substr( $text, 0, 80 ) );
			}
			// Rama equivocada (if ( ! function_exists('f') ) { f(); }): esta condición no la
			// protege; seguimos mirando las de afuera.
		}
		return null;
	}

	/** Busca hacia atrás "$var = ...;" y devuelve el lado derecho, o null. */
	private static function find_assignment( $file, $var_name, $before_ptr ) {
		$tokens = $file->getTokens();
		$p      = $before_ptr;
		while ( ( $p = $file->findPrevious( T_VARIABLE, $p - 1 ) ) !== false ) {
			if ( $tokens[ $p ]['content'] !== $var_name ) {
				continue;
			}
			$eq = $file->findNext( T_WHITESPACE, $p + 1, null, true );
			if ( false === $eq || T_EQUAL !== $tokens[ $eq ]['code'] ) {
				continue;
			}
			$end = $file->findNext( T_SEMICOLON, $eq + 1 );
			if ( false === $end ) {
				return null;
			}
			return preg_replace( '/\s+/', ' ', $file->getTokensAsString( $eq + 1, $end - $eq - 1 ) );
		}
		return null;
	}

	/** Lista ordenada de archivos .php bajo un directorio. */
	public static function list_php_files( $dir ) {
		$files = array();
		$it    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $f ) {
			if ( $f->isFile() && strtolower( $f->getExtension() ) === 'php' ) {
				$files[] = $f->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Escanea una lista de archivos contra una versión de PHP objetivo.
	 *
	 * @param string[] $files        Rutas absolutas.
	 * @param string   $test_version Ej. "8.4".
	 * @param string   $base_dir     Para mostrar rutas relativas.
	 * @return array { messages, errors, warnings, skipped, seconds, peak_mb }
	 */
	public static function scan_files( array $files, $test_version, $base_dir ) {
		return self::scan_batch( $files, 0, $test_version, $base_dir, 0, null );
	}

	/**
	 * Escanea desde $start_index hasta agotar $budget_seconds (0 = sin límite).
	 *
	 * @param callable|null $before_file Se llama con ($index, $rel) antes de cada archivo:
	 *                                   sirve para dejar anotado "estoy en este archivo" por si
	 *                                   el servidor mata la petición a mitad de camino.
	 * @param int           $skip_index  Índice de un archivo que NO hay que analizar (mató la tanda
	 *                                   anterior); se anota en 'skipped' con $skip_reason.
	 * @return array igual que scan_files + 'next_index' (= count($files) si terminó).
	 */
	public static function scan_batch( array $files, $start_index, $test_version, $base_dir, $budget_seconds, $before_file, $index_only = false, $skip_index = -1, $skip_reason = '' ) {
		self::boot();

		$t0      = microtime( true );
		// Nuestro ruleset: PHPCompatibility menos las 94 reglas "New*" (solo sirven a quien BAJA de versión).
		$standard = defined( 'PHPREADY_FULL_RULESET' ) && PHPREADY_FULL_RULESET ? 'PHPCompatibility' : dirname( __DIR__ ) . '/ruleset/upgrade.xml';
		$config  = new \PHP_CodeSniffer\Config(
			array(
				'--standard=' . $standard,
				'--extensions=php',
				'--parallel=1',
				'-q',
				'--ignore-annotations', // un // phpcs:ignore del autor del plugin no puede esconder incompatibilidades
				'--runtime-set',
				'testVersion',
				$test_version,
			)
		);
		$ruleset = new \PHP_CodeSniffer\Ruleset( $config );

		$out = array(
			'messages' => array(),
			'errors'   => 0,
			'warnings' => 0,
			'skipped'  => array(),
			'failed'   => array(),
			'facts'      => array(),
			'candidates' => array(),
			'callables'  => array(),
		);

		$total = count( $files );
		$i     = (int) $start_index;

		for ( ; $i < $total; $i++ ) {
			if ( $budget_seconds > 0 && ( microtime( true ) - $t0 ) >= $budget_seconds ) {
				break;
			}
			$path = $files[ $i ];
			$rel  = ltrim( str_replace( '\\', '/', substr( $path, strlen( $base_dir ) ) ), '/' );

			if ( $i === $skip_index ) {
				$out['skipped'][] = $rel . ' (' . $skip_reason . ')';
				continue;
			}

			if ( $before_file ) {
				call_user_func( $before_file, $i, $rel );
			}

			$fits = self::fits_in_memory( $path );
			if ( true !== $fits ) {
				$out['skipped'][] = $rel . ' (' . $fits . ')';
				continue;
			}

			try {
				$file = new \PHP_CodeSniffer\Files\LocalFile( $path, $ruleset, $config );
				if ( $index_only ) {
					$file->parse(); // solo tokens: para el core de WP no queremos hallazgos, solo hechos de clase
				} else {
					$file->process();
				}

				$cls              = PHPReady_Classes::collect( $file, $rel );
				$out['facts']     = PHPReady_Classes::merge_facts( $out['facts'], $cls['facts'] );
				if ( ! $index_only ) {
					$out['candidates'] = array_merge( $out['candidates'], $cls['candidates'] );
					$out['callables']  = array_merge( $out['callables'], $cls['callables'] );
				}

				// Si PHPCS abortó el archivo (Internal.Exception: un bug del analizador con ese
				// código), NO lo damos por limpio: va a "no comprobados" y el veredicto queda
				// incompleto. Los demás hallazgos de ese archivo no valen (se cortó a mitad).
				$aborted = null;
				foreach ( $file->getErrors() as $lines_e ) {
					foreach ( $lines_e as $cols_e ) {
						foreach ( $cols_e as $m_e ) {
							if ( 'Internal.Exception' === $m_e['source'] ) {
								$aborted = $m_e['message'];
								break 3;
							}
						}
					}
				}
				if ( null !== $aborted ) {
					$out['skipped'][] = $rel . ' (' . __( 'the analyzer failed on this file', 'upgravo' ) . ')';
					$file->cleanUp();
					unset( $file );
					continue;
				}

				$out['errors']   += $file->getErrorCount();
				$out['warnings'] += $file->getWarningCount();

				foreach ( array( 'E' => $file->getErrors(), 'W' => $file->getWarnings() ) as $type => $lines ) {
					foreach ( $lines as $line => $cols ) {
						foreach ( $cols as $msgs ) {
							foreach ( $msgs as $m ) {
								$out['messages'][] = array(
									'file'    => $rel,
									'line'    => $line,
									'type'    => $type,
									'source'  => $m['source'],
									'message' => $m['message'],
									'guard'   => self::guard_for_line( $file, $line, $m['message'] ),
								);
							}
						}
					}
				}

				$file->cleanUp();
				unset( $file );
			} catch ( \Throwable $e ) {
				$out['failed'][] = $rel . ': ' . $e->getMessage();
			}

			// Sin esto, PHPCSUtils acumula ~45 KB por archivo y revienta la memoria en plugins grandes.
			\PHPCSUtils\Internal\Cache::clear();
		}

		$out['next_index'] = $i;
		$out['seconds']    = round( microtime( true ) - $t0, 2 );
		$out['peak_mb']    = round( memory_get_peak_usage( true ) / 1048576, 1 );
		return $out;
	}
}
