<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holt Termine von der Google Calendar API und legt sie im Cache ab.
 */
class GCalMK_Sync {

	const HOOK     = 'gcalmk_sync_event';
	const SCHEDULE = 'gcalmk_10min';
	const CACHE_V  = 9; // Erhöhen, wenn sich das Format der Termindaten ändert: löst automatisch eine neue Synchronisation aus.

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 600,
			'display'  => 'Alle 10 Minuten',
		);
		return $schedules;
	}

	public static function activate() {
		self::ensure_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		}
		// Nach einem Plugin-Update mit neuem Datenformat einmal sofort neu abrufen.
		$cache = get_option( 'gcalmk_cache', array() );
		if ( is_array( $cache ) && ! empty( $cache['events'] ) && ( isset( $cache['v'] ) ? (int) $cache['v'] : 0 ) < self::CACHE_V
			&& ! wp_next_scheduled( self::HOOK, array( 'migrate' ) ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK, array( 'migrate' ) );
		}
	}

	public static function get_cache() {
		$cache = get_option( 'gcalmk_cache', array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		return wp_parse_args(
			$cache,
			array(
				'synced' => 0,
				'events' => array(),
				'tags'   => array(),
			)
		);
	}

	public static function get_status() {
		$status = get_option( 'gcalmk_status', array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		return wp_parse_args(
			$status,
			array(
				'last_ok'    => 0,
				'last_error' => '',
				'error_time' => 0,
			)
		);
	}

	private static function fail( $message ) {
		$status               = self::get_status();
		$status['last_error'] = $message;
		$status['error_time'] = time();
		update_option( 'gcalmk_status', $status, false );
		return array(
			'ok'      => false,
			'message' => $message,
			'count'   => 0,
		);
	}

	/**
	 * Eine Anfrage an die Calendar API. Gibt Array (decodierte Antwort) oder string (Fehlertext) zurück.
	 */
	private static function request( $key, $calendar, $params ) {
		$params['key'] = $key;
		$url           = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar ) . '/events?'
			. http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return 'Verbindung zu Google fehlgeschlagen: ' . $res->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			$msg = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
			switch ( $code ) {
				case 400:
					$hint = 'Anfrage ungültig oder API-Key ungültig.';
					break;
				case 403:
					$hint = 'Zugriff verweigert: Calendar API im Google-Projekt aktivieren, Key-Einschränkungen prüfen oder Kontingent erreicht.';
					break;
				case 404:
					$hint = 'Kalender nicht gefunden oder nicht öffentlich freigegeben.';
					break;
				default:
					$hint = 'Unerwartete Antwort.';
			}
			return sprintf( 'Google antwortet mit HTTP %d. %s%s', $code, $hint, '' !== $msg ? ' (' . $msg . ')' : '' );
		}
		if ( ! is_array( $body ) ) {
			return 'Antwort von Google konnte nicht gelesen werden.';
		}
		return $body;
	}

	/**
	 * Nur Verbindung prüfen (ein Termin).
	 */
	public static function test() {
		$s = GCalMK_Settings::get();
		if ( '' === $s['api_key'] || '' === $s['calendar_id'] ) {
			return array(
				'ok'      => false,
				'message' => 'API-Key und Kalender-ID müssen eingetragen sein.',
			);
		}
		$res = self::request(
			$s['api_key'],
			$s['calendar_id'],
			array(
				'maxResults'   => 1,
				'singleEvents' => 'true',
			)
		);
		if ( is_string( $res ) ) {
			return array(
				'ok'      => false,
				'message' => $res,
			);
		}
		$name = isset( $res['summary'] ) ? (string) $res['summary'] : '';
		return array(
			'ok'      => true,
			'message' => 'Verbindung erfolgreich' . ( '' !== $name ? ': Kalender „' . $name . '“.' : '.' ),
		);
	}

	/**
	 * Vollständiger Abruf, schreibt den Cache. Bei Fehlern bleibt der alte Cache erhalten.
	 */
	public static function run() {
		if ( get_transient( 'gcalmk_lock' ) ) {
			return array(
				'ok'      => false,
				'message' => 'Eine Synchronisation läuft bereits.',
				'count'   => 0,
			);
		}
		set_transient( 'gcalmk_lock', 1, 120 );

		$result = self::do_run();

		delete_transient( 'gcalmk_lock' );
		return $result;
	}

	private static function do_run() {
		$s = GCalMK_Settings::get();
		if ( '' === $s['api_key'] || '' === $s['calendar_id'] ) {
			return self::fail( 'API-Key oder Kalender-ID fehlt.' );
		}

		$tz       = wp_timezone();
		$min      = ( new DateTimeImmutable( 'first day of this month 00:00:00', $tz ) )->modify( '-' . (int) $s['months_back'] . ' months' );
		$max      = ( new DateTimeImmutable( 'first day of this month 00:00:00', $tz ) )->modify( '+' . ( (int) $s['months_forward'] + 1 ) . ' months' );
		$time_min = $min->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		$time_max = $max->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );

		$items = array();
		$token = '';
		for ( $page = 0; $page < 10; $page++ ) {
			$params = array(
				'timeMin'      => $time_min,
				'timeMax'      => $time_max,
				'singleEvents' => 'true',
				'orderBy'      => 'startTime',
				'maxResults'   => 2500,
				'fields'       => 'nextPageToken,items(id,status,summary,description,location,htmlLink,start,end,recurringEventId)',
			);
			if ( '' !== $token ) {
				$params['pageToken'] = $token;
			}
			$res = self::request( $s['api_key'], $s['calendar_id'], $params );
			if ( is_string( $res ) ) {
				return self::fail( $res );
			}
			if ( ! empty( $res['items'] ) && is_array( $res['items'] ) ) {
				$items = array_merge( $items, $res['items'] );
			}
			$token = isset( $res['nextPageToken'] ) ? (string) $res['nextPageToken'] : '';
			if ( '' === $token ) {
				break;
			}
		}

		$events = array();
		foreach ( $items as $item ) {
			$ev = self::normalize( $item, $tz );
			if ( $ev ) {
				$events[] = $ev;
			}
		}

		usort(
			$events,
			function ( $a, $b ) {
				return strcmp( $a['startDate'] . ' ' . $a['startTime'], $b['startDate'] . ' ' . $b['startTime'] );
			}
		);

		// Farben und Druckbereiche der Tags werden erst bei der Auslieferung angewendet (siehe GCalMK_Tags), damit Änderungen sofort wirken.
		update_option(
			'gcalmk_cache',
			array(
				'v'      => self::CACHE_V,
				'synced' => time(),
				'events' => $events,
			),
			false
		);

		$status               = self::get_status();
		$status['last_ok']    = time();
		$status['last_error'] = '';
		update_option( 'gcalmk_status', $status, false );

		return array(
			'ok'      => true,
			'message' => count( $events ) . ' Termine übernommen.',
			'count'   => count( $events ),
		);
	}

	/**
	 * Macht aus einem Google-Termin einen schlanken Datensatz. Zeiten in der WordPress-Zeitzone.
	 */
	private static function normalize( $item, $tz ) {
		if ( ! is_array( $item ) || ! isset( $item['start'], $item['end'] ) ) {
			return null;
		}
		if ( isset( $item['status'] ) && 'cancelled' === $item['status'] ) {
			return null;
		}

		try {
			if ( ! empty( $item['start']['date'] ) ) {
				// Ganztägig: Ende ist exklusiv.
				$start      = new DateTimeImmutable( $item['start']['date'], $tz );
				$end        = new DateTimeImmutable( ! empty( $item['end']['date'] ) ? $item['end']['date'] : $item['start']['date'], $tz );
				$last       = $end->modify( '-1 day' );
				$all_day    = true;
				$start_time = '';
				$end_time   = '';
			} elseif ( ! empty( $item['start']['dateTime'] ) ) {
				$start      = ( new DateTimeImmutable( $item['start']['dateTime'] ) )->setTimezone( $tz );
				$end        = ( new DateTimeImmutable( ! empty( $item['end']['dateTime'] ) ? $item['end']['dateTime'] : $item['start']['dateTime'] ) )->setTimezone( $tz );
				$last       = $end > $start ? $end->modify( '-1 second' ) : $start;
				$all_day    = false;
				$start_time = $start->format( 'H:i' );
				$end_time   = $end->format( 'H:i' );
			} else {
				return null;
			}
		} catch ( Exception $e ) {
			return null;
		}

		$start_date = $start->format( 'Y-m-d' );
		$end_date   = $last->format( 'Y-m-d' );
		if ( $end_date < $start_date ) {
			$end_date = $start_date;
		}

		$title = isset( $item['summary'] ) ? (string) $item['summary'] : '';
		$desc  = isset( $item['description'] ) ? (string) $item['description'] : '';


		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		if ( '' === $title ) {
			$title = '(ohne Titel)';
		}

		$allowed = array(
			'a'      => array(
				'href' => true,
			),
			'br'     => array(),
			'b'      => array(),
			'strong' => array(),
			'i'      => array(),
			'em'     => array(),
			'u'      => array(),
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
		$desc    = wp_kses( $desc, $allowed, array( 'http', 'https', 'mailto', 'tel' ) );
		$desc    = trim( $desc );

		return array(
			'id'          => isset( $item['id'] ) ? (string) $item['id'] : md5( $title . $start_date ),
			'title'       => $title,
			'startDate'   => $start_date,
			'endDate'     => $end_date,
			'startTime'   => $start_time,
			'endTime'     => $end_time,
			'allDay'      => $all_day,
			'location'    => isset( $item['location'] ) ? sanitize_text_field( $item['location'] ) : '',
			'description' => $desc,
			'link'        => isset( $item['htmlLink'] ) ? esc_url_raw( $item['htmlLink'] ) : '',
			// Google liefert bei aufgelösten Serien die ID der Serie mit: so erkennen wir wiederkehrende Termine.
			'recurring'   => ! empty( $item['recurringEventId'] ),
		);
	}
}
