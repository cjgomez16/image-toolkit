<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Figures out whether an attachment is referenced anywhere on the site:
 * post content (classic + block editor), featured images, custom fields
 * (ACF, Elementor, WooCommerce galleries, etc. all live in postmeta),
 * theme mods / widgets / customizer settings (options table), term meta,
 * and user meta (custom avatars). Results are cached on the attachment
 * so re-rendering the Unused Images list doesn't re-run every query.
 */
class IMGTK_Usage_Scanner {

	const MAX_LOCATIONS_SHOWN = 10;

	public static function scan( $attachment_id ) {
		global $wpdb;

		$locations = array();

		$file     = get_attached_file( $attachment_id );
		$filename = $file ? pathinfo( $file, PATHINFO_FILENAME ) : '';

		$like_id       = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%';
		$like_block_id = '%' . $wpdb->esc_like( '"id":' . $attachment_id . ',' ) . '%';
		$like_filename = $filename ? '%' . $wpdb->esc_like( $filename ) . '%' : null;

		// 1. Featured image.
		$featured = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
			$attachment_id
		) );
		foreach ( $featured as $pid ) {
			$locations[] = sprintf(
				/* translators: %s: post title */
				__( 'Featured image of "%s"', 'image-toolkit' ),
				get_the_title( $pid ) ?: ( '#' . $pid )
			);
		}

		// 2. Attached to a post (uploaded directly into that post).
		$post = get_post( $attachment_id );
		if ( $post && $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( $parent ) {
				$locations[] = sprintf(
					/* translators: %s: parent post title */
					__( 'Attached to "%s"', 'image-toolkit' ),
					get_the_title( $parent ) ?: ( '#' . $parent->ID )
				);
			}
		}

		// 3. Post content (classic <img class="wp-image-ID">, Gutenberg block JSON, or a raw filename match).
		$content_clauses = array( 'post_content LIKE %s' );
		$content_params   = array( $like_id );
		$content_clauses[] = 'post_content LIKE %s';
		$content_params[]  = $like_block_id;
		if ( $like_filename ) {
			$content_clauses[] = 'post_content LIKE %s';
			$content_params[]  = $like_filename;
		}

		$sql = "SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_status NOT IN ('trash','auto-draft')
				AND (" . implode( ' OR ', $content_clauses ) . ')
				LIMIT 20';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $content_params ) );
		foreach ( $rows as $row ) {
			$locations[] = sprintf(
				/* translators: %s: post title */
				__( 'Referenced in content of "%s"', 'image-toolkit' ),
				$row->post_title ?: ( '#' . $row->ID )
			);
		}

		// 4. Postmeta (ACF fields, page builder data, product galleries, etc.) excluding the attachment's own rows.
		$meta_clauses = array( 'meta_value LIKE %s' );
		$meta_params  = array( $attachment_id, $like_id );
		if ( $like_filename ) {
			$meta_clauses[] = 'meta_value LIKE %s';
			$meta_params[]  = $like_filename;
		}
		$sql = "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE post_id != %d AND (" . implode( ' OR ', $meta_clauses ) . ') LIMIT 20';
		$meta_rows = $wpdb->get_col( $wpdb->prepare( $sql, $meta_params ) );
		foreach ( $meta_rows as $pid ) {
			$locations[] = sprintf(
				/* translators: %s: post title */
				__( 'Referenced in a custom field on "%s"', 'image-toolkit' ),
				get_the_title( $pid ) ?: ( '#' . $pid )
			);
		}

		// 5. Options table (theme mods, customizer logo/background/site icon, text & media widgets, ACF options pages).
		$opt_clauses = array( 'option_value LIKE %s' );
		$opt_params  = array( $like_id );
		if ( $like_filename ) {
			$opt_clauses[] = 'option_value LIKE %s';
			$opt_params[]  = $like_filename;
		}
		$sql = "SELECT option_name FROM {$wpdb->options}
				WHERE (" . implode( ' OR ', $opt_clauses ) . ') LIMIT 20';
		$opt_rows = $wpdb->get_col( $wpdb->prepare( $sql, $opt_params ) );
		foreach ( $opt_rows as $opt_name ) {
			$locations[] = sprintf(
				/* translators: %s: option name */
				__( 'Referenced in site option "%s"', 'image-toolkit' ),
				$opt_name
			);
		}

		// 6. Term meta (category/tag thumbnails).
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->termmeta}'" ) ) {
			$term_clauses = array( 'meta_value LIKE %s' );
			$term_params  = array( $like_id );
			if ( $like_filename ) {
				$term_clauses[] = 'meta_value LIKE %s';
				$term_params[]  = $like_filename;
			}
			$sql = "SELECT DISTINCT term_id FROM {$wpdb->termmeta}
					WHERE (" . implode( ' OR ', $term_clauses ) . ') LIMIT 20';
			$term_rows = $wpdb->get_col( $wpdb->prepare( $sql, $term_params ) );
			foreach ( $term_rows as $tid ) {
				$term = get_term( $tid );
				$locations[] = sprintf(
					/* translators: %s: term name */
					__( 'Referenced on term "%s"', 'image-toolkit' ),
					( $term && ! is_wp_error( $term ) ) ? $term->name : ( '#' . $tid )
				);
			}
		}

		// 7. User meta (custom avatar / profile image plugins).
		$user_clauses = array( 'meta_value LIKE %s' );
		$user_params  = array( $like_id );
		if ( $like_filename ) {
			$user_clauses[] = 'meta_value LIKE %s';
			$user_params[]  = $like_filename;
		}
		$sql = "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
				WHERE (" . implode( ' OR ', $user_clauses ) . ') LIMIT 20';
		$user_rows = $wpdb->get_col( $wpdb->prepare( $sql, $user_params ) );
		foreach ( $user_rows as $uid ) {
			$user = get_userdata( $uid );
			$locations[] = sprintf(
				/* translators: %s: user display name */
				__( 'Referenced in profile of "%s"', 'image-toolkit' ),
				$user ? $user->display_name : ( '#' . $uid )
			);
		}

		/**
		 * Let other plugins contribute additional usage checks (or remove
		 * false positives) before the final used/unused verdict is cached.
		 */
		$locations = apply_filters( 'imgtk_usage_locations', $locations, $attachment_id );
		$locations = array_values( array_unique( array_filter( (array) $locations ) ) );

		$status = empty( $locations ) ? 'unused' : 'used';

		update_post_meta( $attachment_id, '_imgtk_usage_status', $status );
		update_post_meta( $attachment_id, '_imgtk_usage_locations', array_slice( $locations, 0, self::MAX_LOCATIONS_SHOWN ) );
		update_post_meta( $attachment_id, '_imgtk_last_scanned', time() );

		return array(
			'status'    => $status,
			'locations' => $locations,
		);
	}
}
