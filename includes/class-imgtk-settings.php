<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings: storage, defaults, sanitization, and the save handler
 * for the Settings tab. Deliberately not using the Settings API sections
 * machinery so the admin page markup stays simple to read and style.
 */
class IMGTK_Settings {

	const OPTION_KEY = 'imgtk_settings';

	public function __construct() {
		add_action( 'admin_post_imgtk_save_settings', array( $this, 'save' ) );
	}

	public static function defaults() {
		return array(
			'convert_format'           => '',   // '' = keep original format, or webp|avif|jpeg|png
			'quality'                  => 82,
			'max_width'                => 2560, // 0 disables the width cap
			'max_height'               => 2560, // 0 disables the height cap
			'keep_backup'              => 1,
			'regenerate_thumbnails'    => 1,
			'skip_already_optimized'   => 1,
			'delete_data_on_uninstall' => 0,
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$allowed_formats        = array( '', 'webp', 'avif', 'jpeg', 'png' );
		$out['convert_format']  = in_array( $input['convert_format'] ?? '', $allowed_formats, true ) ? $input['convert_format'] : '';
		$out['quality']         = min( 100, max( 1, (int) ( $input['quality'] ?? 82 ) ) );
		$out['max_width']       = max( 0, (int) ( $input['max_width'] ?? 0 ) );
		$out['max_height']      = max( 0, (int) ( $input['max_height'] ?? 0 ) );

		$out['keep_backup']              = empty( $input['keep_backup'] ) ? 0 : 1;
		$out['regenerate_thumbnails']    = empty( $input['regenerate_thumbnails'] ) ? 0 : 1;
		$out['skip_already_optimized']   = empty( $input['skip_already_optimized'] ) ? 0 : 1;
		$out['delete_data_on_uninstall'] = empty( $input['delete_data_on_uninstall'] ) ? 0 : 1;

		return $out;
	}

	public function save() {
		if ( ! current_user_can( IMGTK_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'image-toolkit' ) );
		}
		check_admin_referer( 'imgtk_save_settings' );

		$input = isset( $_POST['imgtk_settings'] ) ? wp_unslash( $_POST['imgtk_settings'] ) : array();
		update_option( self::OPTION_KEY, self::sanitize( $input ) );

		$redirect = add_query_arg( array( 'page' => 'image-toolkit-settings', 'updated' => '1' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}
}
