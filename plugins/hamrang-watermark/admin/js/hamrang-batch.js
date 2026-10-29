/* global jQuery, hamrangBatch */
( function ( $ ) {
	'use strict';

	var cfg = window.hamrangBatch || {};
	var i18n = cfg.i18n || {};
	var CHUNK = 4;

	var queue = [];
	var total = 0;
	var done = 0;
	var running = false;
	var stopRequested = false;
	var counts = { applied: 0, restored: 0, skipped: 0, error: 0 };
	var failures = 0;

	var $progress = $( '#hamrang-batch-progress' );
	var $bar = $progress.find( '.hamrang-progress-bar' );
	var $text = $progress.find( '.hamrang-progress-text' );
	var $log = $progress.find( '.hamrang-log' );
	var $start = $( '.hamrang-start' );
	var $stop = $( '.hamrang-stop' );

	function setBusy( busy ) {
		running = busy;
		$start.prop( 'disabled', busy || $start.data( 'locked' ) );
		$stop.prop( 'disabled', ! busy );
		$( 'input[name="hamrang_scope"]' ).prop( 'disabled', busy );
	}

	function updateCounters() {
		$.each( counts, function ( key, value ) {
			$progress.find( '.hamrang-counters .is-' + key + ' strong' ).text( value );
		} );
		var pct = total ? Math.round( ( done / total ) * 100 ) : 0;
		$bar.css( 'width', pct + '%' );
		$text.text( done + ' ' + ( i18n.of || '/' ) + ' ' + total + ' (' + pct + '%)' );
	}

	function logItem( item ) {
		var $li = $( '<li>' ).addClass( 'is-' + item.status );
		if ( item.thumb ) {
			$li.append( $( '<img>', { src: item.thumb, alt: '' } ) );
		}
		var $title = $( '<span class="hamrang-log-title">' ).text( item.title || ( '#' + item.id ) );
		if ( item.link ) {
			$title = $( '<a class="hamrang-log-title" target="_blank" rel="noopener">' ).attr( 'href', item.link ).text( item.title || ( '#' + item.id ) );
		}
		$li.append( $title ).append( $( '<span>' ).text( item.message || item.status ) );
		$log.prepend( $li );
		if ( $log.children().length > 300 ) {
			$log.children().last().remove();
		}
	}

	function step( task ) {
		if ( stopRequested || ! queue.length ) {
			finish( stopRequested ? i18n.stopped : i18n.done );
			return;
		}
		var ids = queue.slice( 0, CHUNK );
		$.post( cfg.ajax, {
			action: 'hamrang_batch_run',
			nonce: cfg.nonce,
			task: task,
			ids: ids
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				failures++;
				if ( failures > 5 ) {
					finish( ( res && res.data && res.data.message ) || i18n.requestError );
					return;
				}
				window.setTimeout( function () { step( task ); }, 1500 );
				return;
			}
			failures = 0;
			var processed = {};
			$.each( res.data.items || [], function ( _, item ) {
				processed[ item.id ] = true;
				counts[ item.status ] = ( counts[ item.status ] || 0 ) + 1;
				done++;
				logItem( item );
			} );
			queue = $.grep( queue, function ( id ) { return ! processed[ id ]; } );
			if ( ! res.data.items || ! res.data.items.length ) {
				// nothing processed (should not happen) — drop the chunk to avoid an endless loop
				queue = queue.slice( ids.length );
				done += ids.length;
			}
			updateCounters();
			step( task );
		} ).fail( function () {
			failures++;
			if ( failures > 5 ) {
				finish( i18n.requestError );
				return;
			}
			window.setTimeout( function () { step( task ); }, 2500 );
		} );
	}

	function finish( message ) {
		setBusy( false );
		updateCounters();
		$text.text( $text.text() + ' — ' + message );
	}

	$start.on( 'click', function () {
		if ( running ) {
			return;
		}
		var task = $( this ).data( 'task' );
		var scope = $( 'input[name="hamrang_scope"]:checked' ).val();
		if ( task === 'restore' && ! window.confirm( i18n.confirmRestore ) ) {
			return;
		}
		if ( task === 'force' && ! window.confirm( i18n.confirmForce ) ) {
			return;
		}
		counts = { applied: 0, restored: 0, skipped: 0, error: 0 };
		done = 0;
		failures = 0;
		stopRequested = false;
		$log.empty();
		$progress.prop( 'hidden', false );
		$text.text( i18n.working );
		setBusy( true );

		$.post( cfg.ajax, { action: 'hamrang_batch_query', nonce: cfg.nonce, scope: scope } ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				finish( ( res && res.data && res.data.message ) || i18n.requestError );
				return;
			}
			queue = res.data.ids || [];
			total = queue.length;
			updateCounters();
			if ( ! total ) {
				finish( i18n.nothing );
				return;
			}
			step( task );
		} ).fail( function () {
			finish( i18n.requestError );
		} );
	} );

	$stop.on( 'click', function () {
		stopRequested = true;
		$stop.prop( 'disabled', true );
	} );

	$start.each( function () {
		$( this ).data( 'locked', $( this ).prop( 'disabled' ) );
	} );

	$( window ).on( 'beforeunload', function () {
		if ( running ) {
			return true;
		}
	} );
}( jQuery ) );
