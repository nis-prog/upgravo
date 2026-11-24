<?php
/**
 * Plugin Name:       Upgravo – PHP Version Compatibility Checker
 * Plugin URI:        https://phpready.com/
 * Description:       Scans your plugins and theme on your own server and tells you what will break before you upgrade PHP. No external service, no exec().
 * Version:           0.1.11
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Nicolás Alejandro Salinas
 * Author URI:        https://profiles.wordpress.org/nicolassalinas/
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       upgravo
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PHPREADY_VERSION', '0.1.11' );
define( 'PHPREADY_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHPREADY_URL', plugin_dir_url( __FILE__ ) );

require_once PHPREADY_DIR . 'includes/class-phpready-scanner.php';
require_once PHPREADY_DIR . 'includes/class-phpready-classes.php';
require_once PHPREADY_DIR . 'includes/class-phpready-filter.php';
require_once PHPREADY_DIR . 'includes/class-phpready-job.php';
require_once PHPREADY_DIR . 'includes/class-phpready-extras.php';
require_once PHPREADY_DIR . 'includes/class-phpready-maint.php';
require_once PHPREADY_DIR . 'includes/class-phpready-fixes.php';

PHPReady_Job::init();
PHPReady_Extras::init();
PHPReady_Maint::init();

/**
 * Review request: shown only on the Upgravo screen, after a finished scan, to users who can run scans.
 * Stored per user: 'done' (rated or never ask again) or a timestamp until which it stays hidden.
 */
function phpready_review_due() {
	$v = get_user_meta( get_current_user_id(), 'phpready_review', true );
	if ( 'done' === $v ) {
		return false;
	}
	return ! $v || time() > (int) $v;
}

add_action( 'admin_post_phpready_review', function () {
	if ( ! current_user_can( phpready_cap() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'upgravo' ) );
	}
	check_admin_referer( 'phpready_review' );
	$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
	if ( 'later' === $do ) {
		update_user_meta( get_current_user_id(), 'phpready_review', time() + 14 * DAY_IN_SECONDS );
	} else {
		update_user_meta( get_current_user_id(), 'phpready_review', 'done' );
	}
	if ( 'rate' === $do ) {
		wp_redirect( 'https://wordpress.org/support/plugin/upgravo/reviews/#new-post' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed external URL (the plugin's own review form)
		exit;
	}
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=phpready' ) );
	exit;
} );


/**
 * Modo red: en un multisitio, la versión Pro activada en toda la red pone una sola página en el
 * administrador de la red que escanea lo que usa cualquier sitio. La gratis siempre va por sitio.
 */
function phpready_is_network() {
	return (bool) apply_filters( 'phpready_network', false );
}

function phpready_cap() {
	if ( phpready_is_network() ) {
		return 'manage_network_options';
	}
	// En un multisitio, los plugins y los mu-plugins son de toda la red: solo el superadministrador
	// puede escanearlos, aunque el plugin esté activado en un solo sitio.
	return is_multisite() ? 'manage_network_plugins' : 'manage_options';
}

/** URL de la pantalla de Upgravo (del sitio o de la red, según el modo). */
function phpready_page_url( array $args = array() ) {
	$base = phpready_is_network() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	return add_query_arg( array_merge( array( 'page' => 'phpready' ), $args ), $base );
}

function phpready_register_menu() {
	// Cada gancho decide si le toca: por sitio en modo normal, solo en la red en modo red.
	$network_screen = 'network_admin_menu' === current_action();
	if ( $network_screen !== phpready_is_network() ) {
		return;
	}
	add_menu_page(
		'Upgravo',
		'Upgravo',
		phpready_cap(),
		'phpready',
		'phpready_render_page',
		'dashicons-yes-alt',
		80
	);
}
add_filter( 'plugin_row_meta', function ( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file && ! defined( 'PHPREADY_PRO' ) ) {
		$links[] = '<a href="https://checkout.freemius.com/product/42222/plan/73475/" target="_blank" rel="noopener">' . esc_html__( 'Pro version', 'upgravo' ) . '</a>';
	}
	return $links;
}, 10, 2 );

