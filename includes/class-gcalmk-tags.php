<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags: Jeder Termin (eindeutig nach Titel) hat automatisch einen Tag aus seinem Titel.
 * In den Einstellungen lässt sich der Tag je Eintrag frei umschreiben oder löschen, die Farbe kommt aus einer festen Auswahl.
 *
 * Option gcalmk_tagdata:
 *   titles => array( md5-Schlüssel des Titels => Tag-Text, '' = bewusst kein Tag )   (nur Abweichungen vom Standard)
 *   colors => array( Tag klein geschrieben => #rrggbb )
 */
class GCalMK_Tags {

	const OPT = 'gcalmk_tagdata';

	/** Auswahl der Farben für das Dropdown (Name => Hex). */
	public static function palette() {
		return array(
			'#c62828' => 'Rot',
			'#7b1e3a' => 'Bordeaux',
			'#e65100' => 'Orange',
			'#b8860b' => 'Senf',
			'#6d4c41' => 'Braun',
			'#6b7d1f' => 'Oliv',
			'#43a047' => 'Hellgrün',
			'#2e7d32' => 'Grün',
			'#1b5e20' => 'Dunkelgrün',
			'#00897b' => 'Türkis',
			'#006978' => 'Petrol',
			'#1e88e5' => 'Hellblau',
			'#1565c0' => 'Blau',
			'#0d3b8c' => 'Dunkelblau',
			'#6a1b9a' => 'Violett',
			'#9c27b0' => 'Lila',
			'#c2185b' => 'Magenta',
			'#ec407a' => 'Rosa',
			'#6b7280' => 'Grau',
			'#222222' => 'Schwarz',
		);
	}

	/** Reihenfolge, in der neue Tags automatisch Farben bekommen (wechselt die Farbfamilie von Tag zu Tag). */
	private static function auto_order() {
		return array( '#c62828', '#1e88e5', '#2e7d32', '#e65100', '#6a1b9a', '#00897b', '#b8860b', '#c2185b', '#1565c0', '#6b7d1f', '#7b1e3a', '#ec407a', '#1b5e20', '#9c27b0', '#6d4c41', '#006978', '#43a047', '#0d3b8c', '#6b7280', '#222222' );
	}

	public static function data() {
		$d = get_option( self::OPT, array() );
		if ( ! is_array( $d ) ) {
			$d = array();
		}
		$d['titles'] = isset( $d['titles'] ) && is_array( $d['titles'] ) ? $d['titles'] : array();
		$d['colors'] = isset( $d['colors'] ) && is_array( $d['colors'] ) ? $d['colors'] : array();
		return $d;
	}

	/** Titel ohne führende Ordnungszahl ("10. "), Emojis/Symbole und doppelte Leerzeichen. */
	private static function stripped( $title ) {
		$t = preg_replace( '/^\s*\d+\s*\.\s+/u', '', (string) $title );
		$t = preg_replace( '/[\p{So}\p{Sk}\x{FE0F}\x{200D}]+/u', '', $t );
		return trim( preg_replace( '/\s+/u', ' ', $t ) );
	}

	/** Schlüssel eines Termins: gleich, wenn sich Titel nur durch Zahl am Anfang, Emojis, Satzzeichen oder Groß-/Kleinschreibung unterscheiden. */
	public static function title_key( $title ) {
		$t = mb_strtolower( self::stripped( $title ), 'UTF-8' );
		$t = trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $t ) );
		return md5( $t );
	}

	/** Standard-Tag: der bereinigte Titel. */
	public static function default_tag( $title ) {
		static $cache = array();
		if ( isset( $cache[ $title ] ) ) {
			return $cache[ $title ];
		}
		$t = trim( self::stripped( $title ), " \t-–—:,;" );
		if ( '' === $t ) {
			$t = '(ohne Titel)';
		}
		$t = mb_strtoupper( mb_substr( $t, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $t, 1, null, 'UTF-8' );
		$cache[ $title ] = $t;
		return $t;
	}

	public static function norm( $tag ) {
		return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $tag ) ), 'UTF-8' );
	}

	/** Tag eines Eintrags: gespeicherte Abweichung oder Standard. */
	public static function effective_tag( $title, $key, $data ) {
		if ( isset( $data['titles'][ $key ] ) ) {
			return (string) $data['titles'][ $key ];
		}
		return self::default_tag( $title );
	}

	/** Eindeutige Einträge aus dem Cache: key => array( title, count, recurring ), alphabetisch. */
	public static function unique_titles( $events ) {
		$out = array();
		foreach ( $events as $ev ) {
			$k = self::title_key( $ev['title'] );
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array(
					'title'     => $ev['title'],
					'count'     => 0,
					'recurring' => false,
				);
			}
			$out[ $k ]['count']++;
			if ( ! empty( $ev['recurring'] ) ) {
				$out[ $k ]['recurring'] = true;
			}
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return strnatcasecmp( self::stripped( $a['title'] ), self::stripped( $b['title'] ) );
			}
		);
		return $out;
	}

	/** Farbe eines Tags ohne gespeicherte Wahl (stabil über den Namen). */
	private static function fallback_color( $tag_norm ) {
		$order = self::auto_order();
		return $order[ hexdec( substr( md5( $tag_norm ), 0, 6 ) ) % count( $order ) ];
	}

	/**
	 * Fügt jedem Termin sein Tag hinzu. Gibt array( events, colors ) zurück (colors: Tag-Name => #rrggbb).
	 */
	public static function apply( $events ) {
		$data   = self::data();
		$disp   = array(); // normalisierter Tag => Anzeigename (erste Schreibweise).
		$colors = array();
		foreach ( $events as $i => $ev ) {
			$key = self::title_key( $ev['title'] );
			$tag = self::effective_tag( $ev['title'], $key, $data );
			$tn  = self::norm( $tag );
			if ( '' === $tn ) {
				$events[ $i ]['tags'] = array();
				continue;
			}
			if ( ! isset( $disp[ $tn ] ) ) {
				$disp[ $tn ]            = trim( preg_replace( '/\s+/u', ' ', $tag ) );
				$colors[ $disp[ $tn ] ] = isset( $data['colors'][ $tn ] ) ? $data['colors'][ $tn ] : self::fallback_color( $tn );
			}
			$events[ $i ]['tags'] = array( $disp[ $tn ] );
		}
		return array( $events, $colors );
	}

	/** Vergibt Farben für Tags ohne gespeicherte Farbe (jeweils die nächste der Reihe) und speichert sie. Gibt tag => hex zurück. */
	public static function ensure_colors( $events ) {
		$data    = self::data();
		$order   = self::auto_order();
		$changed = false;
		foreach ( self::unique_titles( $events ) as $k => $u ) {
			$tn = self::norm( self::effective_tag( $u['title'], $k, $data ) );
			if ( '' === $tn || isset( $data['colors'][ $tn ] ) ) {
				continue;
			}
			$data['colors'][ $tn ] = $order[ count( $data['colors'] ) % count( $order ) ];
			$changed               = true;
		}
		if ( $changed ) {
			update_option( self::OPT, $data, false );
		}
		return $data['colors'];
	}

	/**
	 * Eingabe der Einstellungsseite: gcalmk_tagdata[md5][t] = Tag-Text, [c] = Farbe.
	 * Nicht gesendete Einträge bleiben unverändert. WordPress ruft die Bereinigung teils zweimal auf; die fertige Struktur wird durchgereicht.
	 */
	public static function sanitize( $in ) {
		$data = self::data();
		if ( ! is_array( $in ) ) {
			return $data;
		}
		if ( isset( $in['titles'] ) || isset( $in['colors'] ) ) {
			return array(
				'titles' => isset( $in['titles'] ) && is_array( $in['titles'] ) ? $in['titles'] : array(),
				'colors' => isset( $in['colors'] ) && is_array( $in['colors'] ) ? $in['colors'] : array(),
			);
		}

		$allowed  = self::palette();
		$defaults = array();
		foreach ( self::unique_titles( GCalMK_Sync::get_cache()['events'] ) as $k => $u ) {
			$defaults[ $k ] = self::norm( self::default_tag( $u['title'] ) );
		}

		foreach ( $in as $k => $row ) {
			$k = strtolower( preg_replace( '/[^A-Fa-f0-9]/', '', (string) $k ) );
			if ( 32 !== strlen( $k ) || ! is_array( $row ) ) {
				continue;
			}
			$tag = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( isset( $row['t'] ) ? (string) $row['t'] : '' ) ) );
			$tag = mb_substr( $tag, 0, 80, 'UTF-8' );
			$tn  = self::norm( $tag );
			if ( isset( $defaults[ $k ] ) && $tn === $defaults[ $k ] ) {
				unset( $data['titles'][ $k ] ); // entspricht dem Standard
			} else {
				$data['titles'][ $k ] = $tag; // '' = bewusst kein Tag
			}
			$hex = isset( $row['c'] ) ? strtolower( (string) $row['c'] ) : '';
			if ( '' !== $tn && isset( $allowed[ $hex ] ) ) {
				$data['colors'][ $tn ] = $hex;
			}
		}
		return $data;
	}
}
