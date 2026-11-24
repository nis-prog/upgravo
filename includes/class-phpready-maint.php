<?php
/**
 * Señales de mantenimiento por plugin.
 *
 * Sin llamadas externas: usa lo que WordPress ya bajó al buscar actualizaciones (transient
 * update_plugins): "tested up to" y "requires PHP" de la última versión publicada.
 *
 * Opcional (solo si el administrador lo activa en la pantalla de Upgravo): consulta
 * api.wordpress.org por el slug de cada plugin para saber si el directorio lo cerró y cuándo se
 * actualizó por última vez. Se manda solo el slug (nombre de la carpeta), nada del sitio.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHPReady_Maint {

	const OPT        = 'phpready_wporg_check';
	const CACHE      = 'phpready_wporg_cache';
	const TTL        = DAY_IN_SECONDS;
	const TTL_ERROR  = HOUR_IN_SECONDS;
	const PER_CALL   = 6;
	const STALE_DAYS = 730; // dos años sin actualizar

	public static function init() {
		add_action( 'wp_ajax_phpready_wporg', array( __CLASS__, 'ajax' ) );
	}

	public static function enabled() {
		return '1' === get_option( self::OPT, '' );
	}

	public static function set_enabled( $on ) {
		update_option( self::OPT, $on ? '1' : '', false );
	}

	/** Lo que WordPress ya sabe del plugin por la búsqueda de actualizaciones (o null). */
	private static function core_info( $key ) {
		static $t = null;
		if ( null === $t ) {
			$t = get_site_transient( 'update_plugins' );
		}
		if ( ! is_object( $t ) ) {
			return null;
		}
		foreach ( array( 'response', 'no_update' ) as $k ) {
			$list = isset( $t->{$k} ) ? (array) $t->{$k} : array();
			if ( isset( $list[ $key ] ) && is_object( $list[ $key ] ) ) {
				return $list[ $key ];
			}
		}
		return null;
	}

	/** Slug de WordPress.org: el que dio WordPress, o la carpeta. Los plugins de un solo archivo no se consultan. */
	private static function slug_for( array $it ) {
		$core = self::core_info( $it['key'] );
		if ( $core && ! empty( $core->slug ) ) {
			return sanitize_key( $core->slug );
		}
		$dir = dirname( $it['key'] );
		return '.' === $dir ? '' : sanitize_key( $dir );
	}

	/** "Contact Form 7" → "contactform7", para comparar nombres sin importar mayúsculas ni signos. */
	private static function norm( $name ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/** Rama "6.9" → 69, "7.0" → 70: las ramas de WordPress son consecutivas así. */
	private static function branch_num( $v ) {
		$p = explode( '.', (string) $v );
		return isset( $p[1] ) ? (int) $p[0] * 10 + (int) $p[1] : (int) $p[0] * 10;
	}

	/**
	 * Señales de un ítem escaneado. Devuelve una lista de array( 'code' => ..., 'text' => ... ),
	 * vacía si no hay nada que señalar. Solo hechos, sin recomendaciones.
	 */
	public static function signals( array $it, $target ) {
		if ( 'plugin' !== $it['type'] ) {
			return array();
		}
		$out  = array();
		$core = self::core_info( $it['key'] );

		$api  = null;
		$slug = self::slug_for( $it );
		if ( $slug && self::enabled() ) {
			$cache = get_option( self::CACHE, array() );
			if ( isset( $cache[ $slug ] ) ) {
				$api = $cache[ $slug ];
				// Si WordPress no lo conoce como plugin del directorio, el slug podría ser de OTRO
				// plugin con la misma carpeta (uno a medida o de pago): solo vale si el nombre coincide.
				if ( ! $core && ( empty( $api['name'] ) || self::norm( $api['name'] ) !== self::norm( $it['name'] ) ) ) {
					$api = null;
				}
			}
		}

		if ( $api && ! empty( $api['closed'] ) ) {
			$out[] = array(
				'code' => 'closed',
				'text' => $api['reason']
					/* translators: 1: date, 2: reason given by WordPress.org (e.g. "Security Issue") */
					? sprintf( __( 'Closed on WordPress.org on %1$s (%2$s)', 'upgravo' ), $api['closed_date'], $api['reason'] )
					/* translators: %s: date */
					: sprintf( __( 'Closed on WordPress.org on %s', 'upgravo' ), $api['closed_date'] ),
			);
			return $out; // cerrado: lo demás es historia
		}

		if ( $api && ! empty( $api['last_updated'] ) && ( time() - $api['last_updated'] ) > self::STALE_DAYS * DAY_IN_SECONDS ) {
			$out[] = array(
				'code' => 'stale',
				/* translators: %s: date */
				'text' => sprintf( __( 'Last updated on WordPress.org on %s', 'upgravo' ), wp_date( get_option( 'date_format' ), $api['last_updated'] ) ),
			);
		}

		$tested = $core && ! empty( $core->tested ) ? $core->tested : ( $api['tested'] ?? '' );
		if ( $tested && self::branch_num( get_bloginfo( 'version' ) ) - self::branch_num( $tested ) >= 3 ) {
			$out[] = array(
				'code' => 'untested',
				/* translators: %s: WordPress version */
				'text' => sprintf( __( 'Tested up to WordPress %s', 'upgravo' ), $tested ),
			);
		}

		$req = $core && ! empty( $core->requires_php ) ? $core->requires_php : '';
		if ( $req && version_compare( $req, $target, '>' ) ) {
			$out[] = array(
				'code' => 'requires_php',
				/* translators: 1: plugin version, 2: PHP version */
				'text' => sprintf( __( 'Version %1$s requires PHP %2$s', 'upgravo' ), ! empty( $core->new_version ) ? $core->new_version : $it['version'], $req ),
			);
		}
		return $out;
	}

	/** Slugs del escaneo que todavía no están en la caché (o vencieron). */
	public static function pending( $job ) {
		if ( ! self::enabled() || ! $job || 'done' !== $job['status'] ) {
			return array();
		}
		$cache = get_option( self::CACHE, array() );
		$now   = time();
		$out   = array();
		foreach ( $job['items'] as $it ) {
			if ( 'plugin' !== $it['type'] ) {
				continue;
			}
			$slug = self::slug_for( $it );
			if ( ! $slug || isset( $out[ $slug ] ) ) {
				continue;
			}
			$c = $cache[ $slug ] ?? null;
			if ( ! $c || $now - $c['t'] > ( empty( $c['error'] ) ? self::TTL : self::TTL_ERROR ) ) {
				$out[ $slug ] = true;
			}
		}
		return array_keys( $out );
	}

	/** Consulta un slug en api.wordpress.org. Solo pide los campos que usa. */
	private static function fetch( $slug ) {
		$fields = array( 'last_updated' => 1, 'tested' => 1 );
		foreach ( array( 'sections', 'versions', 'reviews', 'screenshots', 'banners', 'icons', 'contributors', 'ratings', 'description', 'short_description', 'tags', 'donate_link', 'compatibility', 'downloaded', 'active_installs', 'homepage', 'download_link', 'added' ) as $f ) {
			$fields[ $f ] = 0;
		}
		$url = add_query_arg(
			array(
				'action'  => 'plugin_information',
				'request' => array( 'slug' => $slug, 'fields' => $fields ),
			),
			'https://api.wordpress.org/plugins/info/1.2/'
		);
		$res = wp_remote_get( $url, array( 'timeout' => 8 ) );
		$row = array( 't' => time() );
		if ( is_wp_error( $res ) || ! in_array( (int) wp_remote_retrieve_response_code( $res ), array( 200, 404 ), true ) ) {
			$row['error'] = 1;
			return $row;
		}
		$d = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $d ) ) {
			$row['error'] = 1;
			return $row;
		}
		if ( ! empty( $d['closed'] ) ) {
			$row['closed']      = 1;
			$row['closed_date'] = sanitize_text_field( $d['closed_date'] ?? '' );
			$row['reason']      = sanitize_text_field( $d['reason_text'] ?? '' );
			$row['name']        = sanitize_text_field( $d['name'] ?? '' );
			return $row;
		}
		if ( ! empty( $d['error'] ) ) {
			$row['notfound'] = 1;
			return $row;
		}
		$row['name']         = sanitize_text_field( $d['name'] ?? '' );
		$row['tested']       = sanitize_text_field( $d['tested'] ?? '' );
		$lu                  = isset( $d['last_updated'] ) ? strtotime( str_replace( array( 'am', 'pm' ), array( ' am', ' pm' ), $d['last_updated'] ) ) : false;
		$row['last_updated'] = $lu ? $lu : 0;
		return $row;
	}

	/** AJAX: consulta unos pocos slugs pendientes y dice cuántos quedan. El JS repite hasta 0. */
	public static function ajax() {
		if ( ! current_user_can( phpready_cap() ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		check_ajax_referer( 'phpready_ajax', 'nonce' );
		$job     = PHPReady_Job::get();
		$pending = self::pending( $job );
		if ( $pending ) {
			$cache = get_option( self::CACHE, array() );
			foreach ( array_slice( $pending, 0, self::PER_CALL ) as $slug ) {
				$cache[ $slug ] = self::fetch( $slug );
			}
			update_option( self::CACHE, $cache, false );
		}
		wp_send_json_success( array( 'left' => count( self::pending( $job ) ) ) );
	}
}
