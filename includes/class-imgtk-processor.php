<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does the actual image work: resize, compress, convert, and the
 * bookkeeping (backups, WordPress subsizes) that has to happen around it.
 *
 * All of this operates on files on local disk via get_attached_file() /
 * wp_get_image_editor(). Sites that offload media to S3/CDN through a
 * plugin that intercepts those functions should test on staging first.
 */
class IMGTK_Processor {

	const SUPPORTED_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	const MIME_TO_EXT = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
		'image/gif'  => 'gif',
	);

	const FORMAT_TO_MIME = array(
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	/**
	 * Process a single attachment. Returns an array of result data or a
	 * WP_Error describing why it was skipped/failed.
	 *
	 * @return array|WP_Error
	 */
	public static function process( $attachment_id, $args = array() ) {
		$settings = wp_parse_args( $args, IMGTK_Settings::get() );

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'imgtk_not_image', __( 'Not an image attachment.', 'image-toolkit' ) );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'imgtk_missing_file', __( 'The file for this attachment is missing on disk.', 'image-toolkit' ) );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED_MIMES, true ) ) {
			return new WP_Error( 'imgtk_unsupported', __( 'Unsupported image type.', 'image-toolkit' ) );
		}

		if ( ! empty( $settings['skip_already_optimized'] ) && get_post_meta( $attachment_id, '_imgtk_optimized', true ) ) {
			return new WP_Error( 'imgtk_already_done', __( 'Already optimized; skipped.', 'image-toolkit' ) );
		}

		$before_size = filesize( $file );

		if ( ! empty( $settings['keep_backup'] ) && ! get_post_meta( $attachment_id, '_imgtk_backup_path', true ) ) {
			$backup_relative = self::create_backup( $attachment_id, $file );
			if ( $backup_relative ) {
				update_post_meta( $attachment_id, '_imgtk_backup_path', $backup_relative );
			}
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$max_w = (int) ( $settings['max_width'] ?? 0 );
		$max_h = (int) ( $settings['max_height'] ?? 0 );
		if ( $max_w > 0 || $max_h > 0 ) {
			$current = $editor->get_size();
			$too_big = $current && ( ( $max_w > 0 && $current['width'] > $max_w ) || ( $max_h > 0 && $current['height'] > $max_h ) );
			if ( $too_big ) {
				$editor->resize( $max_w ?: null, $max_h ?: null, false );
			}
		}

		if ( ! empty( $settings['quality'] ) ) {
			$editor->set_quality( (int) $settings['quality'] );
		}

		$target_mime = $mime;
		$convert     = $settings['convert_format'] ?? '';
		if ( $convert && isset( self::FORMAT_TO_MIME[ $convert ] ) && self::FORMAT_TO_MIME[ $convert ] !== $mime ) {
			$target_mime = self::FORMAT_TO_MIME[ $convert ];
		}

		$old_metadata   = wp_get_attachment_metadata( $attachment_id );
		$changing_mime  = ( $target_mime !== $mime );
		$original_file  = $file;

		if ( $changing_mime ) {
			$path_parts = pathinfo( $file );
			$new_ext    = self::MIME_TO_EXT[ $target_mime ];
			$new_file   = trailingslashit( $path_parts['dirname'] ) . $path_parts['filename'] . '.' . $new_ext;
			$saved      = $editor->save( $new_file, $target_mime );
		} else {
			$saved = $editor->save( $file, $mime );
		}

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$new_file = $saved['path'];

		if ( $changing_mime ) {
			update_attached_file( $attachment_id, $new_file );
			wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $target_mime ) );

			if ( file_exists( $original_file ) && $original_file !== $new_file ) {
				@unlink( $original_file );
			}
		}

		if ( ! empty( $settings['regenerate_thumbnails'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';

			// Old intermediate sizes were generated from a now-deleted or
			// now-renamed original; if the extension changed they'd become
			// orphaned files, so clear them before regenerating.
			if ( $changing_mime && $old_metadata && ! empty( $old_metadata['sizes'] ) ) {
				$dir = dirname( $new_file );
				foreach ( $old_metadata['sizes'] as $size ) {
					if ( empty( $size['file'] ) ) {
						continue;
					}
					$old_size_file = trailingslashit( $dir ) . $size['file'];
					if ( file_exists( $old_size_file ) ) {
						@unlink( $old_size_file );
					}
				}
			}

			$new_metadata = wp_generate_attachment_metadata( $attachment_id, $new_file );
			if ( ! is_wp_error( $new_metadata ) && $new_metadata ) {
				wp_update_attachment_metadata( $attachment_id, $new_metadata );
			}
		} else {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( $meta ) {
				$meta['width']  = $saved['width'];
				$meta['height'] = $saved['height'];
				wp_update_attachment_metadata( $attachment_id, $meta );
			}
		}

		clearstatcache();
		$after_size = file_exists( $new_file ) ? filesize( $new_file ) : $before_size;

		update_post_meta( $attachment_id, '_imgtk_optimized', time() );
		update_post_meta( $attachment_id, '_imgtk_before_size', $before_size );
		update_post_meta( $attachment_id, '_imgtk_after_size', $after_size );

		return array(
			'success'        => true,
			'attachment_id'  => $attachment_id,
			'before_size'    => $before_size,
			'after_size'     => $after_size,
			'saved_bytes'    => max( 0, $before_size - $after_size ),
			'saved_percent'  => $before_size > 0 ? round( ( ( $before_size - $after_size ) / $before_size ) * 100, 1 ) : 0,
			'mime'           => $target_mime,
		);
	}

	/**
	 * Restore an attachment's original file from its backup copy, undoing
	 * whatever resize/compress/convert was previously applied.
	 *
	 * @return true|WP_Error
	 */
	public static function restore( $attachment_id ) {
		$backup_relative = get_post_meta( $attachment_id, '_imgtk_backup_path', true );
		if ( ! $backup_relative ) {
			return new WP_Error( 'imgtk_no_backup', __( 'No backup was saved for this image.', 'image-toolkit' ) );
		}

		$upload_dir  = wp_upload_dir();
		$backup_path = trailingslashit( $upload_dir['basedir'] ) . $backup_relative;
		if ( ! file_exists( $backup_path ) ) {
			return new WP_Error( 'imgtk_backup_missing', __( 'The backup file is missing on disk.', 'image-toolkit' ) );
		}

		$current_file = get_attached_file( $attachment_id );
		$backup_ext   = strtolower( pathinfo( $backup_path, PATHINFO_EXTENSION ) );
		$current_ext  = strtolower( pathinfo( $current_file, PATHINFO_EXTENSION ) );

		$target_file = $current_file;
		if ( $backup_ext !== $current_ext ) {
			$target_file = preg_replace( '/\.' . preg_quote( $current_ext, '/' ) . '$/i', '.' . $backup_ext, $current_file );
			if ( file_exists( $current_file ) ) {
				@unlink( $current_file );
			}
		}

		if ( ! copy( $backup_path, $target_file ) ) {
			return new WP_Error( 'imgtk_restore_failed', __( 'Could not copy the backup file back into place.', 'image-toolkit' ) );
		}

		update_attached_file( $attachment_id, $target_file );

		$ext_to_mime = array_flip( self::MIME_TO_EXT );
		$new_mime    = $ext_to_mime[ $backup_ext ] ?? get_post_mime_type( $attachment_id );
		wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $new_mime ) );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $target_file );
		if ( ! is_wp_error( $metadata ) && $metadata ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		delete_post_meta( $attachment_id, '_imgtk_optimized' );
		delete_post_meta( $attachment_id, '_imgtk_before_size' );
		delete_post_meta( $attachment_id, '_imgtk_after_size' );

		return true;
	}

	private static function create_backup( $attachment_id, $file ) {
		$upload_dir  = wp_upload_dir();
		$backup_dir  = trailingslashit( $upload_dir['basedir'] ) . IMGTK_BACKUP_DIRNAME . '/' . $attachment_id;

		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		$dest = trailingslashit( $backup_dir ) . basename( $file );
		if ( ! file_exists( $dest ) ) {
			if ( ! copy( $file, $dest ) ) {
				return false;
			}
		}

		return ltrim( str_replace( trailingslashit( $upload_dir['basedir'] ), '', $dest ), '/\\' );
	}
}
