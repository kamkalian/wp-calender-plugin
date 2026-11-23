<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Einstellungsseite (Einstellungen → Google-Kalender).
 */
class GCalMK_Settings {

	const OPTION = 'gcalmk_settings';
	const SLUG   = 'gcalmk';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_gcalmk_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_gcalmk_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'add_option_' . self::OPTION, array( 'GCalMK_Sync', 'run' ) );
		add_action( 'update_option_' . self::OPTION, array( 'GCalMK_Sync', 'run' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GCALMK_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function get() {
		$s = get_option( self::OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		$s = wp_parse_args(
			$s,
			array(
				'api_key'        => '',
				'calendar_id'    => '',
				'months_back'    => 3,
				'months_forward' => 12,
			)
		);
		if ( defined( 'GCALMK_API_KEY' ) && '' !== GCALMK_API_KEY ) {
			$s['api_key'] = (string) GCALMK_API_KEY;
		}
		return $s;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Einstellungen</a>' );
		return $links;
	}

	public static function menu() {
		add_options_page( 'Google-Kalender', 'Google-Kalender', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			'gcalmk_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
		register_setting(
			'gcalmk_group',
			GCalMK_Tags::OPT_DEFS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'GCalMK_Tags', 'sanitize_defs' ),
			)
		);
		register_setting(
			'gcalmk_group',
			GCalMK_Tags::OPT_MAP,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'GCalMK_Tags', 'sanitize_map' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$old = get_option( self::OPTION, array() );
		$old = is_array( $old ) ? $old : array();

		$key = isset( $in['api_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in['api_key'] ) : '';
		if ( ! empty( $in['clear_key'] ) ) {
			$key = '';
		} elseif ( '' === $key && isset( $old['api_key'] ) ) {
			$key = (string) $old['api_key']; // Feld leer = bisherigen Key behalten.
		}

		$cal = isset( $in['calendar_id'] ) ? trim( sanitize_text_field( $in['calendar_id'] ) ) : '';

		return array(
			'api_key'        => $key,
			'calendar_id'    => $cal,
			'months_back'    => max( 0, min( 24, (int) ( isset( $in['months_back'] ) ? $in['months_back'] : 3 ) ) ),
			'months_forward' => max( 1, min( 36, (int) ( isset( $in['months_forward'] ) ? $in['months_forward'] : 12 ) ) ),
		);
	}

	private static function notice_key() {
		return 'gcalmk_notice_' . get_current_user_id();
	}

	private static function finish( $ok, $message ) {
		set_transient(
			self::notice_key(),
			array(
				'ok'      => $ok,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	public static function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'gcalmk_sync' );
		$r = GCalMK_Sync::run();
		self::finish( $r['ok'], $r['message'] );
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'gcalmk_test' );
		$r = GCalMK_Sync::test();
		self::finish( $r['ok'], $r['message'] );
	}

	private static function fmt_time( $ts ) {
		if ( ! $ts ) {
			return '–';
		}
		return wp_date( 'd.m.Y H:i:s', $ts );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = self::get();
		$raw     = get_option( self::OPTION, array() );
		$has_key = ! empty( $raw['api_key'] );
		$by_const = defined( 'GCALMK_API_KEY' ) && '' !== GCALMK_API_KEY;
		$status  = GCalMK_Sync::get_status();
		$cache   = GCalMK_Sync::get_cache();
		$next    = wp_next_scheduled( GCalMK_Sync::HOOK );
		$notice  = get_transient( self::notice_key() );
		if ( $notice ) {
			delete_transient( self::notice_key() );
		}
		?>
		<div class="wrap">
			<h1>Google-Kalender</h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo $notice['ok'] ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'gcalmk_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gcalmk_api_key">API-Key</label></th>
						<td>
							<?php if ( $by_const ) : ?>
								<p><em>Der Key wird über die Konstante <code>GCALMK_API_KEY</code> in der wp-config.php gesetzt.</em></p>
							<?php else : ?>
								<input type="password" id="gcalmk_api_key" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_key ? esc_attr( 'gespeichert (…' . substr( $raw['api_key'], -4 ) . ') – leer lassen zum Behalten' ) : 'AIza…'; ?>">
								<?php if ( $has_key ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[clear_key]" value="1"> Key löschen</label>
								<?php endif; ?>
								<p class="description">Google Cloud Console → Calendar API aktivieren → Anmeldedaten → API-Key. Am besten auf die Calendar API beschränken.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gcalmk_cal">Kalender-ID</label></th>
						<td>
							<input type="text" id="gcalmk_cal" name="<?php echo esc_attr( self::OPTION ); ?>[calendar_id]" value="<?php echo esc_attr( $s['calendar_id'] ); ?>" class="regular-text" placeholder="xyz@group.calendar.google.com">
							<p class="description">Google Kalender → Einstellungen des Kalenders → „Kalender-ID“. Der Kalender muss öffentlich freigegeben sein (alle Termindetails).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gcalmk_back">Zeitraum</label></th>
						<td>
							<input type="number" id="gcalmk_back" min="0" max="24" name="<?php echo esc_attr( self::OPTION ); ?>[months_back]" value="<?php echo esc_attr( $s['months_back'] ); ?>" class="small-text"> Monate zurück,
							<input type="number" min="1" max="36" name="<?php echo esc_attr( self::OPTION ); ?>[months_forward]" value="<?php echo esc_attr( $s['months_forward'] ); ?>" class="small-text"> Monate voraus
						</td>
					</tr>
				</table>
				<?php
				$defs    = GCalMK_Tags::defs();
				$map     = GCalMK_Tags::map();
				$uniq    = GCalMK_Tags::unique_titles( $cache['events'] );
				$defs_by = $defs;
				uasort(
					$defs_by,
					function ( $a, $b ) {
						return strnatcasecmp( $a['name'], $b['name'] );
					}
				);
				$assigned = array(); // Tag-ID => Anzahl zugeordneter Termine (eindeutig).
				foreach ( $uniq as $k => $u ) {
					$tid = isset( $map[ $k ] ) ? (int) $map[ $k ] : 0;
					if ( isset( $defs[ $tid ] ) ) {
						$assigned[ $tid ] = isset( $assigned[ $tid ] ) ? $assigned[ $tid ] + 1 : 1;
					}
				}
				$o_defs = GCalMK_Tags::OPT_DEFS;
				$o_map  = GCalMK_Tags::OPT_MAP;
				$next_i = 0;
				?>

				<h2>Tags</h2>
				<p class="description">Lege hier Tags mit Farbe an. Die Farbe erscheint als Punkt am Tag und als Linie am Termin. Neu angelegte Tags nach dem Speichern unten den Terminen zuordnen.</p>
				<table class="widefat striped" id="gcalmk-tagdefs" style="max-width:620px">
					<thead><tr><th style="width:64px">Farbe</th><th>Tag</th><th style="width:110px">Zugeordnet</th><th style="width:70px">Löschen</th></tr></thead>
					<tbody>
					<?php foreach ( $defs_by as $d ) : ?>
						<tr>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $o_defs ); ?>[<?php echo (int) $next_i; ?>][id]" value="<?php echo (int) $d['id']; ?>">
								<input type="color" name="<?php echo esc_attr( $o_defs ); ?>[<?php echo (int) $next_i; ?>][color]" value="<?php echo esc_attr( $d['color'] ); ?>" aria-label="Farbe für <?php echo esc_attr( $d['name'] ); ?>">
							</td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $o_defs ); ?>[<?php echo (int) $next_i; ?>][name]" value="<?php echo esc_attr( $d['name'] ); ?>"></td>
							<td><?php echo (int) ( isset( $assigned[ $d['id'] ] ) ? $assigned[ $d['id'] ] : 0 ); ?> Termine</td>
							<td><input type="checkbox" name="<?php echo esc_attr( $o_defs ); ?>[<?php echo (int) $next_i; ?>][delete]" value="1" aria-label="<?php echo esc_attr( $d['name'] ); ?> löschen"></td>
						</tr>
						<?php $next_i++; ?>
					<?php endforeach; ?>
					<?php for ( $b = 0; $b < 2; $b++, $next_i++ ) : ?>
						<tr>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $o_defs ); ?>[<?php echo (int) $next_i; ?>][id]" value="0">
								<input type="color" name="<?php echo esc_attr( $o_defs ); ?>[<?php echo (int) $next_i; ?>][color]" value="<?php echo esc_attr( GCalMK_Tags::palette( count( $defs ) + $b ) ); ?>" aria-label="Farbe für neuen Tag">
							</td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $o_defs ); ?>[<?php echo (int) $next_i; ?>][name]" value="" placeholder="Neuer Tag"></td>
							<td>–</td>
							<td></td>
						</tr>
					<?php endfor; ?>
					</tbody>
				</table>
				<template id="gcalmk-tagrow">
					<tr>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $o_defs ); ?>[__I__][id]" value="0">
							<input type="color" name="<?php echo esc_attr( $o_defs ); ?>[__I__][color]" value="#a83326" aria-label="Farbe für neuen Tag">
						</td>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $o_defs ); ?>[__I__][name]" value="" placeholder="Neuer Tag"></td>
						<td>–</td>
						<td></td>
					</tr>
				</template>
				<p><button type="button" class="button" id="gcalmk-addtag" data-next="<?php echo (int) $next_i; ?>">+ Weiteren Tag hinzufügen</button></p>

				<h2>Termine zuordnen</h2>
				<?php if ( ! $uniq ) : ?>
					<p class="description">Sobald Termine abgerufen sind, erscheinen sie hier.</p>
				<?php else : ?>
					<p class="description">Alle Termine als eindeutige Liste (Wiederholungen eines Termins zählen als einer). Wähle je Eintrag den Tag. Mit der Suche filterst du die Liste; „Allen angezeigten zuweisen“ setzt den gewählten Tag für alle gerade sichtbaren Einträge.</p>
					<p>
						<input type="search" id="gcalmk-filter" class="regular-text" placeholder="Termine suchen …" aria-label="Termine suchen">
						<label style="margin-left:12px"><input type="checkbox" id="gcalmk-onlyuntagged"> nur Termine ohne Tag</label>
						<span id="gcalmk-visible" style="margin-left:12px;color:#646970"></span>
					</p>
					<p>
						<select id="gcalmk-bulk" aria-label="Tag für alle angezeigten Termine">
							<option value="">– Tag wählen –</option>
							<option value="0">(kein Tag)</option>
							<?php foreach ( $defs_by as $d ) : ?>
								<option value="<?php echo (int) $d['id']; ?>"><?php echo esc_html( $d['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button" id="gcalmk-bulkapply">Allen angezeigten Terminen zuweisen</button>
					</p>
					<div style="max-height:520px;overflow:auto;border:1px solid #c3c4c7;background:#fff;max-width:900px">
						<table class="widefat striped" id="gcalmk-events" style="border:0">
							<thead><tr><th>Termin</th><th style="width:80px">Anzahl</th><th style="width:220px">Tag</th></tr></thead>
							<tbody>
							<?php foreach ( $uniq as $k => $u ) : ?>
								<?php $cur = isset( $map[ $k ] ) ? (int) $map[ $k ] : 0; ?>
								<tr data-title="<?php echo esc_attr( mb_strtolower( $u['title'], 'UTF-8' ) ); ?>">
									<td><?php echo esc_html( $u['title'] ); ?><?php echo $u['recurring'] ? ' <span title="Wiederkehrender Termin" aria-label="Wiederkehrender Termin">↻</span>' : ''; ?></td>
									<td><?php echo (int) $u['count']; ?></td>
									<td>
										<select name="<?php echo esc_attr( $o_map ); ?>[<?php echo esc_attr( $k ); ?>]">
											<option value="0">–</option>
											<?php foreach ( $defs_by as $d ) : ?>
												<option value="<?php echo (int) $d['id']; ?>"<?php selected( $cur, (int) $d['id'] ); ?>><?php echo esc_html( $d['name'] ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<script>
				(function () {
					var add = document.getElementById('gcalmk-addtag'), tpl = document.getElementById('gcalmk-tagrow');
					if (add && tpl) {
						add.addEventListener('click', function () {
							var i = parseInt(add.getAttribute('data-next'), 10);
							add.setAttribute('data-next', String(i + 1));
							var html = tpl.innerHTML.replace(/__I__/g, String(i));
							document.querySelector('#gcalmk-tagdefs tbody').insertAdjacentHTML('beforeend', html);
						});
					}
					var filter = document.getElementById('gcalmk-filter');
					if (!filter) { return; }
					var only = document.getElementById('gcalmk-onlyuntagged'), info = document.getElementById('gcalmk-visible'),
						bulk = document.getElementById('gcalmk-bulk'), btn = document.getElementById('gcalmk-bulkapply'),
						rows = [].slice.call(document.querySelectorAll('#gcalmk-events tbody tr'));
					function apply() {
						var q = filter.value.trim().toLowerCase(), n = 0;
						rows.forEach(function (r) {
							var ok = (!q || r.getAttribute('data-title').indexOf(q) !== -1) && (!only.checked || r.querySelector('select').value === '0');
							r.style.display = ok ? '' : 'none';
							if (ok) { n++; }
						});
						info.textContent = n + ' von ' + rows.length + ' angezeigt';
					}
					filter.addEventListener('input', apply);
					filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
					only.addEventListener('change', apply);
					btn.addEventListener('click', function () {
						if (bulk.value === '') { return; }
						rows.forEach(function (r) {
							if (r.style.display !== 'none') { r.querySelector('select').value = bulk.value; }
						});
						apply();
					});
					apply();
				})();
				</script>
				<?php submit_button( 'Speichern und synchronisieren' ); ?>
			</form>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr><td>Letzte erfolgreiche Synchronisation</td><td><?php echo esc_html( self::fmt_time( $status['last_ok'] ) ); ?></td></tr>
					<tr><td>Termine im Zwischenspeicher</td><td><?php echo esc_html( count( $cache['events'] ) ); ?></td></tr>
					<tr><td>Verschiedene Termine / davon mit Tag</td><td><?php echo esc_html( count( $uniq ) . ' / ' . array_sum( $assigned ) ); ?></td></tr>
					<tr><td>Nächster automatischer Abruf</td><td><?php echo esc_html( self::fmt_time( $next ) ); ?></td></tr>
					<tr><td>Letzter Fehler</td><td><?php echo $status['last_error'] ? esc_html( $status['last_error'] . ' (' . self::fmt_time( $status['error_time'] ) . ')' ) : '–'; ?></td></tr>
				</tbody>
			</table>

			<p style="margin-top:1em">
				<?php foreach ( array( 'gcalmk_test' => 'Verbindung testen', 'gcalmk_sync' => 'Jetzt synchronisieren' ) as $action => $label ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
						<?php wp_nonce_field( $action ); ?>
						<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
					</form>
				<?php endforeach; ?>
			</p>

			<h2>Einbinden</h2>
			<p>Shortcode auf einer Seite einfügen: <code>[gcal_kalender]</code></p>
			<p>Optional (Standard: volle Seitenbreite, mit <code>breite="inhalt"</code> nur Inhaltsbreite): <code>[gcal_kalender tag="Jugend"]</code> startet mit vorgewähltem Tag, <code>suche="nein"</code> blendet die Suche aus, <code>tags="nein"</code> die Tag-Leiste.</p>

			<h2>Hinweise</h2>
			<p>Tags und ihre Zuordnung wirken sofort, ohne neuen Abruf. Der Kalender zeigt als Filter nur Tags, die mindestens einen Termin ab heute haben. Wiederkehrende Termine erkennt das Plugin an der Serien-Information von Google und kennzeichnet sie mit „↻“.</p>
			<p class="description">Der Abruf läuft über WP-Cron alle 10 Minuten (bei Besuchen der Seite). Für exakte Takte einen Server-Cron auf <code>wp-cron.php</code> einrichten und <code>DISABLE_WP_CRON</code> setzen.</p>
		</div>
		<?php
	}
}