add_action( 'admin_menu', 'phpready_register_menu' );
add_action( 'network_admin_menu', 'phpready_register_menu' );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'toplevel_page_phpready' !== $hook && 'toplevel_page_phpready-network' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'phpready-admin', PHPREADY_URL . 'assets/admin.js', array(), PHPREADY_VERSION, true );

	$job    = PHPReady_Job::get();
	$resume = null;
	if ( $job && 'running' === $job['status'] ) {
		$cur    = $job['items'][ $job['current_item'] ];
		$resume = array(
			'item_name' => $cur['name'],
			'item_num'  => $job['current_item'] + 1,
			'items'     => count( $job['items'] ),
			'index'     => $cur['index'],
			'total'     => $cur['total'],
		);
	}
	wp_localize_script( 'phpready-admin', 'PHPReady', array(
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'phpready_ajax' ),
		'resume'  => $resume,
		'wporg'   => count( PHPReady_Maint::pending( $job ) ),
		'i18n'    => array(
			'files'    => __( 'files', 'upgravo' ),
			'error'    => __( 'Error:', 'upgravo' ),
			'copied'   => __( 'Copied', 'upgravo' ),
			'checking' => __( 'Checking WordPress.org…', 'upgravo' ),
		),
	) );
} );

function phpready_target_versions() {
	return array( '8.1', '8.2', '8.3', '8.4', '8.5' );
}

/** "8.1" a partir de "8.1.29". */
function phpready_current_version() {
	return implode( '.', array_slice( explode( '.', PHP_VERSION ), 0, 2 ) );
}

/**
 * Agrupa los mensajes visibles de un ítem por (categoría, regla): una fila por regla
 * con cuántas veces aparece y en qué archivos, en vez de 155 filas iguales.
 */
function phpready_group_messages( array $messages ) {
	$groups = array();
	foreach ( $messages as $m ) {
		$k = $m['bucket'] . '|' . $m['source'];
		if ( ! isset( $groups[ $k ] ) ) {
			$groups[ $k ] = array(
				'bucket'  => $m['bucket'],
				'source'  => $m['source'],
				'example' => $m['message'],
				'count'   => 0,
				'places'  => array(),
			);
		}
		$groups[ $k ]['count']++;
		$groups[ $k ]['places'][] = $m['file'] . ':' . $m['line'];
	}
	// Rompe primero, después por cantidad.
	uasort( $groups, function ( $a, $b ) {
		if ( $a['bucket'] !== $b['bucket'] ) {
			return 'breaks' === $a['bucket'] ? -1 : 1;
		}
		return $b['count'] <=> $a['count'];
	} );
	return $groups;
}

