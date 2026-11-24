<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCalMK_Shortcode {

	public static function init() {
		add_shortcode( 'gcal_kalender', array( __CLASS__, 'render' ) );
	}

	private static function yes( $v ) {
		return ! in_array( strtolower( (string) $v ), array( 'nein', 'no', '0', 'false' ), true );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'tag'   => '',
				'suche' => 'ja',
				'tags'  => 'ja',
				'bereich'  => '',   // Bereich beim Laden vorgewählt
				'bereiche' => 'ja', // "nein" blendet die Bereichs-Filter aus
				'breite' => 'voll', // "voll" = ganze Fensterbreite, "inhalt" = Breite des Theme-Inhaltsbereichs.
				'oben'   => '',     // Abstand des fixierten Kopfbereichs zum Fensterrand in px (bei festem Theme-Header).
			),
			$atts,
			'gcal_kalender'
		);

		wp_enqueue_style( 'gcalmk', GCALMK_URL . 'assets/calendar.css', array(), GCALMK_VERSION );
		wp_enqueue_script( 'gcalmk', GCALMK_URL . 'assets/calendar.js', array(), GCALMK_VERSION, true );

		return sprintf(
			'<div class="gcalmk%s" data-endpoint="%s" data-tag="%s" data-search="%s" data-tags="%s" data-area="%s" data-areas="%s"%s><noscript>Der Kalender benötigt JavaScript.</noscript></div>',
			'inhalt' === strtolower( $atts['breite'] ) ? '' : ' gcalmk-full',
			esc_url( rest_url( 'gcalmk/v1/events' ) ),
			esc_attr( $atts['tag'] ),
			self::yes( $atts['suche'] ) ? '1' : '0',
			self::yes( $atts['tags'] ) ? '1' : '0',
			esc_attr( $atts['bereich'] ),
			self::yes( $atts['bereiche'] ) ? '1' : '0',
			is_numeric( $atts['oben'] ) ? ' data-top="' . (int) $atts['oben'] . '"' : ''
		);
	}
}
