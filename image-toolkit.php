<?php
/**
 * Plugin Name:       Image Toolkit
 * Description:       Internal tool to resize, compress, and convert images in the Media Library, detect images that aren't used anywhere on the site, and clean them up safely.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Internal Tools
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       image-toolkit
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMGTK_VERSION', '1.0.0' );
define( 'IMGTK_FILE', __FILE__ );
define( 'IMGTK_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMGTK_URL', plugin_dir_url( __FILE__ ) );
define( 'IMGTK_BACKUP_DIRNAME', 'image-toolkit-backups' );
define( 'IMGTK_CAP', 'manage_options' );

require_once IMGTK_DIR . 'includes/class-imgtk-settings.php';
require_once IMGTK_DIR . 'includes/class-imgtk-processor.php';
require_once IMGTK_DIR . 'includes/class-imgtk-usage-scanner.php';
require_once IMGTK_DIR . 'includes/class-imgtk-admin.php';
require_once IMGTK_DIR . 'includes/class-imgtk-ajax.php';

/**
 * Central bootstrap. Keeps hook wiring in one place so the rest of the
 * classes stay focused on a single responsibility each.
 */
final class IMGTK_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_notices', array( $this, 'requirements_notice' ) );

		if ( is_admin() ) {
			new IMGTK_Settings();
			new IMGTK_Admin();
			new IMGTK_Ajax();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once IMGTK_DIR . 'includes/class-imgtk-cli.php';
			WP_CLI::add_command( 'image-toolkit', 'IMGTK_CLI' );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'image-toolkit', false, dirname( plugin_basename( IMGTK_FILE ) ) . '/languages' );
	}

	/**
	 * Warn the site admin if the server can't actually do what the
	 * current settings ask for (e.g. WebP conversion selected but the
	 * installed GD/Imagick build doesn't support it).
	 */
	public function requirements_notice() {
		if ( ! current_user_can( IMGTK_CAP ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_our_pages = $screen && ( false !== strpos( (string) $screen->id, 'image-toolkit' ) );
		if ( ! $on_our_pages ) {
			return;
		}

		$settings = IMGTK_Settings::get();
		$format   = $settings['convert_format'];
		if ( ! $format ) {
			return;
		}

		$mime_map = array(
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);
		if ( ! isset( $mime_map[ $format ] ) ) {
			return;
		}

		$supported = wp_image_editor_supports( array( 'mime_type' => $mime_map[ $format ] ) );
		if ( ! $supported ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %s: image format such as WebP or AVIF */
					__( 'Image Toolkit: the server\'s image library does not appear to support %s output. Conversions to this format will fail until GD or Imagick is upgraded.', 'image-toolkit' ),
					strtoupper( $format )
				) )
			);
		}
	}
}

function imgtk_activate() {
	$defaults = IMGTK_Settings::defaults();
	if ( false === get_option( IMGTK_Settings::OPTION_KEY ) ) {
		add_option( IMGTK_Settings::OPTION_KEY, $defaults );
	}

	$upload_dir  = wp_upload_dir();
	$backup_path = trailingslashit( $upload_dir['basedir'] ) . IMGTK_BACKUP_DIRNAME;
	if ( ! file_exists( $backup_path ) ) {
		wp_mkdir_p( $backup_path );
		// Keep the backup folder out of casual browsing / indexing.
		file_put_contents( trailingslashit( $backup_path ) . 'index.php', "<?php\n// Silence is golden.\n" );
	}
}
register_activation_hook( IMGTK_FILE, 'imgtk_activate' );

IMGTK_Plugin::instance();
