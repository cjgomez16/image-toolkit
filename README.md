# Image Toolkit

Internal WordPress plugin for sites that deal with a lot of images. It resizes, compresses, and converts Media Library images in bulk, and finds images that aren't referenced anywhere on the site so they can be cleaned up.

## Features

- **Resize** — caps originals to a max width/height (never upscales).
- **Compress** — adjustable quality for lossy formats.
- **Convert** — WebP, AVIF, JPEG, or PNG, via WordPress's built-in `wp_get_image_editor()` (GD or Imagick, whichever the server has).
- **Bulk Optimize** — runs client-side, one image per AJAX call, so it doesn't hit PHP execution timeouts on large libraries, and can be cancelled mid-run.
- **Unused Images scan** — checks post content, featured images, postmeta (ACF, page builders, WooCommerce galleries, etc.), options (theme mods, customizer, widgets), term meta, and user meta for references to each attachment, then lists anything unreferenced with a bulk "Move to Trash" action.
- **Media Library integration** — File Size / Usage columns, per-row "Optimize now" / "Rescan usage" actions, bulk actions.
- **Backups** — the original file is copied to `uploads/image-toolkit-backups/` before its first optimization, with a restore path.
- **WP-CLI** — `wp image-toolkit optimize|scan|unused`, useful for very large libraries best run over SSH/cron instead of a browser tab.

## Installation

1. Copy (or clone) this repo into `wp-content/plugins/image-toolkit`.
2. Activate **Image Toolkit** from the Plugins screen.
3. Configure quality/format/size limits under **Image Toolkit → Settings** before running a bulk job.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- GD or Imagick (whichever your host provides — WebP/AVIF output depends on the installed build supporting those formats)

## Safety notes

- "Move to Trash" uses WordPress's native attachment trash (`wp_trash_post`) — files stay on disk and are recoverable from the Media Library trash until it empties (default 30 days).
- Usage detection is a best-effort database search. It won't catch images referenced only from external CSS/JS, hand-written stylesheets, or systems outside WordPress (e.g. a CDN rewrite layer). Review the "Unused" list before trashing anything you're not sure about.
- Format conversion changes the file's extension and mime type; anything outside WordPress linking to the old URL directly will break unless the original format is kept.
- Operates on local files via `get_attached_file()` / `wp_get_image_editor()`. On sites using an offload-to-S3/CDN plugin that intercepts those functions, test on staging first.
- Uninstalling the plugin does **not** delete images, backups, or the backups folder by default — only its own settings/tracking postmeta, and only if "Delete data on uninstall" is checked in Settings.

## Repo layout

```
image-toolkit.php                       Plugin bootstrap
includes/class-imgtk-settings.php       Settings storage + sanitization
includes/class-imgtk-processor.php      Resize/compress/convert + backup/restore
includes/class-imgtk-usage-scanner.php  Site-wide usage detection
includes/class-imgtk-admin.php          Admin menu, pages, Media Library integration
includes/class-imgtk-ajax.php           AJAX endpoints for the batch UI
includes/class-imgtk-cli.php            WP-CLI commands
assets/                                 Admin CSS/JS
uninstall.php                           Opt-in cleanup on uninstall
```

## License

GPLv2 or later.
