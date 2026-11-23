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
		$cache    = GCalMK_Sync::get_cache();
		$settings = GCalMK_Settings::get();
		$excluded = array();
		foreach ( $settings['excluded_tags'] as $t ) {
			$excluded[ mb_strtolower( (string) $t, 'UTF-8' ) ] = true;
		}

		$events = $cache['events'];
		$tags   = $cache['tags'];
		if ( $excluded ) {
			$keep = function ( $t ) use ( $excluded ) {
				return ! isset( $excluded[ mb_strtolower( (string) $t, 'UTF-8' ) ] );
			};
			foreach ( $events as $i => $ev ) {
				$events[ $i ]['tags'] = array_values( array_filter( $ev['tags'], $keep ) );
			}
			$tags = array_values( array_filter( $tags, $keep ) );
		}

		$res = new WP_REST_Response(
			array(
				'synced' => (int) $cache['synced'],
				'tags'   => $tags,
				'events' => $events,
			)
		);
		$res->header( 'Cache-Control', 'public, max-age=60' );
		return $res;
	}
}
