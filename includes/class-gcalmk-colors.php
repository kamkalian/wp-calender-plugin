<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Farbe je Tag. Neue Tags bekommen automatisch eine noch nicht vergebene Farbe,
 * die Zuordnung wird gespeichert (stabil) und lässt sich in den Einstellungen ändern.
 */
class GCalMK_Colors {

	const OPTION = 'gcalmk_tag_colors';

	/** Karte "tag klein geschrieben" => "#rrggbb". */
	public static function get() {
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	public static function key( $tag ) {
		return mb_strtolower( (string) $tag, 'UTF-8' );
	}

	/**
	 * Vergibt Farben für Tags, die noch keine haben. Gibt die vollständige Karte zurück.
	 */
	public static function ensure( $tags ) {
		$map     = self::get();
		$used    = array();
		foreach ( $map as $hex ) {
			$used[ strtolower( (string) $hex ) ] = true;
		}
		$n       = count( $map );
		$changed = false;
		foreach ( $tags as $tag ) {
			$k = self::key( $tag );
			if ( '' === $k || isset( $map[ $k ] ) ) {
				continue;
			}
			$guard = 0;
			do {
				$hex = self::palette( $n++ );
			} while ( isset( $used[ $hex ] ) && ++$guard < 1000 );
			$map[ $k ]    = $hex;
			$used[ $hex ] = true;
			$changed      = true;
		}
		if ( $changed ) {
			update_option( self::OPTION, $map, false );
		}
		return $map;
	}

	/**
	 * n-te Farbe: Farbtöne im Goldenen Winkel (weit auseinander), nach je 12 Farben
	 * wechselt die Helligkeit, damit auch bei vielen Tags keine Farbe doppelt vorkommt.
	 */
	public static function palette( $n ) {
		$levels = array( array( 55, 34 ), array( 62, 44 ), array( 50, 26 ) ); // Sättigung, Helligkeit in %.
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
	 * Bereinigt die Eingabe der Einstellungsseite. Erwartet Zeilen array( 'tag' => …, 'color' => … )
	 * (oder – beim zweiten Aufruf durch WordPress – schon die fertige Karte); nicht genannte Tags behalten ihre Farbe.
	 */
	public static function sanitize( $in ) {
		$map = self::get();
		if ( ! is_array( $in ) ) {
			return $map;
		}
		foreach ( $in as $k => $row ) {
			if ( is_array( $row ) ) {
				$tag = isset( $row['tag'] ) ? sanitize_text_field( (string) $row['tag'] ) : '';
				$hex = isset( $row['color'] ) ? sanitize_hex_color( (string) $row['color'] ) : '';
			} else {
				$tag = sanitize_text_field( (string) $k );
				$hex = sanitize_hex_color( (string) $row );
			}
			if ( '' !== $tag && $hex ) {
				$map[ self::key( $tag ) ] = strtolower( $hex );
			}
		}
		return $map;
	}
}
