<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags stehen in der Beschreibung des Google-Termins in eckigen Klammern, z. B. "[Angebot] [Jugend]".
 * Ein Termin kann mehrere Tags haben. In den Einstellungen bekommt jeder Tag eine Farbe und optional einen Bereich
 * (Überschrift) für den Ausdruck. Tags ohne Bereich gibt es auch: Sie filtern nur im Kalender und kommen nicht in den Ausdruck.
 *
 * Option gcalmk_tagdata:  tags => array( Tagname klein geschrieben => array( name, color, area ) )
 */
class GCalMK_Tags {

	const OPT = 'gcalmk_tagdata';

	/** Auswahl der Farben für das Dropdown (Hex => Name). */
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

	private static function auto_order() {
		return array( '#c62828', '#1e88e5', '#2e7d32', '#e65100', '#6a1b9a', '#00897b', '#b8860b', '#c2185b', '#1565c0', '#6b7d1f', '#7b1e3a', '#ec407a', '#1b5e20', '#9c27b0', '#6d4c41', '#006978', '#43a047', '#0d3b8c', '#6b7280', '#222222' );
	}

	/** Farbe eines Tags ohne gespeicherte Wahl (stabil über den Namen). */
	public static function fallback_color( $tag_norm ) {
		$order = self::auto_order();
		return $order[ hexdec( substr( md5( $tag_norm ), 0, 6 ) ) % count( $order ) ];
	}

	public static function norm( $s ) {
		return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $s ) ), 'UTF-8' );
	}

	/** Bereiche des Ausdrucks (Überschriften) – Standard, solange nichts gespeichert ist. */
	public static function default_areas() {
		return array(
			'Treffen und Angebote',
			'Selbsthilfegruppen und Beratungen',
			'Unsere Jugendangebote',
			'Sonstige Veranstaltungen',
			'Tagesfahrten / Wanderungen / Urlaub',
		);
	}

	/** Vorgeschlagene Tags, solange noch nichts gespeichert wurde. */
	public static function default_tags() {
		$p   = array_keys( self::palette() );
		$def = array(
			array( 'Angebot', '#1e88e5', 'Treffen und Angebote' ),
			array( 'Selbsthilfe', '#2e7d32', 'Selbsthilfegruppen und Beratungen' ),
			array( 'Jugend', '#e65100', 'Unsere Jugendangebote' ),
			array( 'Event', '#6a1b9a', 'Sonstige Veranstaltungen' ),
			array( 'Tour', '#00897b', 'Tagesfahrten / Wanderungen / Urlaub' ),
			array( 'Urlaub', '#c2185b', 'Tagesfahrten / Wanderungen / Urlaub' ),
		);
		$out = array();
		foreach ( $def as $d ) {
			$out[ self::norm( $d[0] ) ] = array(
				'name'  => $d[0],
				'color' => $d[1],
				'area'  => $d[2],
			);
		}
		return $out;
	}

	/** Gespeicherte Tag-Einstellungen (norm => name, color, area); vor dem ersten Speichern die Vorschläge. */
	public static function config() {
		$d = get_option( self::OPT, null );
		if ( is_array( $d ) && isset( $d['tags'] ) && is_array( $d['tags'] ) ) {
			return $d['tags'];
		}
		return self::default_tags();
	}

	/** Alle Tags, die in den Terminen vorkommen: norm => array( name, count ) (Name in der ersten gefundenen Schreibweise). */
	public static function found( $events ) {
		$out = array();
		foreach ( $events as $ev ) {
			if ( empty( $ev['tags'] ) ) {
				continue;
			}
			foreach ( $ev['tags'] as $t ) {
				$n = self::norm( $t );
				if ( '' === $n ) {
					continue;
				}
				if ( ! isset( $out[ $n ] ) ) {
					$out[ $n ] = array(
						'name'  => trim( preg_replace( '/\s+/u', ' ', $t ) ),
						'count' => 0,
					);
				}
				$out[ $n ]['count']++;
			}
		}
		return $out;
	}

	/**
	 * Wendet die Einstellungen auf die Termine an: kanonische Tag-Namen, Bereiche für den Ausdruck und Farben.
	 * Gibt array( events, colors ) zurück (colors: Tag-Name => #rrggbb).
	 */
	public static function apply( $events ) {
		$cfg   = self::config();
		$areas = array();
		foreach ( GCalMK_Settings::get()['print_areas'] as $a ) {
			$areas[ self::norm( $a ) ] = $a;
		}
		$disp   = array(); // norm => Anzeigename.
		$colors = array();
		foreach ( $events as $i => $ev ) {
			$tags    = array();
			$evareas = array();
			$seen    = array();
			foreach ( isset( $ev['tags'] ) ? $ev['tags'] : array() as $t ) {
				$n = self::norm( $t );
				if ( '' === $n || isset( $seen[ $n ] ) ) {
					continue;
				}
				$seen[ $n ] = true;
				if ( isset( $cfg[ $n ] ) ) {
					$name  = $cfg[ $n ]['name'];
					$color = $cfg[ $n ]['color'];
					$an    = self::norm( isset( $cfg[ $n ]['area'] ) ? $cfg[ $n ]['area'] : '' );
					if ( '' !== $an && isset( $areas[ $an ] ) && ! in_array( $areas[ $an ], $evareas, true ) ) {
						$evareas[] = $areas[ $an ];
					}
				} else {
					if ( ! isset( $disp[ $n ] ) ) {
						$disp[ $n ] = trim( preg_replace( '/\s+/u', ' ', $t ) );
					}
					$name  = $disp[ $n ];
					$color = self::fallback_color( $n );
				}
				$tags[]          = $name;
				$colors[ $name ] = $color;
			}
			$events[ $i ]['tags'] = $tags;
			if ( $evareas ) {
				$events[ $i ]['areas'] = $evareas;
			} else {
				unset( $events[ $i ]['areas'] );
			}
		}
		return array( $events, $colors );
	}

	/**
	 * Eingabe der Einstellungsseite: gcalmk_tagdata[rows][i][name|color|area|del].
	 * WordPress ruft die Bereinigung teils zweimal auf; die fertige Struktur (tags => …) wird durchgereicht.
	 */
	public static function sanitize( $in ) {
		if ( ! is_array( $in ) ) {
			return array( 'tags' => self::config() );
		}
		if ( isset( $in['tags'] ) && is_array( $in['tags'] ) ) {
			return array( 'tags' => $in['tags'] );
		}
		if ( ! isset( $in['rows'] ) || ! is_array( $in['rows'] ) ) {
			return array( 'tags' => self::config() );
		}
		$allowed = self::palette();
		$out     = array();
		foreach ( $in['rows'] as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['del'] ) ) {
				continue;
			}
			$name = mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( isset( $row['name'] ) ? (string) $row['name'] : '' ) ) ), 0, 40, 'UTF-8' );
			$name = trim( str_replace( array( '[', ']' ), '', $name ) );
			$n    = self::norm( $name );
			if ( '' === $n || isset( $out[ $n ] ) ) {
				continue;
			}
			$hex = isset( $row['color'] ) ? strtolower( (string) $row['color'] ) : '';
			if ( ! isset( $allowed[ $hex ] ) ) {
				$hex = self::fallback_color( $n );
			}
			$out[ $n ] = array(
				'name'  => $name,
				'color' => $hex,
				'area'  => mb_substr( trim( sanitize_text_field( isset( $row['area'] ) ? (string) $row['area'] : '' ) ), 0, 80, 'UTF-8' ),
			);
		}
		return array( 'tags' => $out );
	}
}
