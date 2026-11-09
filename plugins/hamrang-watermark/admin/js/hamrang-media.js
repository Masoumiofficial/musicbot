/* global jQuery, hamrangMedia */
( function ( $ ) {
	'use strict';

	var cfg = window.hamrangMedia || {};

	$( document ).on( 'click', '.hamrang-single', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $wrap = $btn.closest( '.hamrang-state-wrap' );
		var $msg = $wrap.find( '.hamrang-state-message' );
		if ( $wrap.hasClass( 'is-busy' ) ) {
			return;
		}
		$wrap.addClass( 'is-busy' );
		$msg.removeClass( 'is-error is-ok' ).text( cfg.i18n ? cfg.i18n.working : '…' );

		$.post( cfg.ajax, {
			action: 'hamrang_single',
			nonce: cfg.nonce,
			id: $btn.data( 'id' ),
			task: $btn.data( 'task' )
		} ).done( function ( res ) {
			var data = ( res && res.data ) || {};
			if ( data.html ) {
				var $new = $( data.html );
				$wrap.replaceWith( $new );
				$wrap = $new;
				$msg = $wrap.find( '.hamrang-state-message' );
			}
			$msg.text( data.message || '' ).toggleClass( 'is-error', ! res.success ).toggleClass( 'is-ok', !! res.success );
			if ( data.thumb ) {
				// refresh the preview in the attachment details panel / grid
				var $panel = $wrap.closest( '.attachment-details, .media-modal, .attachment-info' );
				$panel.find( '.thumbnail img, .details-image' ).each( function () {
					$( this ).attr( 'src', data.thumb );
				} );
			}
		} ).fail( function () {
			$wrap.removeClass( 'is-busy' );
			$msg.addClass( 'is-error' ).text( cfg.i18n ? cfg.i18n.requestError : 'Error' );
		} );
	} );
}( jQuery ) );
