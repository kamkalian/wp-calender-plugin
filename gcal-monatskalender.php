<?php
/**
 * Plugin Name: GCal Monatskalender
 * Description: Liest die Termine eines öffentlichen Google-Kalenders alle 10 Minuten per API-Key ein und zeigt sie per Shortcode [gcal_kalender] als Monatsansicht mit Tag-Filter und Suche.
 * Version:     1.0.5
 * Author:      FaWoWa
 * License:     GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GCALMK_VERSION', '1.0.5' );
define( 'GCALMK_FILE', __FILE__ );
define( 'GCALMK_DIR', plugin_dir_path( __FILE__ ) );
define( 'GCALMK_URL', plugin_dir_url( __FILE__ ) );

require_once GCALMK_DIR . 'includes/class-gcalmk-sync.php';
require_once GCALMK_DIR . 'includes/class-gcalmk-settings.php';
require_once GCALMK_DIR . 'includes/class-gcalmk-rest.php';
require_once GCALMK_DIR . 'includes/class-gcalmk-shortcode.php';

GCalMK_Sync::init();
GCalMK_Settings::init();
GCalMK_Rest::init();
GCalMK_Shortcode::init();

register_activation_hook( __FILE__, array( 'GCalMK_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GCalMK_Sync', 'deactivate' ) );
