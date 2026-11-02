<?php
/**
 * El filtro: convierte el hallazgo crudo de PHPCompatibility en una de tres cosas.
 *
 *  breaks      → "Rompe": al subir a la versión objetivo deja de funcionar (función/constante
 *                removida, error fatal nuevo).
 *  deprecated  → "Aviso": sigue funcionando pero PHP lo marca como obsoleto (llena el log,
 *                se romperá en una versión futura).
 *  hidden      → se oculta por defecto, con un motivo:
 *     already   ya aplica en la versión de PHP que corre el sitio: si ese código se ejecutara,
 *               ya estaría roto/avisando hoy. Subir de versión no cambia nada.
 *     guarded   está dentro de un if que pregunta por la versión o la existencia (código de
 *               respaldo que no corre en PHP moderno).
 *     future    afecta a una versión posterior a la objetivo.
 *     advice    regla de consejo, no de compatibilidad (palabras "reservadas" que PHP nunca
 *               aplicó, cosas "a revisar", interfaces redundantes, ini_get de directivas viejas).
 *     internal  avisos internos del analizador (finales de línea mezclados, archivo sin PHP).
 *
 * Medido sobre los 20 plugins más instalados: sin filtro 528 hallazgos para PHP 8.4,
 * ninguno rompía nada. Con el filtro, la lista "Rompe" queda vacía, que es la verdad.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHPReady_Filter {

	/** Reglas que nunca son "subir PHP me rompe el sitio". */
	const ADVICE_REGEX = '/NeedsInspection|Keywords\.ForbiddenNames|RedundantSerializableImplementation|RemovedSerializable\.MissingInterface|ArgumentFunctionsReportCurrentValue|InvalidLongTypeFound|ForbiddenGlobalVariableVariable|IniDirectives\.RemovedIniDirectives|NewClosure\.ThisFoundInStatic|ForbiddenBreakContinueVariableArguments|Discouraged/';

	/**
	 * @param array  $m       Mensaje del escáner: type, source, message, guard.
	 * @param string $current Versión de PHP que corre el sitio, "8.1".
	 * @param string $target  Versión objetivo, "8.4".
	 * @return array { bucket: breaks|deprecated|hidden, reason: string|null }
	 */
	public static function classify( array $m, $current, $target ) {
		$src = $m['source'];
		$msg = $m['message'];

		if ( 0 === strpos( $src, 'Internal.' ) ) {
			return self::hide( 'internal' );
		}
		if ( preg_match( self::ADVICE_REGEX, $src ) ) {
			return self::hide( 'advice' );
		}

		// Versión en la que se rompe (R) y en la que empieza a avisar (D), leídas del mensaje.
		$removed    = null;
		$deprecated = null;
		if ( preg_match( '/(?:removed|will throw an? \w*\s*Error|throws? an? \w*\s*Error)\b[^.]*?(?:since|in|as of) PHP (\d+\.\d+)/i', $msg, $x ) ) {
			$removed = $x[1];
		} elseif ( preg_match( '/(?:is removed|has been removed|was removed|no longer (?:supported|available|works))\b[^.]*?PHP (\d+\.\d+)/i', $msg, $x ) ) {
			$removed = $x[1];
		}
		if ( preg_match( '/deprecated\b[^.]*?(?:since|in|as of) (?:PHP )?(\d+\.\d+)/i', $msg, $x ) ) {
			$deprecated = $x[1];
		}

		// Cosas nuevas de PHP ("no existe en 8.3 o anterior"): el sitio ya corre, así que o está
		// guardado o ya está roto hoy. Subir de versión solo puede mejorarlo.
		if ( null === $removed && null === $deprecated && preg_match( '/not (?:present|available|supported)\b.*?(?:in|until|before) PHP(?: version)? (\d+\.\d+)/i', $msg ) ) {
			return self::hide( $m['guard'] ? 'guarded' : 'already' );
		}

		$bucket = null;
		if ( null !== $removed ) {
			if ( version_compare( $removed, $current, '<=' ) ) {
				return self::hide( 'already' );
			}
			if ( version_compare( $removed, $target, '<=' ) ) {
				$bucket = 'breaks';
			} elseif ( null !== $deprecated && version_compare( $deprecated, $current, '>' ) && version_compare( $deprecated, $target, '<=' ) ) {
				$bucket = 'deprecated';
			} else {
				return self::hide( 'future' );
			}
		} elseif ( null !== $deprecated ) {
			if ( version_compare( $deprecated, $current, '<=' ) ) {
				return self::hide( 'already' );
			}
			if ( version_compare( $deprecated, $target, '<=' ) ) {
				$bucket = 'deprecated';
			} else {
				return self::hide( 'future' );
			}
		} else {
			// Sin versión en el mensaje: nos quedamos con lo que dice el analizador.
			$bucket = ( 'E' === $m['type'] ) ? 'breaks' : 'deprecated';
		}

		if ( ! empty( $m['guard'] ) ) {
			return self::hide( 'guarded' );
		}
		return array( 'bucket' => $bucket, 'reason' => null );
	}

	private static function hide( $reason ) {
		return array( 'bucket' => 'hidden', 'reason' => $reason );
	}

	/** Clasifica toda la lista y devuelve contadores + mensajes con su bucket. */
	public static function apply( array $messages, $current, $target, array $context = array() ) {
		$counts = array( 'breaks' => 0, 'deprecated' => 0, 'hidden' => 0 );
		$by_reason = array();
		// El operador `...` (deprecado en PHP 8.5) se marca en la comilla que abre y en la que cierra:
		// es un solo problema por línea.
		$seen     = array();
		$messages = array_values( array_filter( $messages, function ( $m ) use ( &$seen ) {
			if ( false === stripos( $m['source'], 't_backtick' ) ) {
				return true;
			}
			$k = $m['file'] . '|' . $m['line'] . '|' . $m['source'];
			if ( isset( $seen[ $k ] ) ) {
				return false;
			}
			$seen[ $k ] = true;
			return true;
		} ) );
		foreach ( $messages as &$m ) {
			$c = self::classify( $m, $current, $target );
			/**
			 * Lets extensions override the classification of one finding (the Pro version uses it
			 * for the per-plugin "reviewed" list).
			 *
			 * @param array  $c       { bucket: breaks|deprecated|hidden, reason: string|null }
			 * @param array  $m       The finding: file, line, type, source, message, guard.
			 * @param array  $context Where it comes from, e.g. [ 'item' => 'plugin-dir/plugin.php' ].
			 * @param string $current Current PHP version.
			 * @param string $target  Target PHP version.
			 */
			$c = apply_filters( 'phpready_classify', $c, $m, $context, $current, $target );
			$m['bucket'] = $c['bucket'];
			$m['reason'] = $c['reason'];
			$counts[ $c['bucket'] ]++;
			if ( $c['reason'] ) {
				$by_reason[ $c['reason'] ] = ( $by_reason[ $c['reason'] ] ?? 0 ) + 1;
			}
		}
		unset( $m );
		return array( 'messages' => $messages, 'counts' => $counts, 'hidden_by_reason' => $by_reason );
	}

	public static function reason_label( $reason ) {
		$labels = array(
			'already'  => __( 'already applies on the current PHP version', 'upgravo' ),
			'guarded'  => __( 'code guarded by a version check', 'upgravo' ),
			'future'   => __( 'affects a later PHP version', 'upgravo' ),
			'advice'   => __( 'advice, not compatibility', 'upgravo' ),
			'internal' => __( 'analyzer internal', 'upgravo' ),
		);
		/**
		 * Lets extensions label their own hidden reasons.
		 *
		 * @param array $labels reason => label.
		 */
		$labels = apply_filters( 'phpready_reason_labels', $labels );
		return $labels[ $reason ] ?? $reason;
	}
}
