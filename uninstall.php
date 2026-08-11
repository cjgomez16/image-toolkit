<?php
/**
 * Fires only on actual plugin deletion (not deactivation). By default this
 * leaves everything in place — images, backups, and tracking data — since
 * losing that silently would be far more damaging than a few stray rows.
 * Deletion of plugin data only happens if the admin opted in via the
 * "On uninstall" setting.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'imgtk_settings' );
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

delete_option( 'imgtk_settings' );

$meta_keys = array(
	'_imgtk_optimized',
	'_imgtk_before_size',
	'_imgtk_after_size',
	'_imgtk_backup_path',
	'_imgtk_usage_status',
	'_imgtk_usage_locations',
	'_imgtk_last_scanned',
);

foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );
}

// Deliberately not touching wp-content/uploads/image-toolkit-backups/ here:
// uninstalling the plugin should not silently destroy the one safety net
// an admin has for images it already modified.
