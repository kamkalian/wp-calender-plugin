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
				<?php submit_button( 'Speichern und synchronisieren' ); ?>
			</form>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr><td>Letzte erfolgreiche Synchronisation</td><td><?php echo esc_html( self::fmt_time( $status['last_ok'] ) ); ?></td></tr>
					<tr><td>Termine im Zwischenspeicher</td><td><?php echo esc_html( count( $cache['events'] ) ); ?></td></tr>
					<tr><td>Gefundene Tags</td><td><?php echo esc_html( $cache['tags'] ? implode( ', ', $cache['tags'] ) : '–' ); ?></td></tr>
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

			<h2>Tags</h2>
			<p>Als Tag dient automatisch das <strong>erste Wort des Termintitels</strong> (z. B. „Jugendtraining Halle 2“ → <code>Jugendtraining</code>). Alle Tags erscheinen als Filter-Schaltflächen über dem Kalender.</p>
			<p class="description">Der Abruf läuft über WP-Cron alle 10 Minuten (bei Besuchen der Seite). Für exakte Takte einen Server-Cron auf <code>wp-cron.php</code> einrichten und <code>DISABLE_WP_CRON</code> setzen.</p>
		</div>
		<?php
	}
}
