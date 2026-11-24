<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Öffentlicher, schreibgeschützter Endpunkt: liefert den Cache (nie den API-Key).
 */
class GCalMK_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'gcalmk/v1',
			'/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'events' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function events() {
		$cache = GCalMK_Sync::get_cache();

		// Tags kommen aus der Pflege in den Einstellungen (Termintitel => Tag), Farben je Tag-Name.
		list( $events, $colors ) = GCalMK_Tags::apply( $cache['events'] );

		$res = new WP_REST_Response(
			array(
				'synced' => (int) $cache['synced'],
				'colors' => (object) $colors,
				'areas'  => array_values( GCalMK_Tags::areas() ), // Bereiche des Ausdrucks in Druckreihenfolge (Reihenfolge der Tag-Tabelle)
				'events' => $events,
			)
		);
		$res->header( 'Cache-Control', 'public, max-age=60' );
		return $res;
	}
}
