/* global jQuery, IMGTK */
( function ( $ ) {
	'use strict';

	function post( action, data ) {
		return $.post( IMGTK.ajaxUrl, $.extend( { action: action, nonce: IMGTK.nonce }, data ) );
	}

	/* -----------------------------------------------------------------
	 * Sequential batch runner shared by Bulk Optimize and Unused scan.
	 * Processes one attachment ID at a time via AJAX so long-running
	 * jobs never hit a PHP execution timeout, and progress is visible
	 * (and cancellable) the whole way through.
	 * ------------------------------------------------------------- */
	function runQueue( ids, processOne, onTick, onDone ) {
		var i = 0;
		var cancelled = false;
		var total = ids.length;

		function next() {
			if ( cancelled || i >= total ) {
				onDone( cancelled );
				return;
			}
			var id = ids[ i ];
			i++;
			processOne( id )
				.always( function ( response ) {
					onTick( i, total, response );
					next();
				} );
		}

		next();

		return {
			cancel: function () {
				cancelled = true;
			},
		};
	}

	/* ----------------------------- Bulk Optimize ----------------------------- */

	var bulkRunner = null;

	$( '#imgtk-start-bulk' ).on( 'click', function () {
		var $start = $( this );
		var $cancel = $( '#imgtk-cancel-bulk' );
		var $wrap = $( '#imgtk-progress-wrap' );
		var $fill = $( '#imgtk-progress-fill' );
		var $text = $( '#imgtk-progress-text' );
		var $errors = $( '#imgtk-error-list' );

		var onlyUnoptimized = $( '#imgtk-only-unoptimized' ).is( ':checked' );

		$start.prop( 'disabled', true );
		$cancel.show();
		$wrap.show();
		$errors.empty();
		$text.text( IMGTK.i18n.processing );

		post( 'imgtk_get_optimize_queue', { only_unoptimized: onlyUnoptimized ? 1 : 0 } ).done( function ( resp ) {
			if ( ! resp.success ) {
				$text.text( IMGTK.i18n.error );
				return;
			}
			var ids = resp.data.ids;
			if ( ! ids.length ) {
				$text.text( IMGTK.i18n.done + ' (0)' );
				$start.prop( 'disabled', false );
				$cancel.hide();
				return;
			}

			var savedBytes = 0;
			var errorCount = 0;

			bulkRunner = runQueue(
				ids,
				function ( id ) {
					return post( 'imgtk_process_one', { id: id } );
				},
				function ( done, total, response ) {
					var pct = Math.round( ( done / total ) * 100 );
					$fill.css( 'width', pct + '%' );

					if ( response && response.success ) {
						savedBytes += response.data.saved_bytes || 0;
						$text.text( done + ' / ' + total + ' — ' + humanSize( savedBytes ) + ' saved so far' );
					} else if ( response && response.data && ! response.data.skipped ) {
						errorCount++;
						var title = ( response.data.title || ( 'ID ' + response.data.id ) );
						$errors.append( $( '<li>' ).text( title + ': ' + response.data.message ) );
						$text.text( done + ' / ' + total + ' — ' + humanSize( savedBytes ) + ' saved, ' + errorCount + ' error(s)' );
					} else {
						$text.text( done + ' / ' + total + ' — ' + humanSize( savedBytes ) + ' saved so far' );
					}
				},
				function ( cancelled ) {
					$start.prop( 'disabled', false );
					$cancel.hide();
					$text.text( ( cancelled ? 'Cancelled. ' : IMGTK.i18n.done + ' ' ) + humanSize( savedBytes ) + ' saved, ' + errorCount + ' error(s).' );
				}
			);
		} );
	} );

	$( '#imgtk-cancel-bulk' ).on( 'click', function () {
		if ( bulkRunner ) {
			bulkRunner.cancel();
		}
	} );

	/* ----------------------------- Unused Images scan ----------------------------- */

	var scanRunner = null;

	$( '#imgtk-start-scan' ).on( 'click', function () {
		var $start = $( this );
		var $cancel = $( '#imgtk-cancel-scan' );
		var $wrap = $( '#imgtk-scan-progress-wrap' );
		var $fill = $( '#imgtk-scan-progress-fill' );
		var $text = $( '#imgtk-scan-progress-text' );

		var includeScanned = $( '#imgtk-rescan-all' ).is( ':checked' );

		$start.prop( 'disabled', true );
		$cancel.show();
		$wrap.show();
		$text.text( IMGTK.i18n.scanning );

		post( 'imgtk_get_scan_queue', { include_scanned: includeScanned ? 1 : 0 } ).done( function ( resp ) {
			if ( ! resp.success ) {
				$text.text( IMGTK.i18n.error );
				return;
			}
			var ids = resp.data.ids;
			if ( ! ids.length ) {
				$text.text( IMGTK.i18n.done + ' (0)' );
				$start.prop( 'disabled', false );
				$cancel.hide();
				return;
			}

			scanRunner = runQueue(
				ids,
				function ( id ) {
					return post( 'imgtk_scan_one', { id: id } );
				},
				function ( done, total ) {
					var pct = Math.round( ( done / total ) * 100 );
					$fill.css( 'width', pct + '%' );
					$text.text( done + ' / ' + total );
				},
				function ( cancelled ) {
					$start.prop( 'disabled', false );
					$cancel.hide();
					$text.text( ( cancelled ? 'Cancelled.' : IMGTK.i18n.done ) + ' Reloading results…' );
					window.location.reload();
				}
			);
		} );
	} );

	$( '#imgtk-cancel-scan' ).on( 'click', function () {
		if ( scanRunner ) {
			scanRunner.cancel();
		}
	} );

	/* ----------------------------- Unused Images table ----------------------------- */

	$( '#imgtk-select-all' ).on( 'change', function () {
		$( '.imgtk-row-checkbox' ).prop( 'checked', $( this ).is( ':checked' ) );
	} );

	$( '#imgtk-trash-selected' ).on( 'click', function () {
		var ids = $( '.imgtk-row-checkbox:checked' ).map( function () {
			return $( this ).val();
		} ).get();

		if ( ! ids.length ) {
			window.alert( IMGTK.i18n.noneSelected );
			return;
		}
		if ( ! window.confirm( IMGTK.i18n.confirmTrash ) ) {
			return;
		}

		var $btn = $( this ).prop( 'disabled', true );

		post( 'imgtk_trash_unused', { ids: ids } ).done( function ( resp ) {
			if ( resp.success ) {
				resp.data.trashed.forEach( function ( id ) {
					$( 'tr[data-id="' + id + '"]' ).fadeOut( 200, function () {
						$( this ).remove();
					} );
				} );
			}
			$btn.prop( 'disabled', false );
		} );
	} );

	/* ----------------------------- Media Library row actions ----------------------------- */

	$( document ).on( 'click', '.imgtk-row-optimize', function ( e ) {
		e.preventDefault();
		var $link = $( this );
		var id = $link.data( 'id' );
		$link.text( IMGTK.i18n.processing );

		post( 'imgtk_process_one', { id: id } ).done( function ( resp ) {
			if ( resp.success ) {
				$link.closest( 'tr' ).find( '.column-imgtk_size' ).html(
					humanSize( resp.data.after_size ) + '<br><span class="imgtk-saved">' + '−' + humanSize( resp.data.saved_bytes ) + ' optimized</span>'
				);
				$link.text( 'Optimized' );
			} else {
				$link.text( IMGTK.i18n.error );
			}
		} );
	} );

	$( document ).on( 'click', '.imgtk-row-scan', function ( e ) {
		e.preventDefault();
		var $link = $( this );
		var id = $link.data( 'id' );
		$link.text( IMGTK.i18n.scanning );

		post( 'imgtk_scan_one', { id: id } ).done( function ( resp ) {
			if ( resp.success ) {
				var badgeClass = resp.data.status === 'used' ? 'imgtk-badge-used' : 'imgtk-badge-unused';
				var label = resp.data.status === 'used' ? 'Used' : 'Unused';
				$link.closest( 'tr' ).find( '.column-imgtk_usage' ).html( '<span class="imgtk-badge ' + badgeClass + '">' + label + '</span>' );
				$link.text( 'Rescan usage' );
			} else {
				$link.text( IMGTK.i18n.error );
			}
		} );
	} );

	function humanSize( bytes ) {
		bytes = parseInt( bytes, 10 ) || 0;
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		var units = [ 'KB', 'MB', 'GB' ];
		var value = bytes / 1024;
		var unit = 0;
		while ( value >= 1024 && unit < units.length - 1 ) {
			value /= 1024;
			unit++;
		}
		return value.toFixed( 1 ) + ' ' + units[ unit ];
	}
} )( jQuery );
