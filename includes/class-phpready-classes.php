<?php
/**
 * Lo que PHPCompatibility no cubre y hay que hacer a mano:
 *
 *  1. Propiedades dinámicas (deprecadas en PHP 8.2): `$this->algo = ...` dentro de una clase que
 *     no declara `$algo` ni lo hereda. Para saberlo hay que conocer la clase, su padre, el padre
 *     del padre... que suelen estar en otros archivos (o en el core de WordPress). Así que:
 *       - mientras escaneamos cada archivo anotamos "hechos" de cada clase (padre, propiedades
 *         declaradas, si es "abierta": usa traits, tiene __set o #[AllowDynamicProperties]);
 *       - anotamos "candidatos": asignaciones a propiedades no declaradas en esa misma clase;
 *       - al final cruzamos candidatos contra la cadena de ancestros. Si un ancestro no está en
 *         los hechos (clase desconocida) o es abierto, descartamos el candidato: preferimos no
 *         avisar antes que avisar mal.
 *
 *  2. Callables escritos como texto 'self::metodo' / 'parent::metodo' / 'static::metodo'
 *     (deprecados en PHP 8.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PHPCSUtils\Utils\ObjectDeclarations;
use PHPCSUtils\Utils\Namespaces;
use PHPCSUtils\Utils\UseStatements;
use PHPCSUtils\Utils\FunctionDeclarations;
use PHPCSUtils\Tokens\Collections;
use PHP_CodeSniffer\Util\Tokens;

class PHPReady_Classes {

	const SRC_DYNAMIC  = 'PHPReady.Php82.DynamicProperty';
	const SRC_CALLABLE = 'PHPReady.Php82.RelativeCallable';

	/** Clases del core de PHP que aceptan propiedades dinámicas sin aviso. */
	const OPEN_BUILTINS = array( 'stdclass', 'arrayobject', 'simplexmlelement', 'domdocument', 'domelement', 'domnode', 'wp_error' );

	/**
	 * Recorre un archivo ya tokenizado y devuelve:
	 *   facts      => [ fq_lower => [ 'parent' => fq_lower|'', 'open' => bool, 'props' => [name => 1] ] ]
	 *   candidates => [ [ 'class' => fq_lower, 'display' => Name, 'prop' => x, 'file' => rel, 'line' => n ] ]
	 *   callables  => [ [ 'file' => rel, 'line' => n, 'text' => "'self::x'" ] ]
	 */
	public static function collect( $file, $rel ) {
		$tokens = $file->getTokens();
		$out    = array( 'facts' => array(), 'candidates' => array(), 'callables' => array() );

		$uses = self::use_map( $file );

		foreach ( $tokens as $i => $t ) {
			if ( T_CONSTANT_ENCAPSED_STRING === $t['code'] ) {
				if ( preg_match( '/^([\'"])(self|parent|static)::\w+\1$/i', $t['content'] ) ) {
					$out['callables'][] = array( 'file' => $rel, 'line' => $t['line'], 'text' => $t['content'] );
				} elseif ( preg_match( '/^([\'"])(self|parent|static)\1$/', $t['content'] ) ) {
					// Forma array: array( 'parent', 'metodo' ) / [ 'self', 'metodo' ]
					$prev = $file->findPrevious( Tokens::$emptyTokens, $i - 1, null, true );
					$next = $file->findNext( Tokens::$emptyTokens, $i + 1, null, true );
					$is_array = false;
					if ( false !== $prev && T_OPEN_SHORT_ARRAY === $tokens[ $prev ]['code'] ) {
						$is_array = true;
					} elseif ( false !== $prev && T_OPEN_PARENTHESIS === $tokens[ $prev ]['code'] ) {
						$pp       = $file->findPrevious( Tokens::$emptyTokens, $prev - 1, null, true );
						$is_array = ( false !== $pp && T_ARRAY === $tokens[ $pp ]['code'] ); // array( 'parent', ... ), no __( 'Parent', ... )
					}
					if ( $is_array && false !== $next && T_COMMA === $tokens[ $next ]['code'] ) {
						$m = $file->findNext( Tokens::$emptyTokens, $next + 1, null, true );
						// Exactamente dos elementos, y el segundo con pinta de nombre de método (no otra palabra clave).
						$after = ( false !== $m ) ? $file->findNext( Tokens::$emptyTokens, $m + 1, null, true ) : false;
						if ( false !== $m && T_CONSTANT_ENCAPSED_STRING === $tokens[ $m ]['code']
							&& preg_match( '/^([\'"])(?!(self|parent|static)\1)[A-Za-z_]\w*\1$/', $tokens[ $m ]['content'] )
							&& false !== $after && in_array( $tokens[ $after ]['code'], array( T_CLOSE_PARENTHESIS, T_CLOSE_SHORT_ARRAY ), true ) ) {
							$out['callables'][] = array( 'file' => $rel, 'line' => $t['line'], 'text' => 'array(' . $t['content'] . ', ' . $tokens[ $m ]['content'] . ')' );
						}
					}
				}
				continue;
			}
			if ( T_CLASS !== $t['code'] || ! isset( $t['scope_opener'], $t['scope_closer'] ) ) {
				continue;
			}

			$name = ObjectDeclarations::getName( $file, $i );
			if ( ! $name ) {
				continue; // clase anónima
			}
			$ns = Namespaces::determineNamespace( $file, $i );
			$fq = strtolower( ( $ns ? $ns . '\\' : '' ) . $name );

			$parent = ObjectDeclarations::findExtendedClassName( $file, $i );
			$parent = $parent ? self::resolve( $parent, $ns, $uses ) : '';

			$props = array();
			foreach ( ObjectDeclarations::getDeclaredProperties( $file, $i ) as $pname => $ptr ) {
				$props[ ltrim( $pname, '$' ) ] = 1;
			}
			$methods = ObjectDeclarations::getDeclaredMethods( $file, $i );
			$open    = isset( $methods['__set'] ) || self::uses_trait( $file, $i ) || self::has_attribute( $file, $i, 'AllowDynamicProperties' );

			// Parámetros promovidos del constructor: public function __construct( private $x ) declara $x.
			foreach ( $methods as $mname => $mptr ) {
				if ( '__construct' === strtolower( $mname ) ) {
					foreach ( FunctionDeclarations::getParameters( $file, $mptr ) as $p ) {
						if ( ! empty( $p['property_visibility'] ) ) {
							$props[ ltrim( $p['name'], '$' ) ] = 1;
						}
					}
				}
			}

			if ( isset( $out['facts'][ $fq ] ) ) {
				$out['facts'][ $fq ]['dup'] = true; // definida dos veces en el mismo archivo: rara, la tratamos como ambigua
			} else {
				$out['facts'][ $fq ] = array( 'parent' => $parent, 'open' => $open, 'props' => $props );
			}

			if ( $open ) {
				continue;
			}

			// Candidatos: $this->x = ... en métodos no estáticos de esta clase.
			$seen = array();
			for ( $j = $t['scope_opener'] + 1; $j < $t['scope_closer']; $j++ ) {
				$tj = $tokens[ $j ];
				if ( T_VARIABLE !== $tj['code'] || '$this' !== $tj['content'] ) {
					continue;
				}
				$op = $file->findNext( Tokens::$emptyTokens, $j + 1, null, true );
				if ( false === $op || T_OBJECT_OPERATOR !== $tokens[ $op ]['code'] ) {
					continue;
				}
				$pn = $file->findNext( Tokens::$emptyTokens, $op + 1, null, true );
				if ( false === $pn || T_STRING !== $tokens[ $pn ]['code'] ) {
					continue;
				}
				$prop = $tokens[ $pn ]['content'];
				if ( isset( $props[ $prop ] ) || isset( $seen[ $prop ] ) ) {
					continue;
				}
				// Saltar índices: $this->x['a']['b'] = ...
				$k = $file->findNext( Tokens::$emptyTokens, $pn + 1, null, true );
				while ( false !== $k && T_OPEN_SQUARE_BRACKET === $tokens[ $k ]['code'] && isset( $tokens[ $k ]['bracket_closer'] ) ) {
					$k = $file->findNext( Tokens::$emptyTokens, $tokens[ $k ]['bracket_closer'] + 1, null, true );
				}
				if ( false === $k || ! isset( Tokens::$assignmentTokens[ $tokens[ $k ]['code'] ] ) ) {
					continue;
				}
				// ¿Dentro de qué función estamos? Closures y métodos estáticos no cuentan.
				if ( ! self::in_instance_method( $file, $j, $i ) ) {
					continue;
				}
				$seen[ $prop ]        = 1;
				$out['candidates'][] = array(
					'class'   => $fq,
					'display' => $name,
					'prop'    => $prop,
					'file'    => $rel,
					'line'    => $tj['line'],
				);
			}
		}
		return $out;
	}

	/** Mapa alias_lower => fq_lower de los `use` de nivel de archivo. */
	private static function use_map( $file ) {
		$tokens = $file->getTokens();
		$map    = array();
		foreach ( $tokens as $i => $t ) {
			if ( T_USE !== $t['code'] ) {
				continue;
			}
			if ( ! empty( $t['conditions'] ) ) {
				continue; // use dentro de clase (trait) o closure: no es import
			}
			try {
				$split = UseStatements::splitImportUseStatement( $file, $i );
			} catch ( \Throwable $e ) {
				continue;
			}
			foreach ( $split['name'] as $alias => $fq ) {
				$map[ strtolower( $alias ) ] = strtolower( ltrim( $fq, '\\' ) );
			}
		}
		return $map;
	}

	/** Nombre de clase tal como está escrito → nombre completo en minúsculas. */
	private static function resolve( $written, $ns, $uses ) {
		if ( '\\' === $written[0] ) {
			return strtolower( ltrim( $written, '\\' ) );
		}
		$parts = explode( '\\', $written );
		$first = strtolower( $parts[0] );
		if ( isset( $uses[ $first ] ) ) {
			$parts[0] = $uses[ $first ];
			return strtolower( implode( '\\', $parts ) );
		}
		return strtolower( ( $ns ? $ns . '\\' : '' ) . $written );
	}

	private static function uses_trait( $file, $class_ptr ) {
		$tokens = $file->getTokens();
		for ( $j = $tokens[ $class_ptr ]['scope_opener'] + 1; $j < $tokens[ $class_ptr ]['scope_closer']; $j++ ) {
			if ( T_USE === $tokens[ $j ]['code'] && isset( $tokens[ $j ]['conditions'][ $class_ptr ] ) && count( $tokens[ $j ]['conditions'] ) === count( $tokens[ $class_ptr ]['conditions'] ) + 1 ) {
				return true;
			}
			if ( T_FUNCTION === $tokens[ $j ]['code'] && isset( $tokens[ $j ]['scope_closer'] ) ) {
				$j = $tokens[ $j ]['scope_closer'];
			}
		}
		return false;
	}

	private static function has_attribute( $file, $class_ptr, $attr ) {
		$tokens = $file->getTokens();
		$p      = $class_ptr;
		while ( ( $p = $file->findPrevious( Tokens::$emptyTokens, $p - 1, null, true ) ) !== false ) {
			$code = $tokens[ $p ]['code'];
			if ( T_ATTRIBUTE_END === $code && isset( $tokens[ $p ]['attribute_opener'] ) ) {
				$text = $file->getTokensAsString( $tokens[ $p ]['attribute_opener'], $p - $tokens[ $p ]['attribute_opener'] + 1 );
				if ( stripos( $text, $attr ) !== false ) {
					return true;
				}
				$p = $tokens[ $p ]['attribute_opener'];
				continue;
			}
			if ( in_array( $code, array( T_ABSTRACT, T_FINAL, T_READONLY ), true ) ) {
				continue;
			}
			break;
		}
		return false;
	}

	/** ¿El token está en un método de instancia de la clase (no closure, no estático, no función suelta)? */
	private static function in_instance_method( $file, $ptr, $class_ptr ) {
		$tokens = $file->getTokens();
		$conds  = array_reverse( $tokens[ $ptr ]['conditions'], true );
		foreach ( $conds as $cptr => $code ) {
			if ( T_CLOSURE === $code || T_FN === $code ) {
				return false;
			}
			if ( T_FUNCTION === $code ) {
				$parent_conds = array_keys( $tokens[ $cptr ]['conditions'] );
				if ( end( $parent_conds ) !== $class_ptr ) {
					return false;
				}
				$props = FunctionDeclarations::getProperties( $file, $cptr );
				return empty( $props['is_static'] );
			}
		}
		return false;
	}

	/**
	 * Cruza candidatos contra los hechos de clase. Devuelve mensajes en el formato del escáner.
	 */
	public static function resolve_candidates( array $candidates, array $facts ) {
		$msgs = array();
		foreach ( $candidates as $c ) {
			$cur   = $facts[ $c['class'] ]['parent'] ?? '';
			$depth = 0;
			$drop  = false;
			while ( '' !== $cur && $depth++ < 50 ) {
				if ( in_array( $cur, self::OPEN_BUILTINS, true ) ) {
					$drop = true;
					break;
				}
				if ( ! isset( $facts[ $cur ] ) || ! empty( $facts[ $cur ]['dup'] ) || $facts[ $cur ]['open'] ) {
					$drop = true; // ancestro desconocido/ambiguo/abierto: no afirmamos nada
					break;
				}
				if ( isset( $facts[ $cur ]['props'][ $c['prop'] ] ) ) {
					$drop = true;
					break;
				}
				$cur = $facts[ $cur ]['parent'];
			}
			if ( $drop ) {
				continue;
			}
			$msgs[] = array(
				'file'    => $c['file'],
				'line'    => $c['line'],
				'type'    => 'W',
				'source'  => self::SRC_DYNAMIC,
				'message' => sprintf( 'Creation of dynamic property %s::$%s is deprecated since PHP 8.2; declare the property in the class.', $c['display'], $c['prop'] ),
				'guard'   => null,
			);
		}
		return $msgs;
	}

	public static function callable_messages( array $callables ) {
		$msgs = array();
		foreach ( $callables as $c ) {
			$msgs[] = array(
				'file'    => $c['file'],
				'line'    => $c['line'],
				'type'    => 'W',
				'source'  => self::SRC_CALLABLE,
				'message' => sprintf( 'Using %s as a callable string is deprecated since PHP 8.2; use an array callable or a first-class callable instead.', $c['text'] ),
				'guard'   => null,
			);
		}
		return $msgs;
	}

	/** Merge de hechos de varios archivos: una clase definida en dos lugares distintos queda ambigua. */
	public static function merge_facts( array $into, array $new ) {
		foreach ( $new as $fq => $f ) {
			if ( isset( $into[ $fq ] ) ) {
				if ( $into[ $fq ] !== $f ) {
					$into[ $fq ]['dup'] = true;
				}
			} else {
				$into[ $fq ] = $f;
			}
		}
		return $into;
	}
}
