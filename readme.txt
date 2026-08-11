=== Image Toolkit ===
Contributors: internal
Tags: images, media library, compression, webp, cleanup
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Internal tool for resizing, compressing, and converting Media Library images in bulk, and for finding + cleaning up images that aren't used anywhere on the site.

== Description ==

Image Toolkit adds an admin area for managing media on image-heavy WordPress sites:

* **Resize** originals down to a maximum width/height (never upscales).
* **Compress** with an adjustable quality setting.
* **Convert** to WebP or AVIF (or force JPEG/PNG) using WordPress's built-in image editor abstraction (GD or Imagick, whichever the server has).
* **Bulk Optimize** runs in the browser one image at a time via AJAX, so it doesn't hit PHP execution timeouts on large libraries, and can be cancelled mid-run.
* **Unused Images** scans post content, featured images, custom fields (ACF, page builders, WooCommerce galleries, etc.), theme mods/customizer/widget options, term meta, and user meta to flag attachments that aren't referenced anywhere, with a bulk "Move to Trash" action.
* **Backups**: before the first optimization of a file, the original is copied to `wp-content/uploads/image-toolkit-backups/`, with a "Restore" path available via `IMGTK_Processor::restore()` / the WP-CLI command.
* **Media Library integration**: new "File Size" and "Usage" columns, plus per-row "Optimize now" / "Rescan usage" actions and bulk actions.
* **WP-CLI**: `wp image-toolkit optimize|scan|unused` for large libraries best run over SSH/cron.

== Safety notes ==

* Moving images to Trash uses WordPress's native attachment trash (`wp_trash_post`) — files stay on disk and are recoverable from the Media Library until the trash empties (default 30 days), not deleted immediately.
* Usage detection is a best-effort text/serialized-data search of the database. It will **not** find images referenced only from hand-written CSS/JS, external stylesheets, or systems outside WordPress (e.g. a CDN rewrite layer). Review the "Unused" list before trashing anything you're not sure about.
* Format conversion changes the attachment's file extension and mime type; anything outside WordPress that links to the old URL directly (bypassing `wp_get_attachment_url()`) will break unless you keep the old format.
* This plugin operates on local files via `get_attached_file()` / `wp_get_image_editor()`. On sites using an offload-to-S3/CDN plugin that intercepts those functions, test on staging first.
* Uninstalling the plugin does **not** delete images, backups, or the backups folder by default. Only the plugin's own settings/tracking postmeta are removed, and only if "Delete data on uninstall" is checked in Settings.

== Installation ==

1. Upload the `image-toolkit` folder to `/wp-content/plugins/`.
2. Activate through the *Plugins* screen.
3. Go to *Image Toolkit → Settings* to configure quality/format/size limits before running a bulk job.

== Changelog ==

= 1.0.0 =
* Initial release.
