<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags werden manuell gepflegt:
 *  - Tag-Liste (Name + Farbe), Option gcalmk_tag_defs:  id => array( id, name, color )
 *  - Zuordnung Termintitel => Tag,  Option gcalmk_tag_map:  md5(Titel) => id
 * Termine mit gleichem Titel (z. B. alle Wiederholungen einer Serie) teilen sich einen Eintrag.
 */
class GCalMK_Tags {

	const OPT_DEFS = 'gcalmk_tag_defs';
	const OPT_MAP  = 'gcalmk_tag_map';

	/** Schlüssel eines Termintitels (Groß-/Kleinschreibung und Leerraum egal). */
	public static function title_key( $title ) {
		$t = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $title ) ), 'UTF-8' );
		return md5( $t );
	}

	public static function defs() {
		$d = get_option( self::OPT_DEFS, array() );
		return is_array( $d ) ? $d : array();
	}

	public static function map() {
		$m = get_option( self::OPT_MAP, array() );
		return is_array( $m ) ? $m : array();
	}

	/** Eindeutige Termintitel aus dem Cache: array( key => array( title, count, recurring ) ), alphabetisch. */
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
				return strnatcasecmp( $a['title'], $b['title'] );
			}
		);
		return $out;
	}

	/**
	 * Fügt jedem Termin sein Tag (Name) hinzu und liefert zusätzlich die Farben.
	 * Gibt array( events, colors ) zurück.
	 */
	public static function apply( $events ) {
		$defs   = self::defs();
		$map    = self::map();
		$colors = array();
		foreach ( $defs as $d ) {
			$colors[ $d['name'] ] = $d['color'];
		}
		foreach ( $events as $i => $ev ) {
			$id                    = isset( $map[ self::title_key( $ev['title'] ) ] ) ? (int) $map[ self::title_key( $ev['title'] ) ] : 0;
			$events[ $i ]['tags'] = isset( $defs[ $id ] ) ? array( $defs[ $id ]['name'] ) : array();
		}
		return array( $events, $colors );
	}

	/** Vorschlagsfarbe: Farbtöne im Goldenen Winkel, nach je 12 Farben wechselt die Helligkeit. */
	public static function palette( $n ) {
		$levels = array( array( 55, 34 ), array( 62, 44 ), array( 50, 26 ) );
		$lv     = $levels[ intdiv( $n, 12 ) % 3 ];
		return self::hsl_to_hex( fmod( $n * 137.508, 360 ), $lv[0], $lv[1] );
	}

	private static function hsl_to_hex( $h, $s, $l ) {
		$s /= 100;
		$l /= 100;
		$c  = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x  = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m  = $l - $c / 2;
		if ( $h < 60 ) {
			list( $r, $g, $b ) = array( $c, $x, 0 );
		} elseif ( $h < 120 ) {
			list( $r, $g, $b ) = array( $x, $c, 0 );
		} elseif ( $h < 180 ) {
			list( $r, $g, $b ) = array( 0, $c, $x );
		} elseif ( $h < 240 ) {
			list( $r, $g, $b ) = array( 0, $x, $c );
		} elseif ( $h < 300 ) {
			list( $r, $g, $b ) = array( $x, 0, $c );
		} else {
			list( $r, $g, $b ) = array( $c, 0, $x );
		}
		return sprintf( '#%02x%02x%02x', round( ( $r + $m ) * 255 ), round( ( $g + $m ) * 255 ), round( ( $b + $m ) * 255 ) );
	}

	/**
	 * Tag-Liste aus dem Formular: Zeilen array( id, name, color, delete ).
	 * Auch die fertige Liste (id => Zeile) ist gültige Eingabe, WordPress ruft die Bereinigung teils zweimal auf.
	 */
	public static function sanitize_defs( $in ) {
		$old = self::defs();
		if ( ! is_array( $in ) ) {
			return $old;
		}
		$max = 0;
		foreach ( $old as $id => $d ) {
			$max = max( $max, (int) $id );
		}
		foreach ( $in as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$max = max( $max, (int) $row['id'] );
			}
		}

		$out   = array();
		$names = array();
		foreach ( $in as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}
			$name = trim( sanitize_text_field( isset( $row['name'] ) ? (string) $row['name'] : '' ) );
			if ( '' === $name ) {
				continue;
			}
			$lk = mb_strtolower( $name, 'UTF-8' );
			if ( isset( $names[ $lk ] ) ) {
				continue; // Namen sind eindeutig.
			}
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id <= 0 || isset( $out[ $id ] ) ) {
				$id = ++$max;
			}
			$hex = isset( $row['color'] ) ? sanitize_hex_color( (string) $row['color'] ) : '';
			if ( ! $hex ) {
				$hex = isset( $old[ $id ]['color'] ) ? $old[ $id ]['color'] : self::palette( count( $out ) );
			}
			$names[ $lk ] = true;
			$out[ $id ]   = array(
				'id'    => $id,
				'name'  => $name,
				'color' => strtolower( $hex ),
			);
		}
		return $out;
	}

	/** Zuordnung aus dem Formular: md5 => Tag-ID (leer/0 = Zuordnung entfernen). Nicht gesendete Einträge bleiben. */
	public static function sanitize_map( $in ) {
		$map = self::map();
		if ( ! is_array( $in ) ) {
			return $map;
		}
		foreach ( $in as $k => $v ) {
			$k = strtolower( preg_replace( '/[^A-Fa-f0-9]/', '', (string) $k ) );
			if ( 32 !== strlen( $k ) ) {
				continue;
			}
			$v = (int) $v;
			if ( $v > 0 ) {
				$map[ $k ] = $v;
			} else {
				unset( $map[ $k ] );
			}
		}
		return $map;
	}
}
