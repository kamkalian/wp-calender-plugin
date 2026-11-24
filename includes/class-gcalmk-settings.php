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
			GCalMK_Tags::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'GCalMK_Tags', 'sanitize' ),
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
				$tdata   = GCalMK_Tags::data();
				$uniq    = GCalMK_Tags::unique_titles( $cache['events'] );
				$tcolors = GCalMK_Tags::ensure_colors( $cache['events'] ); // Tag => Farbe (fehlende werden automatisch vergeben).
				$palette = GCalMK_Tags::palette();
				$o_tag   = GCalMK_Tags::OPT;
				$tag_of  = array();
				$tagged  = 0;
				foreach ( $uniq as $k => $u ) {
					$tag_of[ $k ] = GCalMK_Tags::effective_tag( $u['title'], $k, $tdata );
					if ( '' !== GCalMK_Tags::norm( $tag_of[ $k ] ) ) {
						$tagged++;
					}
				}
				$color_options = function ( $selected ) use ( $palette ) {
					$html = '';
					foreach ( $palette as $hex => $name ) {
						$html .= '<option value="' . esc_attr( $hex ) . '" style="color:' . esc_attr( $hex ) . '"' . selected( $selected, $hex, false ) . '>■ ' . esc_html( $name ) . '</option>';
					}
					return $html;
				};
				?>

				<h2>Termine und Tags</h2>
				<?php if ( ! $uniq ) : ?>
					<p class="description">Sobald Termine abgerufen sind, erscheinen sie hier.</p>
				<?php else : ?>
					<p class="description">Jeder Termin (Wiederholungen zählen als einer) hat automatisch seinen Titel als Tag. Schreibe in das Feld einen anderen Tag, um Termine zu gruppieren (gleiche Tags werden im Kalender zusammengefasst), oder lösche den Tag. Die Farbe wählst du im Dropdown; gleiche Tags haben dieselbe Farbe. Änderungen wirken nach dem Speichern sofort.</p>
					<p>
						<input type="search" id="gcalmk-filter" class="regular-text" placeholder="Termine suchen …" aria-label="Termine suchen">
						<label style="margin-left:12px"><input type="checkbox" id="gcalmk-onlyuntagged"> nur Termine ohne Tag</label>
						<span id="gcalmk-visible" style="margin-left:12px;color:#646970"></span>
					</p>
					<p>
						<strong>Für alle angezeigten Termine:</strong>
						<input type="text" id="gcalmk-bulktag" class="regular-text" placeholder="Tag eintragen" aria-label="Tag für alle angezeigten Termine">
						<select id="gcalmk-bulkcolor" aria-label="Farbe für alle angezeigten Termine"><?php echo $color_options( '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
						<button type="button" class="button" id="gcalmk-bulkapply">Setzen</button>
						<button type="button" class="button" id="gcalmk-bulkdelete">Tags löschen</button>
						<span style="margin-left:12px">Ausdruck:</span>
						<button type="button" class="button" id="gcalmk-bulknoprint">nicht drucken</button>
						<button type="button" class="button" id="gcalmk-bulkprint">wieder drucken</button>
					</p>
					<div style="max-height:560px;overflow:auto;border:1px solid #c3c4c7;background:#fff;max-width:1000px">
						<table class="widefat striped" id="gcalmk-events" style="border:0">
							<thead><tr><th>Termin</th><th style="width:70px">Anzahl</th><th style="width:480px">Tag</th><th style="width:110px">Nicht drucken</th></tr></thead>
							<tbody>
							<?php foreach ( $uniq as $k => $u ) : ?>
								<?php
								$tg  = $tag_of[ $k ];
								$tn  = GCalMK_Tags::norm( $tg );
								$hex = ( '' !== $tn && isset( $tcolors[ $tn ] ) ) ? $tcolors[ $tn ] : '#c62828';
								?>
								<tr data-title="<?php echo esc_attr( mb_strtolower( $u['title'], 'UTF-8' ) ); ?>">
									<td><?php echo esc_html( $u['title'] ); ?></td>
									<td><?php echo (int) $u['count']; ?></td>
									<td style="white-space:nowrap">
										<input type="text" class="gcalmk-tag" style="width:230px" name="<?php echo esc_attr( $o_tag ); ?>[<?php echo esc_attr( $k ); ?>][t]" value="<?php echo esc_attr( $tg ); ?>" aria-label="Tag für <?php echo esc_attr( $u['title'] ); ?>">
										<button type="button" class="button gcalmk-del" aria-label="Tag löschen">Löschen</button>
										<select class="gcalmk-col" style="color:<?php echo esc_attr( $hex ); ?>" name="<?php echo esc_attr( $o_tag ); ?>[<?php echo esc_attr( $k ); ?>][c]" aria-label="Farbe"><?php echo $color_options( $hex ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
									</td>
								<td style="text-align:center">
										<input type="checkbox" class="gcalmk-np" name="<?php echo esc_attr( $o_tag ); ?>[<?php echo esc_attr( $k ); ?>][p]" value="1"<?php checked( ! empty( $tdata['noprint'][ $k ] ) ); ?> aria-label="<?php echo esc_attr( $u['title'] ); ?> nicht drucken">
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<script>
				(function () {
					var table = document.getElementById('gcalmk-events');
					if (!table) { return; }
					var rows = [].slice.call(table.querySelectorAll('tbody tr')),
						filter = document.getElementById('gcalmk-filter'), only = document.getElementById('gcalmk-onlyuntagged'),
						info = document.getElementById('gcalmk-visible');
					function nk(s) { return s.toLowerCase().replace(/\s+/g, ' ').trim(); }
					function inp(r) { return r.querySelector('.gcalmk-tag'); }
					function sel(r) { return r.querySelector('.gcalmk-col'); }
					function paint(s) { s.style.color = s.value; }
					function visible(r) { return r.style.display !== 'none'; }
					function apply() {
						var q = filter.value.trim().toLowerCase(), n = 0;
						rows.forEach(function (r) {
							var ok = (!q || r.getAttribute('data-title').indexOf(q) !== -1) && (!only.checked || nk(inp(r).value) === '');
							r.style.display = ok ? '' : 'none';
							if (ok) { n++; }
						});
						info.textContent = n + ' von ' + rows.length + ' angezeigt';
					}
					// Gleiche Tags bekommen immer dieselbe Farbe.
					function syncColor(from) {
						var k = nk(inp(from).value);
						if (!k) { return; }
						rows.forEach(function (o) {
							if (o !== from && nk(inp(o).value) === k) { sel(o).value = sel(from).value; paint(sel(o)); }
						});
					}
					rows.forEach(function (r) {
						paint(sel(r));
						sel(r).addEventListener('change', function () { paint(sel(r)); syncColor(r); });
						inp(r).addEventListener('change', function () {
							var k = nk(inp(r).value);
							if (k) {
								for (var i = 0; i < rows.length; i++) {
									if (rows[i] !== r && nk(inp(rows[i]).value) === k) { sel(r).value = sel(rows[i]).value; paint(sel(r)); break; }
								}
							}
							apply();
						});
						r.querySelector('.gcalmk-del').addEventListener('click', function () { inp(r).value = ''; apply(); });
					});
					filter.addEventListener('input', apply);
					filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
					only.addEventListener('change', apply);

					var bt = document.getElementById('gcalmk-bulktag'), bc = document.getElementById('gcalmk-bulkcolor');
					bt.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
					document.getElementById('gcalmk-bulkapply').addEventListener('click', function () {
						if (!bt.value.trim()) { return; }
						var first = null;
						rows.forEach(function (r) {
							if (!visible(r)) { return; }
							inp(r).value = bt.value.trim();
							sel(r).value = bc.value; paint(sel(r));
							first = first || r;
						});
						if (first) { syncColor(first); }
						apply();
					});
					document.getElementById('gcalmk-bulkdelete').addEventListener('click', function () {
						rows.forEach(function (r) { if (visible(r)) { inp(r).value = ''; } });
						apply();
					});
					function setNoPrint(v) {
						rows.forEach(function (r) { if (visible(r)) { r.querySelector('.gcalmk-np').checked = v; } });
					}
					document.getElementById('gcalmk-bulknoprint').addEventListener('click', function () { setNoPrint(true); });
					document.getElementById('gcalmk-bulkprint').addEventListener('click', function () { setNoPrint(false); });
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
					<tr><td>Verschiedene Termine / davon mit Tag</td><td><?php echo esc_html( count( $uniq ) . ' / ' . $tagged ); ?></td></tr>
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
			<p>Tags und ihre Zuordnung wirken sofort, ohne neuen Abruf. Der Kalender zeigt als Filter nur Tags, die mindestens einen Termin ab heute haben. Ein Tag, dessen Termine wiederkehrend sind (Serien-Information von Google), trägt im Kalender das Symbol „↻“.</p>
			<p class="description">Der Abruf läuft über WP-Cron alle 10 Minuten (bei Besuchen der Seite). Für exakte Takte einen Server-Cron auf <code>wp-cron.php</code> einrichten und <code>DISABLE_WP_CRON</code> setzen.</p>
		</div>
		<?php
	}
}
