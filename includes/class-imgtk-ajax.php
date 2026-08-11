<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoints backing the Bulk Optimize / Unused Images admin pages
 * and the per-row Media Library actions. Everything here is gated to
 * IMGTK_CAP (manage_options) since this tool can resize/convert/delete
 * media site-wide.
 */
class IMGTK_Ajax {

	const BATCH_SIZE = 50;

	public function __construct() {
		add_action( 'wp_ajax_imgtk_get_optimize_queue', array( $this, 'get_optimize_queue' ) );
		add_action( 'wp_ajax_imgtk_process_one', array( $this, 'process_one' ) );
		add_action( 'wp_ajax_imgtk_get_scan_queue', array( $this, 'get_scan_queue' ) );
		add_action( 'wp_ajax_imgtk_scan_one', array( $this, 'scan_one' ) );
		add_action( 'wp_ajax_imgtk_trash_unused', array( $this, 'trash_unused' ) );
		add_action( 'wp_ajax_imgtk_restore_backup', array( $this, 'restore_backup' ) );
	}

	private function guard() {
		check_ajax_referer( 'imgtk_nonce', 'nonce' );
		if ( ! current_user_can( IMGTK_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'image-toolkit' ) ), 403 );
		}
	}

	public function get_optimize_queue() {
		$this->guard();

		$only_unoptimized = ! empty( $_POST['only_unoptimized'] );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( $only_unoptimized ) {
			$args['meta_query'] = array( array( 'key' => '_imgtk_optimized', 'compare' => 'NOT EXISTS' ) );
		}

		$ids = get_posts( $args );
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	public function process_one() {
		$this->guard();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing attachment ID.', 'image-toolkit' ) ) );
		}

		$result = IMGTK_Processor::process( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'id'      => $id,
				'title'   => get_the_title( $id ),
				'message' => $result->get_error_message(),
				'skipped' => in_array( $result->get_error_code(), array( 'imgtk_already_done', 'imgtk_not_image', 'imgtk_unsupported' ), true ),
			) );
		}

		$result['title'] = get_the_title( $id );
		wp_send_json_success( $result );
	}

	public function get_scan_queue() {
		$this->guard();

		$include_scanned = ! empty( $_POST['include_scanned'] );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( ! $include_scanned ) {
			$args['meta_query'] = array( array( 'key' => '_imgtk_usage_status', 'compare' => 'NOT EXISTS' ) );
		}

		$ids = get_posts( $args );
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	public function scan_one() {
		$this->guard();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing or invalid attachment ID.', 'image-toolkit' ) ) );
		}

		$result           = IMGTK_Usage_Scanner::scan( $id );
		$result['id']    = $id;
		$result['title'] = get_the_title( $id );
		wp_send_json_success( $result );
	}

	public function trash_unused() {
		$this->guard();

		$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
		$ids = array_filter( array_map( 'intval', $ids ) );

		$trashed = array();
		$failed  = array();

		foreach ( $ids as $id ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				$failed[] = $id;
				continue;
			}
			// Trash rather than delete: recoverable via Media Library trash
			// until it empties, files stay on disk until then.
			$result = wp_trash_post( $id );
			if ( $result ) {
				$trashed[] = $id;
			} else {
				$failed[] = $id;
			}
		}

		wp_send_json_success( array(
			'trashed' => $trashed,
			'failed'  => $failed,
		) );
	}

	public function restore_backup() {
		$this->guard();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing attachment ID.', 'image-toolkit' ) ) );
		}

		$result = IMGTK_Processor::restore( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}
}
