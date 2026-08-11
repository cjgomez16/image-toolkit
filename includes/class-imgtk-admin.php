<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMGTK_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_filter( 'manage_media_columns', array( $this, 'media_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );

		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'media_row_actions', array( $this, 'row_actions' ), 10, 2 );

		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/* ---------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------- */

	public function menu() {
		add_menu_page(
			__( 'Image Toolkit', 'image-toolkit' ),
			__( 'Image Toolkit', 'image-toolkit' ),
			IMGTK_CAP,
			'image-toolkit',
			array( $this, 'render_dashboard' ),
			'dashicons-format-image',
			65
		);

		add_submenu_page( 'image-toolkit', __( 'Dashboard', 'image-toolkit' ), __( 'Dashboard', 'image-toolkit' ), IMGTK_CAP, 'image-toolkit', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'image-toolkit', __( 'Bulk Optimize', 'image-toolkit' ), __( 'Bulk Optimize', 'image-toolkit' ), IMGTK_CAP, 'image-toolkit-bulk', array( $this, 'render_bulk' ) );
		add_submenu_page( 'image-toolkit', __( 'Unused Images', 'image-toolkit' ), __( 'Unused Images', 'image-toolkit' ), IMGTK_CAP, 'image-toolkit-unused', array( $this, 'render_unused' ) );
		add_submenu_page( 'image-toolkit', __( 'Settings', 'image-toolkit' ), __( 'Settings', 'image-toolkit' ), IMGTK_CAP, 'image-toolkit-settings', array( $this, 'render_settings' ) );
	}

	public function assets( $hook ) {
		$is_our_page   = isset( $_GET['page'] ) && 0 === strpos( (string) $_GET['page'], 'image-toolkit' );
		$is_media_page = in_array( $hook, array( 'upload.php' ), true );

		if ( ! $is_our_page && ! $is_media_page ) {
			return;
		}

		wp_enqueue_style( 'imgtk-admin', IMGTK_URL . 'assets/css/admin.css', array(), IMGTK_VERSION );
		wp_enqueue_script( 'imgtk-admin', IMGTK_URL . 'assets/js/admin.js', array( 'jquery' ), IMGTK_VERSION, true );

		wp_localize_script( 'imgtk-admin', 'IMGTK', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'imgtk_nonce' ),
			'i18n'    => array(
				'confirmTrash'  => __( 'Move the selected images to Trash? They can be restored from the Media Library trash before it empties.', 'image-toolkit' ),
				'processing'    => __( 'Processing…', 'image-toolkit' ),
				'scanning'      => __( 'Scanning…', 'image-toolkit' ),
				'done'          => __( 'Done.', 'image-toolkit' ),
				'error'         => __( 'Error', 'image-toolkit' ),
				'noneSelected'  => __( 'Select at least one image first.', 'image-toolkit' ),
			),
		) );
	}

	/* ---------------------------------------------------------------
	 * Media Library integration
	 * ------------------------------------------------------------- */

	public function media_columns( $columns ) {
		$columns['imgtk_size']  = __( 'File Size', 'image-toolkit' );
		$columns['imgtk_usage'] = __( 'Usage', 'image-toolkit' );
		return $columns;
	}

	public function media_column_content( $column_name, $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		if ( 'imgtk_size' === $column_name ) {
			$file = get_attached_file( $attachment_id );
			$size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
			echo esc_html( size_format( $size, 1 ) );

			$saved = (int) get_post_meta( $attachment_id, '_imgtk_before_size', true ) - (int) get_post_meta( $attachment_id, '_imgtk_after_size', true );
			if ( $saved > 0 ) {
				echo '<br><span class="imgtk-saved">' . esc_html( sprintf( __( '−%s optimized', 'image-toolkit' ), size_format( $saved, 1 ) ) ) . '</span>';
			}
		}

		if ( 'imgtk_usage' === $column_name ) {
			$status = get_post_meta( $attachment_id, '_imgtk_usage_status', true );
			if ( '' === $status ) {
				echo '<span class="imgtk-badge imgtk-badge-unknown">' . esc_html__( 'Not scanned', 'image-toolkit' ) . '</span>';
			} elseif ( 'used' === $status ) {
				echo '<span class="imgtk-badge imgtk-badge-used">' . esc_html__( 'Used', 'image-toolkit' ) . '</span>';
			} else {
				echo '<span class="imgtk-badge imgtk-badge-unused">' . esc_html__( 'Unused', 'image-toolkit' ) . '</span>';
			}
		}
	}

	public function bulk_actions( $actions ) {
		$actions['imgtk_optimize']   = __( 'Optimize images (Image Toolkit)', 'image-toolkit' );
		$actions['imgtk_scan_usage'] = __( 'Scan usage (Image Toolkit)', 'image-toolkit' );
		return $actions;
	}

	public function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
		if ( ! in_array( $doaction, array( 'imgtk_optimize', 'imgtk_scan_usage' ), true ) ) {
			return $redirect_to;
		}
		if ( ! current_user_can( IMGTK_CAP ) ) {
			return $redirect_to;
		}

		$count = 0;
		foreach ( $post_ids as $id ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			if ( 'imgtk_optimize' === $doaction ) {
				$result = IMGTK_Processor::process( $id );
				if ( ! is_wp_error( $result ) ) {
					$count++;
				}
			} else {
				IMGTK_Usage_Scanner::scan( $id );
				$count++;
			}
		}

		$redirect_to = add_query_arg( array(
			'imgtk_bulk_action' => $doaction,
			'imgtk_bulk_count'  => $count,
		), $redirect_to );

		return $redirect_to;
	}

	public function admin_notices() {
		if ( empty( $_GET['imgtk_bulk_action'] ) ) {
			return;
		}
		$count  = isset( $_GET['imgtk_bulk_count'] ) ? (int) $_GET['imgtk_bulk_count'] : 0;
		$action = sanitize_text_field( wp_unslash( $_GET['imgtk_bulk_action'] ) );

		$message = ( 'imgtk_optimize' === $action )
			? sprintf( _n( 'Optimized %d image.', 'Optimized %d images.', $count, 'image-toolkit' ), $count )
			: sprintf( _n( 'Scanned %d image.', 'Scanned %d images.', $count, 'image-toolkit' ), $count );

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	public function row_actions( $actions, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! current_user_can( IMGTK_CAP ) ) {
			return $actions;
		}
		$actions['imgtk_optimize'] = '<a href="#" class="imgtk-row-optimize" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Optimize now', 'image-toolkit' ) . '</a>';
		$actions['imgtk_scan']     = '<a href="#" class="imgtk-row-scan" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Rescan usage', 'image-toolkit' ) . '</a>';
		return $actions;
	}

	/* ---------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------- */

	private function nav( $active ) {
		$tabs = array(
			'image-toolkit'          => __( 'Dashboard', 'image-toolkit' ),
			'image-toolkit-bulk'     => __( 'Bulk Optimize', 'image-toolkit' ),
			'image-toolkit-unused'   => __( 'Unused Images', 'image-toolkit' ),
			'image-toolkit-settings' => __( 'Settings', 'image-toolkit' ),
		);
		echo '<h1>' . esc_html__( 'Image Toolkit', 'image-toolkit' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$class = ( $slug === $active ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf( '<a class="%s" href="%s">%s</a>', esc_attr( $class ), esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $label ) );
		}
		echo '</nav>';
	}

	public function render_dashboard() {
		global $wpdb;

		$total_images = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' AND post_status != 'trash'" );
		$optimized    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_imgtk_optimized'" );
		$scanned      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_imgtk_usage_status'" );
		$unused       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_imgtk_usage_status' AND meta_value='unused'" );

		$saved_bytes = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(b.meta_value - a.meta_value), 0)
			 FROM {$wpdb->postmeta} b
			 JOIN {$wpdb->postmeta} a ON a.post_id = b.post_id AND a.meta_key = '_imgtk_after_size'
			 WHERE b.meta_key = '_imgtk_before_size' AND CAST(b.meta_value AS SIGNED) > CAST(a.meta_value AS SIGNED)"
		);

		echo '<div class="wrap imgtk-wrap">';
		$this->nav( 'image-toolkit' );
		?>
		<div class="imgtk-cards">
			<div class="imgtk-card">
				<span class="imgtk-card-number"><?php echo esc_html( number_format_i18n( $total_images ) ); ?></span>
				<span class="imgtk-card-label"><?php esc_html_e( 'Images in Media Library', 'image-toolkit' ); ?></span>
			</div>
			<div class="imgtk-card">
				<span class="imgtk-card-number"><?php echo esc_html( number_format_i18n( $optimized ) ); ?></span>
				<span class="imgtk-card-label"><?php esc_html_e( 'Optimized so far', 'image-toolkit' ); ?></span>
			</div>
			<div class="imgtk-card">
				<span class="imgtk-card-number"><?php echo esc_html( size_format( $saved_bytes, 1 ) ); ?></span>
				<span class="imgtk-card-label"><?php esc_html_e( 'Disk space reclaimed', 'image-toolkit' ); ?></span>
			</div>
			<div class="imgtk-card">
				<span class="imgtk-card-number"><?php echo esc_html( number_format_i18n( $unused ) ); ?></span>
				<span class="imgtk-card-label"><?php esc_html_e( 'Unused images found', 'image-toolkit' ); ?></span>
			</div>
		</div>

		<p>
			<?php
			printf(
				/* translators: 1: scanned count, 2: total count */
				esc_html__( '%1$s of %2$s images have been scanned for usage.', 'image-toolkit' ),
				esc_html( number_format_i18n( $scanned ) ),
				esc_html( number_format_i18n( $total_images ) )
			);
			?>
		</p>

		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=image-toolkit-bulk' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Run Bulk Optimize', 'image-toolkit' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=image-toolkit-unused' ) ); ?>" class="button"><?php esc_html_e( 'Find Unused Images', 'image-toolkit' ); ?></a>
		</p>
		<?php
		echo '</div>';
	}

	public function render_bulk() {
		$settings = IMGTK_Settings::get();
		echo '<div class="wrap imgtk-wrap">';
		$this->nav( 'image-toolkit-bulk' );
		?>
		<p><?php esc_html_e( 'Runs entirely in your browser tab, one image at a time, so it will not time out on large libraries. You can leave this page open or cancel at any point — anything already processed stays processed.', 'image-toolkit' ); ?></p>

		<div class="imgtk-box">
			<label>
				<input type="checkbox" id="imgtk-only-unoptimized" <?php checked( $settings['skip_already_optimized'] ); ?> />
				<?php esc_html_e( 'Skip images already optimized by Image Toolkit', 'image-toolkit' ); ?>
			</label>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to settings page */
					esc_html__( 'Uses the format, quality, and size limits configured in %s.', 'image-toolkit' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=image-toolkit-settings' ) ) . '">' . esc_html__( 'Settings', 'image-toolkit' ) . '</a>'
				);
				?>
			</p>
		</div>

		<p>
			<button id="imgtk-start-bulk" class="button button-primary"><?php esc_html_e( 'Start Bulk Optimize', 'image-toolkit' ); ?></button>
			<button id="imgtk-cancel-bulk" class="button" style="display:none"><?php esc_html_e( 'Cancel', 'image-toolkit' ); ?></button>
		</p>

		<div id="imgtk-progress-wrap" style="display:none">
			<div class="imgtk-progress-bar"><div id="imgtk-progress-fill" class="imgtk-progress-fill"></div></div>
			<p id="imgtk-progress-text"></p>
			<ul id="imgtk-error-list"></ul>
		</div>
		<?php
		echo '</div>';
	}

	public function render_unused() {
		global $wpdb;

		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page = 40;
		$offset   = ( $paged - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_imgtk_usage_status' AND meta_value='unused'" );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_imgtk_usage_status' AND meta_value='unused' ORDER BY post_id DESC LIMIT %d OFFSET %d",
			$per_page, $offset
		) );

		echo '<div class="wrap imgtk-wrap">';
		$this->nav( 'image-toolkit-unused' );
		?>
		<p><?php esc_html_e( 'Scans the Media Library against post content, custom fields, theme/customizer options, term meta, and user meta to find images that are not referenced anywhere. Always double-check anything ambiguous before deleting — images used only via a hardcoded URL in a hard-to-search location (e.g. an external stylesheet) will not be detected.', 'image-toolkit' ); ?></p>

		<p>
			<button id="imgtk-start-scan" class="button button-primary"><?php esc_html_e( 'Scan Media Library for Unused Images', 'image-toolkit' ); ?></button>
			<label style="margin-left:12px">
				<input type="checkbox" id="imgtk-rescan-all" />
				<?php esc_html_e( 'Rescan images already scanned', 'image-toolkit' ); ?>
			</label>
			<button id="imgtk-cancel-scan" class="button" style="display:none"><?php esc_html_e( 'Cancel', 'image-toolkit' ); ?></button>
		</p>

		<div id="imgtk-scan-progress-wrap" style="display:none">
			<div class="imgtk-progress-bar"><div id="imgtk-scan-progress-fill" class="imgtk-progress-fill"></div></div>
			<p id="imgtk-scan-progress-text"></p>
		</div>

		<h2>
			<?php
			printf(
				/* translators: %d: number of unused images found */
				esc_html__( 'Unused images (%d)', 'image-toolkit' ),
				(int) $total
			);
			?>
		</h2>

		<?php if ( $ids ) : ?>
			<p>
				<button id="imgtk-trash-selected" class="button"><?php esc_html_e( 'Move Selected to Trash', 'image-toolkit' ); ?></button>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="imgtk-select-all" /></td>
						<th><?php esc_html_e( 'Image', 'image-toolkit' ); ?></th>
						<th><?php esc_html_e( 'File', 'image-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Size', 'image-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Uploaded', 'image-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Last scanned', 'image-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody id="imgtk-unused-tbody">
					<?php foreach ( $ids as $id ) : ?>
						<?php
						$file      = get_attached_file( $id );
						$size      = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
						$scanned   = (int) get_post_meta( $id, '_imgtk_last_scanned', true );
						?>
						<tr data-id="<?php echo esc_attr( $id ); ?>">
							<th class="check-column"><input type="checkbox" class="imgtk-row-checkbox" value="<?php echo esc_attr( $id ); ?>" /></th>
							<td><?php echo wp_get_attachment_image( $id, array( 60, 60 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( basename( (string) $file ) ); ?></a>
							</td>
							<td><?php echo esc_html( size_format( $size, 1 ) ); ?></td>
							<td><?php echo esc_html( get_the_date( '', $id ) ); ?></td>
							<td><?php echo $scanned ? esc_html( human_time_diff( $scanned ) . ' ' . __( 'ago', 'image-toolkit' ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post( paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
				) ) );
				echo '</div></div>';
			}
			?>
		<?php else : ?>
			<p><?php esc_html_e( 'No unused images found yet. Run a scan above.', 'image-toolkit' ); ?></p>
		<?php endif; ?>
		<?php
		echo '</div>';
	}

	public function render_settings() {
		$s = IMGTK_Settings::get();
		echo '<div class="wrap imgtk-wrap">';
		$this->nav( 'image-toolkit-settings' );

		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'image-toolkit' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="imgtk_save_settings" />
			<?php wp_nonce_field( 'imgtk_save_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="imgtk_convert_format"><?php esc_html_e( 'Convert format', 'image-toolkit' ); ?></label></th>
					<td>
						<select name="imgtk_settings[convert_format]" id="imgtk_convert_format">
							<option value="" <?php selected( $s['convert_format'], '' ); ?>><?php esc_html_e( 'Keep original format', 'image-toolkit' ); ?></option>
							<option value="webp" <?php selected( $s['convert_format'], 'webp' ); ?>>WebP</option>
							<option value="avif" <?php selected( $s['convert_format'], 'avif' ); ?>>AVIF</option>
							<option value="jpeg" <?php selected( $s['convert_format'], 'jpeg' ); ?>>JPEG</option>
							<option value="png" <?php selected( $s['convert_format'], 'png' ); ?>>PNG</option>
						</select>
						<p class="description"><?php esc_html_e( 'Applies during Bulk Optimize and per-image "Optimize now" actions. AVIF/WebP support depends on the server\'s GD or Imagick build.', 'image-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="imgtk_quality"><?php esc_html_e( 'Compression quality', 'image-toolkit' ); ?></label></th>
					<td>
						<input type="number" min="1" max="100" name="imgtk_settings[quality]" id="imgtk_quality" value="<?php echo esc_attr( $s['quality'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( '1–100. Lower means smaller files but more visible compression artifacts. 80–85 is a good default for JPEG/WebP.', 'image-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Maximum dimensions', 'image-toolkit' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Width', 'image-toolkit' ); ?>
							<input type="number" min="0" name="imgtk_settings[max_width]" value="<?php echo esc_attr( $s['max_width'] ); ?>" class="small-text" />
						</label>
						&nbsp;&nbsp;
						<label><?php esc_html_e( 'Height', 'image-toolkit' ); ?>
							<input type="number" min="0" name="imgtk_settings[max_height]" value="<?php echo esc_attr( $s['max_height'] ); ?>" class="small-text" />
						</label>
						<p class="description"><?php esc_html_e( 'Images larger than this are scaled down (never up). Set to 0 to disable a dimension cap. This resizes the original file — WordPress\'s registered thumbnail sizes are then regenerated from the new original.', 'image-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Regenerate thumbnails', 'image-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="imgtk_settings[regenerate_thumbnails]" value="1" <?php checked( $s['regenerate_thumbnails'] ); ?> /> <?php esc_html_e( 'Regenerate all registered image sizes after processing', 'image-toolkit' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Backups', 'image-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="imgtk_settings[keep_backup]" value="1" <?php checked( $s['keep_backup'] ); ?> /> <?php esc_html_e( 'Keep a copy of the original file before the first optimization, so it can be restored later', 'image-toolkit' ); ?></label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: backup folder path relative to uploads */
								esc_html__( 'Stored under wp-content/uploads/%s/. Not deleted automatically — clear it manually once you\'re confident you won\'t need to restore.', 'image-toolkit' ),
								esc_html( IMGTK_BACKUP_DIRNAME )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Skip processed images', 'image-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="imgtk_settings[skip_already_optimized]" value="1" <?php checked( $s['skip_already_optimized'] ); ?> /> <?php esc_html_e( 'Skip images that Image Toolkit has already optimized during Bulk Optimize runs', 'image-toolkit' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'On uninstall', 'image-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="imgtk_settings[delete_data_on_uninstall]" value="1" <?php checked( $s['delete_data_on_uninstall'] ); ?> /> <?php esc_html_e( 'Delete this plugin\'s settings and stored metadata when it is uninstalled', 'image-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'This never deletes images or backup files — only the plugin\'s own settings/tracking data.', 'image-toolkit' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'image-toolkit' ) ); ?>
		</form>
		<?php
		echo '</div>';
	}
}
