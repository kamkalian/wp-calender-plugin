<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags werden in den Einstellungen manuell angelegt (Name, Farbe, optional Bereich im Ausdruck).
 * Ein Termin bekommt einen Tag, wenn der Tag-Name im Titel als ganzes Wort vorkommt (Groß-/Kleinschreibung egal,
 * "Cafe" passt zu "Cafe am Markt", nicht zu "SonntagsCafe"). Ein Termin kann mehrere Tags haben. Der Tag, der im Titel zuerst vorkommt und einen
 * Bereich hat, entscheidet über den Bereich im Ausdruck (ein Termin steht nur in einem Bereich).
 * Tags ohne Bereich dienen nur als Filter im Kalender.
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

	/** Gespeicherte Tag-Einstellungen (norm => name, color, area). */
	public static function config() {
		$d = get_option( self::OPT, null );
		if ( is_array( $d ) && isset( $d['tags'] ) && is_array( $d['tags'] ) ) {
			return $d['tags'];
		}
		return array();
	}

	/**
	 * Welche eingerichteten Tags kommen im Titel vor? Gibt die Schlüssel (klein geschrieben) zurück, nach erstem Vorkommen im Titel
	 * sortiert (bei gleicher Stelle der längere Tag zuerst, dann die Reihenfolge der Einstellungen).
	 */
	public static function match( $title, $cfg ) {
		$hits = array();
		$idx  = 0;
		foreach ( $cfg as $n => $c ) {
			$idx++;
			if ( '' === $n ) {
				continue;
			}
			// nur als ganzes Wort: davor und danach kein Buchstabe und keine Ziffer (Bindestrich, Leerzeichen, Klammer usw. sind Wortgrenzen)
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $n, '/' ) . '(?![\p{L}\p{N}])/iu', (string) $title, $m, PREG_OFFSET_CAPTURE ) ) {
				$hits[] = array( $m[0][1], -mb_strlen( $n, 'UTF-8' ), $idx, $n );
			}
		}
		usort(
			$hits,
			function ( $a, $b ) {
				return ( $a[0] <=> $b[0] ) ? ( $a[0] <=> $b[0] ) : ( ( $a[1] <=> $b[1] ) ? ( $a[1] <=> $b[1] ) : ( $a[2] <=> $b[2] ) );
			}
		);
		return array_map(
			function ( $h ) {
				return $h[3];
			},
			$hits
		);
	}

	/** Anzahl der Termine je Tag (Schlüssel => Anzahl), für die Einstellungsseite. */
	public static function counts( $events, $cfg ) {
		$out = array();
		foreach ( $events as $ev ) {
			foreach ( self::match( $ev['title'], $cfg ) as $n ) {
				$out[ $n ] = isset( $out[ $n ] ) ? $out[ $n ] + 1 : 1;
			}
		}
		return $out;
	}

	/**
	 * Wendet die Einstellungen auf die Termine an: Tags (alle zutreffenden, nach Vorkommen im Titel), der Bereich für den Ausdruck
	 * (aus dem ersten zutreffenden Tag mit Bereich) und die Farben. Gibt array( events, colors ) zurück (colors: Tag-Name => #rrggbb).
	 */
	public static function apply( $events ) {
		$cfg   = self::config();
		$areas = array();
		foreach ( GCalMK_Settings::get()['print_areas'] as $a ) {
			$areas[ self::norm( $a ) ] = $a;
		}
		$colors = array();
		foreach ( $events as $i => $ev ) {
			$tags = array();
			$area = '';
			foreach ( self::match( $ev['title'], $cfg ) as $n ) {
				$c               = $cfg[ $n ];
				$tags[]          = $c['name'];
				$colors[ $c['name'] ] = $c['color'];
				$an              = self::norm( isset( $c['area'] ) ? $c['area'] : '' );
				if ( '' === $area && '' !== $an && isset( $areas[ $an ] ) ) {
					$area = $areas[ $an ]; // das erste Vorkommen mit Bereich entscheidet
				}
			}
			$events[ $i ]['tags'] = $tags;
			if ( '' !== $area ) {
				$events[ $i ]['areas'] = array( $area );
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
