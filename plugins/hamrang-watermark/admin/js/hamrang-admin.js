/* global jQuery, wp, hamrangAdmin */
( function ( $ ) {
	'use strict';

	var cfg = window.hamrangAdmin || {};
	var i18n = cfg.i18n || {};
	var $form = $( '#hamrang-form' );
	if ( ! $form.length ) {
		return;
	}

	/* ------------------------------------------------------------------ uploads from this screen must not be stamped */
	if ( wp && wp.Uploader && wp.Uploader.defaults ) {
		wp.Uploader.defaults.multipart_params = wp.Uploader.defaults.multipart_params || {};
		wp.Uploader.defaults.multipart_params.hamrang_skip = '1';
	}

	/* ------------------------------------------------------------------ range <-> number */
	$form.on( 'input change', '.hamrang-range input[type=range]', function () {
		var $num = $( '#' + $( this ).data( 'for' ) );
		if ( $num.val() !== this.value ) {
			$num.val( this.value );
		}
	} );
	$form.on( 'input change', '.hamrang-range input[type=number]', function () {
		var $range = $( this ).siblings( 'input[type=range]' );
		if ( $range.val() !== this.value ) {
			$range.val( this.value );
		}
	} );

	/* ------------------------------------------------------------------ conditional rows */
	function toggle( selector, show ) {
		$form.find( selector ).toggleClass( 'hamrang-hidden', ! show );
	}
	function refreshConditions() {
		var mode = $form.find( 'input[name="hamrang[apply_mode]"]:checked' ).val();
		var plate = $form.find( 'input[name="hamrang[plate]"]:checked' ).val();
		var color = $form.find( 'input[name="hamrang[plate_color]"]:checked' ).val();
		toggle( '.hamrang-when-featured', mode === 'featured' );
		toggle( '.hamrang-when-all', mode === 'all' );
		toggle( '.hamrang-when-plate', plate !== 'none' );
		toggle( '.hamrang-when-free', plate === 'none' );
		toggle( '.hamrang-when-custom', color === 'custom' );
	}
	$form.on( 'change', 'input[name="hamrang[apply_mode]"], input[name="hamrang[plate]"], input[name="hamrang[plate_color]"]', refreshConditions );
	refreshConditions();

	/* ------------------------------------------------------------------ colour picker */
	if ( $.fn.wpColorPicker ) {
		$form.find( '.hamrang-color' ).wpColorPicker( {
			change: function () {
				schedulePreview();
			},
			clear: function () {
				schedulePreview();
			}
		} );
	}

	/* ------------------------------------------------------------------ media pickers */
	function openFrame( title, button, onSelect, pngOnly ) {
		var frame = wp.media( {
			title: title,
			button: { text: button },
			multiple: false,
			library: { type: pngOnly ? [ 'image/png', 'image/webp', 'image/gif' ] : 'image' }
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			onSelect( attachment );
		} );
		frame.open();
	}

	$form.on( 'click', '.hamrang-pick-logo', function ( e ) {
		e.preventDefault();
		var $picker = $( this ).closest( '.hamrang-logo-picker' );
		openFrame( i18n.chooseLogo, i18n.useLogo, function ( att ) {
			if ( att.mime && att.mime !== 'image/png' && att.mime !== 'image/webp' && att.mime !== 'image/gif' ) {
				window.alert( i18n.notPng );
				return;
			}
			var src = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
			$picker.addClass( 'has-logo' );
			$picker.find( 'input[type=hidden]' ).val( att.id );
			$picker.find( '.hamrang-logo-thumb' ).html( $( '<img>', { src: src, alt: '' } ) );
			$picker.find( '.hamrang-logo-name' ).text( att.filename || '' );
			schedulePreview();
		}, true );
	} );

	$form.on( 'click', '.hamrang-remove-logo', function ( e ) {
		e.preventDefault();
		var $picker = $( this ).closest( '.hamrang-logo-picker' );
		$picker.removeClass( 'has-logo' );
		$picker.find( 'input[type=hidden]' ).val( '0' );
		$picker.find( '.hamrang-logo-thumb' ).empty();
		$picker.find( '.hamrang-logo-name' ).text( '' );
		schedulePreview();
	} );

	/* ------------------------------------------------------------------ preview */
	var $stage = $( '.hamrang-preview-stage' );
	var $img = $( '#hamrang-preview-img' );
	var $note = $( '.hamrang-preview-note' );
	var $sample = $( '#hamrang-sample' );
	var timer = null;
	var inflight = null;
	var serial = 0;

	function collect() {
		var data = {};
		// unchecked single checkboxes are not serialised; send an explicit 0 so the preview matches the form
		$form.find( 'input[type=checkbox]' ).each( function () {
			var m = this.name.match( /^hamrang\[([a-z_]+)\]$/ );
			if ( m && ! this.checked ) {
				data[ m[ 1 ] ] = '0';
			}
		} );
		$.each( $form.serializeArray(), function ( _, field ) {
			var m = field.name.match( /^hamrang\[([a-z_]+)\](\[\])?$/ );
			if ( ! m ) {
				return;
			}
			if ( m[ 2 ] ) {
				data[ m[ 1 ] ] = data[ m[ 1 ] ] || [];
				data[ m[ 1 ] ].push( field.value );
			} else {
				data[ m[ 1 ] ] = field.value;
			}
		} );
		return data;
	}

	function schedulePreview() {
		window.clearTimeout( timer );
		timer = window.setTimeout( runPreview, 450 );
	}

	function runPreview() {
		var mine = ++serial;
		if ( inflight && inflight.abort ) {
			inflight.abort();
		}
		$stage.addClass( 'is-loading' );
		inflight = $.post( cfg.ajax, {
			action: 'hamrang_preview',
			nonce: cfg.nonce,
			settings: collect(),
			sample: $sample.val()
		} ).done( function ( res ) {
			if ( mine !== serial ) {
				return;
			}
			if ( res && res.success ) {
				$img.attr( 'src', res.data.image );
				$note.text( res.data.note || '' ).toggleClass( 'is-error', ! res.data.ok );
			} else {
				$note.text( ( res && res.data && res.data.message ) || i18n.previewError ).addClass( 'is-error' );
			}
		} ).fail( function ( xhr, status ) {
			if ( status !== 'abort' && mine === serial ) {
				$note.text( i18n.previewError ).addClass( 'is-error' );
			}
		} ).always( function () {
			if ( mine === serial ) {
				$stage.removeClass( 'is-loading' );
			}
		} );
	}

	$form.on( 'input change', 'input, select', function ( e ) {
		if ( $( e.target ).closest( '.hamrang-samples' ).length ) {
			return;
		}
		schedulePreview();
	} );

	$form.on( 'click', '.hamrang-sample[data-sample]', function () {
		$( '.hamrang-sample' ).removeClass( 'is-active' );
		$( this ).addClass( 'is-active' );
		$sample.val( $( this ).data( 'sample' ) );
		schedulePreview();
	} );

	$( '#hamrang-pick-sample' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		openFrame( i18n.chooseSample, i18n.useSample, function ( att ) {
			var src = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			$( '.hamrang-sample' ).removeClass( 'is-active' );
			$btn.addClass( 'is-active' ).html( $( '<img>', { src: src, alt: '' } ) );
			$sample.val( att.id );
			schedulePreview();
		}, false );
	} );

	runPreview();
}( jQuery ) );
