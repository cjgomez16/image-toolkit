<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI companion for the admin UI. Useful on sites with large media
 * libraries where running everything through a browser tab is slow —
 * `wp image-toolkit optimize --all` can run over SSH or from cron
 * without worrying about PHP execution timeouts.
 */
class IMGTK_CLI {

	/**
	 * Resize/compress/convert images according to the plugin's saved settings.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Process every image attachment, including ones already optimized.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs to process instead of the whole library.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-toolkit optimize
	 *     wp image-toolkit optimize --all
	 *     wp image-toolkit optimize --ids=12,45,201
	 */
	public function optimize( $args, $assoc_args ) {
		$ids = $this->resolve_ids( $assoc_args, '_imgtk_optimized' );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Optimizing', count( $ids ) );
		$done     = 0;
		$saved    = 0;

		foreach ( $ids as $id ) {
			$result = IMGTK_Processor::process( $id, array( 'skip_already_optimized' => empty( $assoc_args['all'] ) ) );
			if ( ! is_wp_error( $result ) ) {
				$done++;
				$saved += $result['saved_bytes'];
			}
			$progress->tick();
		}
		$progress->finish();

		WP_CLI::success( sprintf( 'Optimized %d image(s), reclaimed %s.', $done, size_format( $saved ) ) );
	}

	/**
	 * Scan images for usage across the site and cache the used/unused status.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Rescan every image, including ones already scanned.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs to scan instead of the whole library.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-toolkit scan
	 *     wp image-toolkit scan --all
	 */
	public function scan( $args, $assoc_args ) {
		$ids = $this->resolve_ids( $assoc_args, '_imgtk_usage_status' );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning', count( $ids ) );
		$unused   = 0;

		foreach ( $ids as $id ) {
			$result = IMGTK_Usage_Scanner::scan( $id );
			if ( 'unused' === $result['status'] ) {
				$unused++;
			}
			$progress->tick();
		}
		$progress->finish();

		WP_CLI::success( sprintf( 'Scanned %d image(s), found %d unused.', count( $ids ), $unused ) );
	}

	/**
	 * List (and optionally trash) images already flagged as unused by a previous scan.
	 *
	 * ## OPTIONS
	 *
	 * [--trash]
	 * : Move the unused images to Trash instead of just listing them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-toolkit unused
	 *     wp image-toolkit unused --trash
	 */
	public function unused( $args, $assoc_args ) {
		global $wpdb;

		$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_imgtk_usage_status' AND meta_value='unused'" );

		if ( empty( $ids ) ) {
			WP_CLI::log( 'No unused images on record. Run `wp image-toolkit scan` first.' );
			return;
		}

		if ( ! empty( $assoc_args['trash'] ) ) {
			$count = 0;
			foreach ( $ids as $id ) {
				if ( wp_trash_post( $id ) ) {
					$count++;
				}
			}
			WP_CLI::success( sprintf( 'Moved %d unused image(s) to Trash.', $count ) );
			return;
		}

		$rows = array();
		foreach ( $ids as $id ) {
			$file   = get_attached_file( $id );
			$rows[] = array(
				'ID'   => $id,
				'file' => $file ? basename( $file ) : '',
				'size' => $file && file_exists( $file ) ? size_format( filesize( $file ) ) : '?',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'file', 'size' ) );
	}

	private function resolve_ids( $assoc_args, $meta_key ) {
		if ( ! empty( $assoc_args['ids'] ) ) {
			return array_filter( array_map( 'intval', explode( ',', $assoc_args['ids'] ) ) );
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		);

		if ( empty( $assoc_args['all'] ) ) {
			$args['meta_query'] = array( array( 'key' => $meta_key, 'compare' => 'NOT EXISTS' ) );
		}

		return get_posts( $args );
	}
}
