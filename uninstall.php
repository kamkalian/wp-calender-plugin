<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'gcalmk_settings' );
delete_option( 'gcalmk_cache' );
delete_option( 'gcalmk_status' );
delete_transient( 'gcalmk_lock' );
wp_clear_scheduled_hook( 'gcalmk_sync_event' );
