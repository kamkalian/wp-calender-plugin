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
		add_action( 'admin_post_gcalmk_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_gcalmk_import', array( __CLASS__, 'handle_import' ) );
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

	/** Bereiche und Tags als JSON-Datei herunterladen. */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'gcalmk_export' );
		$json = wp_json_encode( GCalMK_Tags::export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gcal-kalender-tags-' . wp_date( 'Y-m-d' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON-Download, kein HTML.
		exit;
	}

	/** Zuvor exportierte Datei einspielen (ersetzt Bereiche und Tags). */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'gcalmk_import' );
		if ( empty( $_FILES['gcalmk_import']['tmp_name'] ) || ! is_uploaded_file( $_FILES['gcalmk_import']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['gcalmk_import']['error'] ) {
			self::finish( false, 'Es wurde keine Datei hochgeladen.' );
		}
		if ( (int) $_FILES['gcalmk_import']['size'] > 262144 ) {
			self::finish( false, 'Die Datei ist zu groß (höchstens 256 KB).' );
		}
		$raw  = file_get_contents( $_FILES['gcalmk_import']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			self::finish( false, 'Die Datei konnte nicht gelesen werden (kein gültiges JSON).' );
		}
		$r = GCalMK_Tags::import_data( $data );
		self::finish( $r[0], $r[1] );
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
		$s        = self::get();
		$raw      = get_option( self::OPTION, array() );
		$has_key  = ! empty( $raw['api_key'] );
		$by_const = defined( 'GCALMK_API_KEY' ) && '' !== GCALMK_API_KEY;
		$status   = GCalMK_Sync::get_status();
		$cache    = GCalMK_Sync::get_cache();
		$next     = wp_next_scheduled( GCalMK_Sync::HOOK );
		$notice   = get_transient( self::notice_key() );
		if ( $notice ) {
			delete_transient( self::notice_key() );
		}

		// Bereiche und Tags (manuell eingerichtet); die Anzahl zeigt, wie viele Termine den Tag im Titel haben.
		$areas   = GCalMK_Tags::areas_config();
		$cfg     = GCalMK_Tags::config();
		$counts  = GCalMK_Tags::counts( $cache['events'], $cfg );
		$palette = GCalMK_Tags::palette();
		$o_tag   = GCalMK_Tags::OPT;
		$used    = array(); // Bereichsnummer => Anzahl Tags
		foreach ( $cfg as $c ) {
			$a = isset( $c['area'] ) ? (int) $c['area'] : 0;
			if ( $a > 0 ) {
				$used[ $a ] = isset( $used[ $a ] ) ? $used[ $a ] + 1 : 1;
			}
		}
		$color_options = function ( $selected ) use ( $palette ) {
			$html = '';
			foreach ( $palette as $hex => $name ) {
				$html .= '<option value="' . esc_attr( $hex ) . '" style="color:' . esc_attr( $hex ) . '"' . selected( $selected, $hex, false ) . '>■ ' . esc_html( $name ) . '</option>';
			}
			return $html;
		};
		$area_options  = function ( $selected ) use ( $areas ) {
			$html = '<option value="">– kein Bereich –</option>';
			foreach ( $areas as $id => $nm ) {
				$html .= '<option value="' . (int) $id . '"' . selected( (int) $selected, (int) $id, false ) . '>' . esc_html( $nm ) . '</option>';
			}
			return $html;
		};
		$with_tag = 0;
		list( $tagged ) = GCalMK_Tags::apply( $cache['events'] );
		foreach ( $tagged as $ev ) {
			if ( ! empty( $ev['tags'] ) ) {
				$with_tag++;
			}
		}
		// Termine ab heute ohne Tag: Titel eindeutig, mit Anzahl.
		$untagged = array();
		$today    = wp_date( 'Y-m-d' );
		foreach ( $tagged as $ev ) {
			if ( empty( $ev['tags'] ) && $ev['endDate'] >= $today ) {
				$t              = trim( (string) $ev['title'] );
				$untagged[ $t ] = isset( $untagged[ $t ] ) ? $untagged[ $t ] + 1 : 1;
			}
		}
		ksort( $untagged, SORT_NATURAL | SORT_FLAG_CASE );
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

				<h2>Bereiche im Ausdruck</h2>
				<p class="description" style="max-width:900px">
					Die Bereiche sind die Überschriften des Ausdrucks, in der Reihenfolge dieser Tabelle. Mehrere Tags können demselben Bereich zugeordnet werden. Einen Bereich umbenennen ändert nichts an den Zuordnungen. <em>Neue Bereiche erst speichern, danach stehen sie in der Auswahl der Tags.</em>
				</p>
				<table class="widefat striped" id="gcalmk-areatable" style="max-width:620px">
					<thead><tr><th>Bereich (Überschrift)</th><th style="width:90px">Tags</th><th style="width:80px">Entfernen</th></tr></thead>
					<tbody>
					<?php $ai = 0; foreach ( $areas as $id => $nm ) : ?>
						<tr>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $o_tag ); ?>[arearows][<?php echo (int) $ai; ?>][id]" value="<?php echo (int) $id; ?>">
								<input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[arearows][<?php echo (int) $ai; ?>][name]" value="<?php echo esc_attr( $nm ); ?>" aria-label="Name des Bereichs">
							</td>
							<td><?php echo (int) ( isset( $used[ $id ] ) ? $used[ $id ] : 0 ); ?></td>
							<td style="text-align:center"><input type="checkbox" name="<?php echo esc_attr( $o_tag ); ?>[arearows][<?php echo (int) $ai; ?>][del]" value="1" aria-label="<?php echo esc_attr( $nm ); ?> entfernen"></td>
						</tr>
						<?php $ai++; ?>
					<?php endforeach; ?>
					<?php for ( $b = 0; $b < 2; $b++, $ai++ ) : ?>
						<tr>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $o_tag ); ?>[arearows][<?php echo (int) $ai; ?>][id]" value="0">
								<input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[arearows][<?php echo (int) $ai; ?>][name]" value="" placeholder="Neuer Bereich" aria-label="Name des neuen Bereichs">
							</td>
							<td>–</td>
							<td></td>
						</tr>
					<?php endfor; ?>
					</tbody>
				</table>
				<template id="gcalmk-arearow">
					<tr>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $o_tag ); ?>[arearows][__I__][id]" value="0">
							<input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[arearows][__I__][name]" value="" placeholder="Neuer Bereich" aria-label="Name des neuen Bereichs">
						</td>
						<td>–</td>
						<td></td>
					</tr>
				</template>
				<p><button type="button" class="button" id="gcalmk-addarea" data-next="<?php echo (int) $ai; ?>">+ Weiteren Bereich hinzufügen</button></p>

				<h2>Tags</h2>
				<p class="description" style="max-width:900px">
					Ein Termin bekommt einen Tag, wenn der Tag-Name oder eines der <strong>weiteren Stichwörter</strong> (mit Komma trennen) <strong>im Titel</strong> vorkommt (Groß-/Kleinschreibung egal, nur als <strong>ganzes Wort</strong>: „Cafe“ passt zu „Cafe am Markt“ und „AWO-Cafe“, nicht zu „SonntagsCafe“). Ein Termin kann mehrere Tags haben.
					Der Tag, der im Titel <strong>zuerst</strong> vorkommt und einen Bereich hat, entscheidet über den Bereich im Ausdruck – ein Termin steht nur in einem Bereich.
					<strong>Ohne Bereich wird der Termin nicht gedruckt</strong>, der Tag filtert dann nur im Kalender.
				</p>
				<table class="widefat striped" id="gcalmk-tagtable" style="max-width:920px">
					<thead><tr><th style="width:150px">Farbe</th><th>Tag-Name</th><th>Weitere Stichwörter im Titel</th><th style="width:300px">Bereich im Ausdruck</th><th style="width:80px">Termine</th><th style="width:80px">Entfernen</th></tr></thead>
					<tbody>
					<?php $i = 0; foreach ( $cfg as $n => $r ) : ?>
						<tr>
							<td><select class="gcalmk-col" style="color:<?php echo esc_attr( $r['color'] ); ?>" name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][color]" aria-label="Farbe für <?php echo esc_attr( $r['name'] ); ?>"><?php echo $color_options( $r['color'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
							<td><input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $r['name'] ); ?>"></td>
							<td><input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][words]" value="<?php echo esc_attr( ! empty( $r['words'] ) ? implode( ', ', $r['words'] ) : '' ); ?>" placeholder="z. B. Bingo, Kneipenquiz" aria-label="Weitere Stichwörter für <?php echo esc_attr( $r['name'] ); ?>"></td>
							<td><select name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][area]" aria-label="Bereich für <?php echo esc_attr( $r['name'] ); ?>"><?php echo $area_options( isset( $r['area'] ) ? $r['area'] : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
							<td><?php echo (int) ( isset( $counts[ $n ] ) ? $counts[ $n ] : 0 ); ?></td>
							<td style="text-align:center"><input type="checkbox" name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][del]" value="1" aria-label="<?php echo esc_attr( $r['name'] ); ?> entfernen"></td>
						</tr>
						<?php $i++; ?>
					<?php endforeach; ?>
					<?php for ( $b = 0; $b < 2; $b++, $i++ ) : ?>
						<tr>
							<td><select class="gcalmk-col" style="color:<?php echo esc_attr( array_keys( $palette )[0] ); ?>" name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][color]" aria-label="Farbe für neuen Tag"><?php echo $color_options( '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
							<td><input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][name]" value="" placeholder="Neuer Tag"></td>
							<td><input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][words]" value="" placeholder="Stichwörter, Komma getrennt" aria-label="Weitere Stichwörter für neuen Tag"></td>
							<td><select name="<?php echo esc_attr( $o_tag ); ?>[rows][<?php echo (int) $i; ?>][area]" aria-label="Bereich für neuen Tag"><?php echo $area_options( 0 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
							<td>–</td>
							<td></td>
						</tr>
					<?php endfor; ?>
					</tbody>
				</table>
				<template id="gcalmk-tagrow">
					<tr>
						<td><select class="gcalmk-col" name="<?php echo esc_attr( $o_tag ); ?>[rows][__I__][color]" aria-label="Farbe für neuen Tag"><?php echo $color_options( '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
						<td><input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[rows][__I__][name]" value="" placeholder="Neuer Tag"></td>
						<td><input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $o_tag ); ?>[rows][__I__][words]" value="" placeholder="Stichwörter, Komma getrennt" aria-label="Weitere Stichwörter für neuen Tag"></td>
						<td><select name="<?php echo esc_attr( $o_tag ); ?>[rows][__I__][area]" aria-label="Bereich für neuen Tag"><?php echo $area_options( 0 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
						<td>–</td>
						<td></td>
					</tr>
				</template>
				<p><button type="button" class="button" id="gcalmk-addtag" data-next="<?php echo (int) $i; ?>">+ Weiteren Tag hinzufügen</button></p>
				<script>
				(function () {
					function paint(s) { s.style.color = s.value; }
					function bind(root) {
						[].forEach.call(root.querySelectorAll('select.gcalmk-col'), function (s) {
							paint(s);
							s.addEventListener('change', function () { paint(s); });
						});
					}
					function adder(buttonId, tplId, tableId) {
						var btn = document.getElementById(buttonId), tpl = document.getElementById(tplId), table = document.getElementById(tableId);
						btn.addEventListener('click', function () {
							var i = parseInt(btn.getAttribute('data-next'), 10);
							btn.setAttribute('data-next', String(i + 1));
							table.querySelector('tbody').insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__I__/g, String(i)));
							bind(table.querySelector('tbody tr:last-child'));
						});
					}
					bind(document.getElementById('gcalmk-tagtable'));
					adder('gcalmk-addtag', 'gcalmk-tagrow', 'gcalmk-tagtable');
					adder('gcalmk-addarea', 'gcalmk-arearow', 'gcalmk-areatable');
				})();
				</script>
				<?php submit_button( 'Speichern und synchronisieren' ); ?>
			</form>

			<h2>Termine ohne Tag (<?php echo (int) count( $untagged ); ?>)</h2>
			<p class="description" style="max-width:900px">Termine ab heute, in deren Titel kein Tag vorkommt. Sie werden nicht gedruckt.</p>
			<?php if ( $untagged ) : ?>
				<ul style="list-style:disc;margin-left:20px">
					<?php foreach ( $untagged as $t => $n ) : ?>
						<li><?php echo esc_html( $t ); ?><?php echo $n > 1 ? ' <span style="color:#646970">(' . (int) $n . '×)</span>' : ''; ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>Alle kommenden Termine haben einen Tag.</p>
			<?php endif; ?>

			<h2>Bereiche und Tags sichern</h2>
			<p class="description" style="max-width:900px">Lädt alle Bereiche und Tags (Name, Farbe, Bereich) als Datei herunter, damit du sie sichern oder auf eine andere Seite übertragen kannst. Mit „Einspielen“ stellst du sie später wieder her.</p>
			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="gcalmk_export">
					<?php wp_nonce_field( 'gcalmk_export' ); ?>
					<button type="submit" class="button">Bereiche und Tags herunterladen</button>
				</form>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" onsubmit="return confirm('Alle bisherigen Bereiche und Tags werden durch den Inhalt der Datei ersetzt. Fortfahren?');">
				<input type="hidden" name="action" value="gcalmk_import">
				<?php wp_nonce_field( 'gcalmk_import' ); ?>
				<input type="file" name="gcalmk_import" accept=".json,application/json" required>
				<button type="submit" class="button">Einspielen (ersetzt vorhandene)</button>
			</form>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr><td>Letzte erfolgreiche Synchronisation</td><td><?php echo esc_html( self::fmt_time( $status['last_ok'] ) ); ?></td></tr>
					<tr><td>Termine im Zwischenspeicher</td><td><?php echo esc_html( count( $cache['events'] ) ); ?></td></tr>
					<tr><td>Termine mit Tag / eingerichtete Tags</td><td><?php echo esc_html( $with_tag . ' / ' . count( $cfg ) ); ?></td></tr>
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
			<p>Optional (Standard: volle Seitenbreite, mit <code>breite="inhalt"</code> nur Inhaltsbreite): <code>[gcal_kalender tag="Jugend"]</code> startet mit vorgewähltem Tag, <code>suche="nein"</code> blendet die Suche aus, <code>tags="nein"</code> die Tag-Leiste, <code>oben="70"</code> setzt den Abstand des fixierten Kopfbereichs (Pixel).</p>

			<h2>Hinweise</h2>
			<p>Der Kalender zeigt als Filter nur Tags, die mindestens einen Termin ab heute haben. Ein Tag, dessen Termine wiederkehrend sind (Serien-Information von Google), trägt im Kalender das Symbol „↻“. Änderungen an Tags, Farben und Bereichen wirken sofort.</p>
			<p class="description">Der Abruf läuft über WP-Cron alle 10 Minuten (bei Besuchen der Seite). Für exakte Takte einen Server-Cron auf <code>wp-cron.php</code> einrichten und <code>DISABLE_WP_CRON</code> setzen.</p>
		</div>
		<?php
	}
}