function phpready_render_page() {
	if ( ! current_user_can( phpready_cap() ) ) {
		return;
	}

	$versions = phpready_target_versions();
	$current  = phpready_current_version();
	$job      = PHPReady_Job::get();
	$running  = $job && 'running' === $job['status'];
	$done     = $job && 'done' === $job['status'];
	$target   = $job ? $job['target'] : end( $versions );
	?>
	<div class="wrap">
		<h1>Upgravo</h1>

		<form id="phpready-form" method="post" <?php echo $running ? 'style="display:none"' : ''; ?>>
			<p>
				<?php esc_html_e( 'Current PHP:', 'upgravo' ); ?> <strong><?php echo esc_html( PHP_VERSION ); ?></strong>
				&nbsp;→&nbsp; <?php esc_html_e( 'check for PHP', 'upgravo' ); ?>
				<select name="phpready_target" id="phpready_target">
					<?php foreach ( $versions as $v ) : ?>
						<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $target, $v ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
				&nbsp;
				<button type="submit" class="button button-primary"><?php echo $done ? esc_html__( 'Scan again', 'upgravo' ) : esc_html__( 'Scan plugins and theme', 'upgravo' ); ?></button>
				&nbsp;&nbsp;
				<label><input type="checkbox" name="phpready_inactive" value="1" <?php checked( ! empty( $job['inactive'] ) ); ?>> <?php esc_html_e( 'Include inactive plugins', 'upgravo' ); ?></label>
			</p>
			<p style="margin-top:-6px">
				<label><input type="checkbox" name="phpready_wporg" value="1" <?php checked( PHPReady_Maint::enabled() ); ?>> <?php esc_html_e( 'Also check on WordPress.org whether each plugin is still maintained (sends the plugin folder names to api.wordpress.org)', 'upgravo' ); ?></label>
			</p>
		</form>
		<?php
		/**
		 * Lets extensions add settings below the scan form (the Pro version adds scheduled scans here).
		 *
		 * @param array|null $job Current or last scan state.
		 */
		do_action( 'phpready_after_form', $job );

		if ( ! defined( 'PHPREADY_PRO' ) ) :
			?>
			<p class="description" style="margin:6px 0 0">
				<?php esc_html_e( 'Upgravo Pro adds scheduled scans with email alerts, a PDF report for clients, a whitelist of false positives and multisite.', 'upgravo' ); ?>
				<a href="https://checkout.freemius.com/product/42222/plan/73475/" target="_blank" rel="noopener"><?php esc_html_e( 'Get Pro', 'upgravo' ); ?></a>
			</p>
			<?php
		endif;
		?>

		<div id="phpready-progress" style="<?php echo $running ? '' : 'display:none;'; ?>max-width:640px;margin:1em 0">
			<div style="background:#dcdcde;height:18px;border-radius:3px;overflow:hidden">
				<div id="phpready-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s"></div>
			</div>
			<p><span id="phpready-text"></span> &nbsp; <button type="button" id="phpready-cancel" class="button"><?php esc_html_e( 'Cancel', 'upgravo' ); ?></button></p>
		</div>

		<?php if ( $done ) : ?>
			<?php
			$rows    = PHPReady_Extras::evaluate( $job );
			$verdict = PHPReady_Extras::verdict( $rows );
			$steps   = PHPReady_Extras::next_steps( $rows, $job['target'] );
			$tot     = array( 'breaks' => 0, 'deprecated' => 0, 'hidden' => 0 );
			$n_files = 0;
			$secs    = 0;
			$peak    = 0;
			foreach ( $rows as $r ) {
				foreach ( $tot as $k => $_ ) {
					$tot[ $k ] += $r['filtered']['counts'][ $k ];
				}
				$n_files += $r['item']['total'];
				$secs    += $r['item']['seconds'];
				$peak     = max( $peak, $r['item']['peak_mb'] );
			}
			$csv_url = wp_nonce_url( admin_url( 'admin-post.php?action=phpready_csv' ), 'phpready_csv' );
			$colors  = array( 'ok' => '#00843d', 'warn' => '#00843d', 'incomplete' => '#996800', 'break' => '#b32d2e' );
			$titles  = array(
				/* translators: %s: PHP version */
				'ok'         => sprintf( __( 'Ready for PHP %s: nothing breaks', 'upgravo' ), $job['target'] ),
				/* translators: %s: PHP version */
				'warn'       => sprintf( __( 'Ready for PHP %s: nothing breaks, with deprecation notices', 'upgravo' ), $job['target'] ),
				/* translators: %s: PHP version */
				'incomplete' => sprintf( __( 'PHP %s: nothing breaks in what was analyzed, but some files could not be checked', 'upgravo' ), $job['target'] ),
				/* translators: %s: PHP version */
				'break'      => sprintf( __( 'Not yet: something active breaks on PHP %s', 'upgravo' ), $job['target'] ),
			);
			?>
			<hr>
			<h2 style="color:<?php echo $colors[ $verdict ]; // phpcs:ignore ?>"><?php echo esc_html( $titles[ $verdict ] ); ?></h2>
			<p>
				PHP <?php echo esc_html( $job['current'] ); ?> → <?php echo esc_html( $job['target'] ); ?> &nbsp;·&nbsp;
				<span style="color:#b32d2e"><?php esc_html_e( 'Breaks:', 'upgravo' ); ?> <?php echo (int) $tot['breaks']; ?></span> &nbsp;·&nbsp;
				<span style="color:#996800"><?php esc_html_e( 'Notices:', 'upgravo' ); ?> <?php echo (int) $tot['deprecated']; ?></span> &nbsp;·&nbsp;
				<span style="color:#646970"><?php esc_html_e( 'Hidden:', 'upgravo' ); ?> <?php echo (int) $tot['hidden']; ?></span>
				&nbsp;&nbsp; <a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Download CSV', 'upgravo' ); ?></a>
				<button type="button" class="button" id="phpready-copy"><?php esc_html_e( 'Copy summary', 'upgravo' ); ?></button>
				<span id="phpready-wporg-status" class="description"></span>
			</p>
			<textarea id="phpready-summary" readonly rows="14" style="display:none;width:100%;max-width:1100px;font-family:monospace;font-size:12px"><?php echo esc_textarea( PHPReady_Extras::summary_text( $job, $rows, $titles[ $verdict ] ) ); ?></textarea>
			<p class="description">
				<?php
				/* translators: 1: number of items, 2: number of files, 3: seconds, 4: number of batches, 5: peak memory in MB, 6: date */
				echo esc_html( sprintf( __( '%1$d items · %2$d files · %3$d s in %4$d batches · peak memory %5$s MB · %6$s', 'upgravo' ), count( $rows ), $n_files, round( $secs ), $job['steps'], $peak, wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $job['finished'] ) ) );
				?>
			</p>

			<?php if ( $steps ) : ?>
				<div style="border-left:4px solid <?php echo $colors[ $verdict ]; // phpcs:ignore ?>;background:#fff;padding:8px 16px;margin:12px 0;max-width:1000px">
					<strong><?php esc_html_e( 'What to do first', 'upgravo' ); ?></strong>
					<ol style="margin:6px 0 4px 18px">
						<?php foreach ( $steps as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?>
					</ol>
				</div>
			<?php endif; ?>

			<?php
			if ( phpready_review_due() ) :
				$phpready_review_url = function ( $do ) {
					return wp_nonce_url( admin_url( 'admin-post.php?action=phpready_review&do=' . $do ), 'phpready_review' );
				};
				?>
				<div id="phpready-review" style="border-left:4px solid #2271b1;background:#fff;padding:10px 16px;margin:12px 0;max-width:1000px">
					<p style="margin:0 0 8px"><?php esc_html_e( 'Was this scan useful? A short review on WordPress.org helps other site owners find Upgravo.', 'upgravo' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( $phpready_review_url( 'rate' ) ); ?>" target="_blank" rel="noopener" data-phpready-review="1"><?php esc_html_e( 'Leave a review', 'upgravo' ); ?></a>
					&nbsp;<a class="button" href="<?php echo esc_url( $phpready_review_url( 'later' ) ); ?>"><?php esc_html_e( 'Maybe later', 'upgravo' ); ?></a>
					&nbsp;<a href="<?php echo esc_url( $phpready_review_url( 'never' ) ); ?>"><?php esc_html_e( "Don't ask again", 'upgravo' ); ?></a>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th style="width:34%"><?php esc_html_e( 'Plugin / theme', 'upgravo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'upgravo' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Breaks', 'upgravo' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Notices', 'upgravo' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Hidden', 'upgravo' ); ?></th>
						<th><?php esc_html_e( 'Files', 'upgravo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $r ) :
					$it = $r['item'];
					$c  = $r['filtered']['counts'];
					$has_detail = $c['breaks'] || $c['deprecated'] || $c['hidden'] || $it['skipped'] || $it['failed'];
					if ( $c['breaks'] ) {
						$light = '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#b32d2e"></span> ' . esc_html__( 'Breaks', 'upgravo' );
					} elseif ( $c['deprecated'] ) {
						$light = '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#dba617"></span> ' . esc_html__( 'Notices', 'upgravo' );
					} else {
						$light = '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#00843d"></span> ' . esc_html__( 'OK', 'upgravo' );
					}
					?>
					<tr class="phpready-row" data-detail="phpready-det-<?php echo (int) $r['idx']; ?>" style="<?php echo $has_detail ? 'cursor:pointer' : ''; ?>">
						<td>
							<strong><?php echo esc_html( $it['name'] ); ?></strong> <?php echo esc_html( $it['version'] ); ?>
							<?php if ( 'theme' === $it['type'] ) : ?><span class="description">(<?php esc_html_e( 'theme', 'upgravo' ); ?>)</span><?php endif; ?>
							<?php if ( ! $it['active'] ) : ?><span class="description">(<?php esc_html_e( 'inactive', 'upgravo' ); ?>)</span><?php endif; ?>
							<?php if ( ! empty( $it['update'] ) ) : ?><br><small style="color:#2271b1"><?php esc_html_e( 'Update available:', 'upgravo' ); ?> <?php echo esc_html( $it['update'] ); ?></small><?php endif; ?>
							<?php foreach ( PHPReady_Maint::signals( $it, $job['target'] ) as $sig ) : ?><br><small style="color:<?php echo 'closed' === $sig['code'] ? '#b32d2e' : '#996800'; ?>"><?php echo esc_html( $sig['text'] ); ?></small><?php endforeach; ?>
						</td>
						<td><?php echo $light; // phpcs:ignore ?></td>
						<td><?php echo $c['breaks'] ? '<strong style="color:#b32d2e">' . (int) $c['breaks'] . '</strong>' : '—'; ?></td>
						<td><?php echo $c['deprecated'] ? '<span style="color:#996800">' . (int) $c['deprecated'] . '</span>' : '—'; ?></td>
						<td><?php echo $c['hidden'] ? (int) $c['hidden'] : '—'; ?></td>
						<td><?php echo (int) $it['total']; ?><?php echo $it['skipped'] ? ' <span style="color:#996800">(' . sprintf( /* translators: %d: number of files */ esc_html__( '%d not checked', 'upgravo' ), count( $it['skipped'] ) ) . ')</span>' : ''; ?></td>
					</tr>
					<?php if ( $has_detail ) :
						$msgs    = $r['filtered']['messages'];
						$visible = array_filter( $msgs, function ( $m ) { return 'hidden' !== $m['bucket']; } );
						$hidden  = array_filter( $msgs, function ( $m ) { return 'hidden' === $m['bucket']; } );
						?>
						<tr id="phpready-det-<?php echo (int) $r['idx']; ?>" style="display:none">
							<td colspan="6" style="background:#f6f7f7;padding:12px 16px">
								<?php if ( $r['bundled'] ) : ?>
									<p class="description"><?php
									/* translators: 1: number of findings in bundled libraries, 2: total visible findings */
									echo esc_html( sprintf( __( '%1$d of %2$d are in third-party libraries bundled with the plugin (vendor, node_modules…): the author fixes those by updating the library, not their own code.', 'upgravo' ), $r['bundled'], count( $visible ) ) );
									?></p>
								<?php endif; ?>
								<?php if ( $it['skipped'] ) : ?>
									<p style="color:#996800"><?php esc_html_e( 'Not checked:', 'upgravo' ); ?> <?php echo esc_html( implode( ' · ', $it['skipped'] ) ); ?></p>
								<?php endif; ?>
								<?php if ( $it['failed'] ) : ?>
									<p style="color:#b32d2e"><?php echo esc_html( implode( ' · ', $it['failed'] ) ); ?></p>
								<?php endif; ?>

								<?php foreach ( phpready_group_messages( $visible ) as $g ) : ?>
									<details style="margin:4px 0">
										<summary style="cursor:pointer">
											<?php echo 'breaks' === $g['bucket'] ? '<strong style="color:#b32d2e">' . esc_html__( 'BREAKS', 'upgravo' ) . '</strong>' : '<span style="color:#996800">' . esc_html__( 'NOTICE', 'upgravo' ) . '</span>'; ?>
											&nbsp;×<?php echo (int) $g['count']; ?>&nbsp;
											<?php echo esc_html( $g['example'] ); ?>
											<small class="description">(<?php echo esc_html( str_replace( 'PHPCompatibility.', '', $g['source'] ) ); ?>)</small>
										</summary>
										<div style="padding:4px 0 4px 24px;font-family:monospace;font-size:12px">
											<?php echo esc_html( implode( ' · ', array_unique( $g['places'] ) ) ); ?>
										</div>
										<?php PHPReady_Fixes::render( $g['source'] ); ?>
										<?php
										/**
										 * Actions for one visible group of findings (the Pro version adds "mark as reviewed").
										 *
										 * @param array $g  Group: bucket, source, example, count, places.
										 * @param array $it The scanned item.
										 */
										do_action( 'phpready_group_actions', $g, $it );
										?>
									</details>
								<?php endforeach; ?>

								<?php if ( $hidden ) : ?>
									<details style="margin-top:8px">
										<summary style="cursor:pointer;color:#646970"><?php esc_html_e( 'Hidden', 'upgravo' ); ?> (<?php echo (int) count( $hidden ); ?>):
											<?php
											$parts = array();
											foreach ( $r['filtered']['hidden_by_reason'] as $reason => $n ) {
												$parts[] = $n . ' ' . PHPReady_Filter::reason_label( $reason );
											}
											echo esc_html( implode( ' · ', $parts ) );
											?>
										</summary>
										<?php foreach ( phpready_group_messages( $hidden ) as $g ) : ?>
											<details style="margin:2px 0 2px 16px">
												<summary style="cursor:pointer;color:#646970">
													×<?php echo (int) $g['count']; ?> <?php echo esc_html( $g['example'] ); ?>
													<small>(<?php echo esc_html( str_replace( 'PHPCompatibility.', '', $g['source'] ) ); ?>)</small>
												</summary>
												<div style="padding:4px 0 4px 24px;font-family:monospace;font-size:12px;color:#646970">
													<?php echo esc_html( implode( ' · ', array_unique( $g['places'] ) ) ); ?>
												</div>
												<?php
												/**
												 * Actions for one hidden group of findings (the Pro version adds "restore").
												 *
												 * @param array $g  Group: bucket, source, example, count, places.
												 * @param array $it The scanned item.
												 */
												do_action( 'phpready_hidden_group_actions', $g, $it );
												?>
											</details>
										<?php endforeach; ?>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
