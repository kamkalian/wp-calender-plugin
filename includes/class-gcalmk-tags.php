<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags und Bereiche, beides in den Einstellungen manuell gepflegt.
 *
 *  - Bereiche = Überschriften des Ausdrucks (geordnete Liste, jeder mit fester Nummer, damit Umbenennen nichts zerstört).
 *  - Tags = Name, Farbe und ein Bereich (oder keiner). Mehrere Tags können denselben Bereich haben.
 *
 * Ein Termin bekommt einen Tag, wenn der Tag-Name im Titel als ganzes Wort vorkommt (Groß-/Kleinschreibung egal,
 * "Cafe" passt zu "Cafe am Markt", nicht zu "SonntagsCafe"). Ein Termin kann mehrere Tags haben. Der Tag, der im Titel
 * zuerst vorkommt und einen Bereich hat, entscheidet über den Bereich im Ausdruck (ein Termin steht nur in einem Bereich).
 * Termine ohne Bereich werden nicht gedruckt; Tags ohne Bereich dienen nur als Filter im Kalender.
 *
 * Option gcalmk_tagdata:
 *   areas => array( Nummer => Name )                                   (Reihenfolge = Druckreihenfolge)
 *   tags  => array( Tagname klein geschrieben => array( name, color, area = Nummer oder 0 ) )
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

	/** Farbe eines Tags ohne gültige Wahl (stabil über den Namen). */
	public static function fallback_color( $tag_norm ) {
		$order = self::auto_order();
		return $order[ hexdec( substr( md5( $tag_norm ), 0, 6 ) ) % count( $order ) ];
	}

	public static function norm( $s ) {
		return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $s ) ), 'UTF-8' );
	}

	/** Bereiche, solange noch nichts gespeichert wurde (Überschriften aus der gedruckten Vorlage). */
	public static function default_areas() {
		return array(
			1 => 'Treffen und Angebote',
			2 => 'Selbsthilfegruppen und Beratungen',
			3 => 'Unsere Jugendangebote',
			4 => 'Sonstige Veranstaltungen',
			5 => 'Tagesfahrten / Wanderungen / Urlaub',
		);
	}

	/** Bereiche in Druckreihenfolge: Nummer => Name. */
	public static function areas_config() {
		$d = get_option( self::OPT, null );
		if ( is_array( $d ) && isset( $d['areas'] ) && is_array( $d['areas'] ) ) {
			return $d['areas'];
		}
		return self::default_areas();
	}

	/** Namen der Bereiche in Druckreihenfolge (für den öffentlichen Endpunkt). */
	public static function areas() {
		return array_values( self::areas_config() );
	}

	/** Gespeicherte Tag-Einstellungen (norm => name, color, area = Bereichsnummer oder 0). */
	public static function config() {
		$d = get_option( self::OPT, null );
		if ( ! is_array( $d ) || ! isset( $d['tags'] ) || ! is_array( $d['tags'] ) ) {
			return array();
		}
		return $d['tags'];
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
			// Treffer nur als ganzes Wort: davor und danach kein Buchstabe und keine Ziffer
			// (Bindestrich, Leerzeichen, Klammer usw. sind Wortgrenzen). Maßgeblich ist das früheste Vorkommen im Titel.
			// Nur die Stichwörter zählen; der Tag-Name ist nur die Bezeichnung. Alte Einträge ohne Stichwort-Feld: der Name ist das Stichwort.
			$words = isset( $c['words'] ) && is_array( $c['words'] ) ? $c['words'] : array( $n );
			$best = null;
			foreach ( $words as $w ) {
				$w = (string) $w;
				if ( '' !== $w && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/iu', (string) $title, $m, PREG_OFFSET_CAPTURE ) ) {
					$cand = array( $m[0][1], -mb_strlen( $w, 'UTF-8' ) );
					if ( null === $best || $cand < $best ) {
						$best = $cand;
					}
				}
			}
			if ( null !== $best ) {
				$hits[] = array( $best[0], $best[1], $idx, $n );
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
		$cfg    = self::config();
		$areas  = self::areas_config();
		$colors = array();
		foreach ( $events as $i => $ev ) {
			$tags = array();
			$area = '';
			foreach ( self::match( $ev['title'], $cfg ) as $n ) {
				$c                    = $cfg[ $n ];
				$tags[]               = $c['name'];
				$colors[ $c['name'] ] = $c['color'];
				$aid                  = isset( $c['area'] ) ? (int) $c['area'] : 0;
				if ( '' === $area && $aid > 0 && isset( $areas[ $aid ] ) ) {
					$area = $areas[ $aid ]; // das erste Vorkommen mit Bereich entscheidet
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

	// ---- Speichern -------------------------------------------------------------------------------------------------

	private static function clean_area_name( $s ) {
		return mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $s ) ) ), 0, 80, 'UTF-8' );
	}

	private static function clean_tag_name( $s ) {
		$name = mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $s ) ) ), 0, 40, 'UTF-8' );
		return trim( str_replace( array( '[', ']' ), '', $name ) );
	}

	/** Weitere Stichwörter aus einer Eingabe (Komma, Semikolon oder Zeilenumbruch getrennt, oder Liste): klein geschrieben, eindeutig. */
	private static function clean_words( $in, $own ) {
		$list = is_array( $in ) ? $in : preg_split( '/[,;\n]+/u', (string) $in );
		$out  = array();
		foreach ( (array) $list as $w ) {
			$w = self::norm( mb_substr( trim( str_replace( array( '[', ']' ), '', sanitize_text_field( (string) $w ) ) ), 0, 40, 'UTF-8' ) );
			if ( '' !== $w && ! in_array( $w, $out, true ) ) {
				$out[] = $w;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	private static function clean_color( $hex, $n ) {
		$hex = strtolower( (string) $hex );
		return isset( self::palette()[ $hex ] ) ? $hex : self::fallback_color( $n );
	}

	/**
	 * Eingabe der Einstellungsseite:
	 *   gcalmk_tagdata[arearows][i][id|name|del]   Bereiche (fehlt das Feld ganz, bleiben die Bereiche unverändert)
	 *   gcalmk_tagdata[rows][i][name|color|area|del]   Tags; area = Bereichsnummer (leer = kein Bereich)
	 * WordPress ruft die Bereinigung teils zweimal auf; die fertige Struktur (tags => …) wird durchgereicht.
	 */
	public static function sanitize( $in ) {
		$current_areas = self::areas_config();
		if ( ! is_array( $in ) ) {
			return array(
				'areas' => $current_areas,
				'tags'  => self::config(),
			);
		}
		if ( isset( $in['tags'] ) && is_array( $in['tags'] ) ) {
			return array(
				'areas' => isset( $in['areas'] ) && is_array( $in['areas'] ) ? $in['areas'] : $current_areas,
				'tags'  => $in['tags'],
			);
		}

		// Bereiche
		$areas = $current_areas;
		if ( isset( $in['arearows'] ) && is_array( $in['arearows'] ) ) {
			$areas = array();
			$max   = 0;
			foreach ( $current_areas as $id => $nm ) {
				$max = max( $max, (int) $id );
			}
			foreach ( $in['arearows'] as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) ) {
					$max = max( $max, (int) $row['id'] );
				}
			}
			$seen = array();
			foreach ( $in['arearows'] as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['del'] ) ) {
					continue;
				}
				$name = self::clean_area_name( isset( $row['name'] ) ? $row['name'] : '' );
				$n    = self::norm( $name );
				if ( '' === $n || isset( $seen[ $n ] ) ) {
					continue;
				}
				$seen[ $n ] = true;
				$id         = isset( $row['id'] ) ? (int) $row['id'] : 0;
				if ( $id <= 0 || isset( $areas[ $id ] ) ) {
					$id = ++$max;
				}
				$areas[ $id ] = $name;
			}
			$areas = array_slice( $areas, 0, 30, true );
		}

		// Tags
		$tags = self::config();
		if ( isset( $in['rows'] ) && is_array( $in['rows'] ) ) {
			$tags   = array();
			$byname = array();
			foreach ( $areas as $id => $nm ) {
				$byname[ self::norm( $nm ) ] = (int) $id;
			}
			foreach ( $in['rows'] as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['del'] ) ) {
					continue;
				}
				$name = self::clean_tag_name( isset( $row['name'] ) ? $row['name'] : '' );
				$n    = self::norm( $name );
				if ( '' === $n || isset( $tags[ $n ] ) ) {
					continue;
				}
				$a   = isset( $row['area'] ) ? trim( (string) $row['area'] ) : '';
				$aid = 0;
				if ( ctype_digit( $a ) ) {
					$aid = (int) $a;
				} elseif ( '' !== $a && isset( $byname[ self::norm( $a ) ] ) ) {
					$aid = $byname[ self::norm( $a ) ]; // Name statt Nummer (z. B. aus älteren Eingaben)
				}
				if ( ! isset( $areas[ $aid ] ) ) {
					$aid = 0; // Bereich gibt es nicht (mehr)
				}
				$tags[ $n ] = array(
					'name'  => $name,
					'color' => self::clean_color( isset( $row['color'] ) ? $row['color'] : '', $n ),
					'area'  => $aid,
					'words' => self::clean_words( isset( $row['words'] ) ? $row['words'] : $name, $n ),
				);
			}
		} else {
			// Bereiche geändert, Tags nicht neu gesendet: Verweise auf entfernte Bereiche aufheben
			foreach ( $tags as $n => $c ) {
				if ( empty( $c['area'] ) || ! isset( $areas[ (int) $c['area'] ] ) ) {
					$tags[ $n ]['area'] = 0;
				}
			}
		}
		return array(
			'areas' => $areas,
			'tags'  => $tags,
		);
	}

	// ---- Export / Import -------------------------------------------------------------------------------------------

	/** Bereiche und Tags als Datenstruktur für die Download-Datei (Tags verweisen mit dem Namen auf ihren Bereich). */
	public static function export_data() {
		$areas = self::areas_config();
		$tags  = array();
		foreach ( self::config() as $c ) {
			$aid    = isset( $c['area'] ) ? (int) $c['area'] : 0;
			$tags[] = array(
				'name'  => $c['name'],
				'color' => $c['color'],
				'area'  => isset( $areas[ $aid ] ) ? $areas[ $aid ] : '',
				'words' => isset( $c['words'] ) && is_array( $c['words'] ) ? array_values( $c['words'] ) : array( self::norm( $c['name'] ) ),
			);
		}
		return array(
			'format'  => 'gcal-monatskalender-tags',
			'version' => 1,
			'areas'   => array_values( $areas ),
			'tags'    => $tags,
		);
	}

	/**
	 * Spielt eine zuvor exportierte Datei ein und ersetzt Bereiche und Tags. Gibt array( ok, Meldung ) zurück.
	 */
	public static function import_data( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['format'] ) || 'gcal-monatskalender-tags' !== $data['format'] || ! isset( $data['areas'], $data['tags'] ) || ! is_array( $data['areas'] ) || ! is_array( $data['tags'] ) ) {
			return array( false, 'Die Datei ist keine gültige Export-Datei dieses Plugins.' );
		}
		$arearows = array();
		foreach ( $data['areas'] as $name ) {
			if ( is_string( $name ) ) {
				$arearows[] = array(
					'id'   => 0,
					'name' => $name,
				);
			}
		}
		$rows = array();
		foreach ( $data['tags'] as $t ) {
			if ( is_array( $t ) && isset( $t['name'] ) ) {
				$rows[] = array(
					'name'  => (string) $t['name'],
					'color' => isset( $t['color'] ) ? (string) $t['color'] : '',
					'area'  => isset( $t['area'] ) ? (string) $t['area'] : '', // Name; wird in sanitize() der Nummer zugeordnet
					'words' => isset( $t['words'] ) && is_array( $t['words'] ) ? $t['words'] : (string) $t['name'],
				);
			}
		}
		$clean = self::sanitize(
			array(
				'arearows' => $arearows,
				'rows'     => $rows,
			)
		);
		update_option( self::OPT, $clean );
		return array( true, count( $clean['areas'] ) . ' Bereiche und ' . count( $clean['tags'] ) . ' Tags eingespielt.' );
	}
}
