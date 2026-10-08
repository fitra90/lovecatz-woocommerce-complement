/**
 * Purchase Order / partial payment order-screen behaviour.
 *
 * Every action posts to admin-ajax and swaps the metabox body for the freshly
 * rendered panel, so the figures, badges and ledger always come straight from
 * the server instead of being recomputed in the browser.
 */
( function ( $ ) {
	'use strict';

	var config = window.lwcPaymentPlan || {};
	var i18n = config.i18n || {};

	/**
	 * The metabox root, or null when we are not on an order screen.
	 *
	 * @return {jQuery|null} Root element.
	 */
	function root() {
		var $root = $( '#lwc-pp-root' );

		return $root.length ? $root : null;
	}

	/**
	 * Current order ID, read from the DOM rather than captured at load time.
	 *
	 * @return {string} Order ID.
	 */
	function orderId() {
		return String( $( '#lwc-pp-root' ).attr( 'data-order-id' ) || '' );
	}

	/**
	 * Toggle the busy state of the panel.
	 *
	 * @param {jQuery} $root Metabox root.
	 * @param {boolean} busy Whether a request is in flight.
	 */
	function setBusy( $root, busy ) {
		$root.find( '.lwc-pp-spinner' ).toggleClass( 'is-active', busy );
		$root.find( 'button' ).prop( 'disabled', busy );
	}

	/**
	 * Show a result message under the panel.
	 *
	 * @param {jQuery} $root   Metabox root.
	 * @param {string} message Message text.
	 * @param {boolean} isError Whether the message reports a failure.
	 */
	function feedback( $root, message, isError ) {
		$root
			.find( '.lwc-pp-feedback' )
			.text( message || '' )
			.toggleClass( 'is-error', !! isError )
			.toggleClass( 'is-success', ! isError );
	}

	/**
	 * Post one action and apply the returned panel.
	 *
	 * @param {jQuery} $root  Metabox root.
	 * @param {string} action AJAX action name.
	 * @param {Object} data   Extra payload.
	 */
	function submit( $root, action, data ) {
		setBusy( $root, true );
		feedback( $root, i18n.working || '', false );

		$.post(
			config.ajaxUrl,
			$.extend(
				{
					action: action,
					nonce: config.nonce,
					order_id: orderId()
				},
				data
			)
		)
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					var message = response && response.data && response.data.message
						? response.data.message
						: ( i18n.failed || '' );
					feedback( $root, message, true );
					setBusy( $root, false );
					return;
				}

				$root.html( response.data.html );
				feedback( root(), response.data.message || '', false );
			} )
			.fail( function () {
				feedback( $root, i18n.failed || '', true );
				setBusy( $root, false );
			} );
	}

	$( document ).on( 'click', '#lwc-pp-root .lwc-pp-plan-button', function () {
		var $root = root();
		if ( ! $root ) {
			return;
		}

		submit( $root, config.actions.plan, { plan: $( this ).attr( 'data-plan' ) } );
	} );

	$( document ).on( 'submit', '#lwc-pp-root .lwc-pp-plan-form', function ( event ) {
		event.preventDefault();

		var $root = root();
		if ( ! $root ) {
			return;
		}

		var $form = $( this );
		var payload = { plan: $form.attr( 'data-plan' ) };

		$form.find( 'input, select' ).each( function () {
			payload[ this.name ] = $( this ).val();
		} );

		submit( $root, config.actions.plan, payload );
	} );

	$( document ).on( 'submit', '#lwc-pp-root .lwc-pp-record', function ( event ) {
		event.preventDefault();

		var $root = root();
		if ( ! $root ) {
			return;
		}

		var $form = $( this );
		var payload = {};

		$form.find( 'input, select' ).each( function () {
			payload[ this.name ] = $( this ).val();
		} );

		submit( $root, config.actions.record, payload );
	} );

	$( document ).on( 'click', '#lwc-pp-root .lwc-pp-delete-payment', function () {
		var $root = root();
		if ( ! $root ) {
			return;
		}

		if ( i18n.confirm && ! window.confirm( i18n.confirm ) ) {
			return;
		}

		submit( $root, config.actions.delete, { payment_id: $( this ).attr( 'data-payment-id' ) } );
	} );

	// Quick-fill helpers on the record form. They only move data between the
	// server-rendered panel and the input, never recompute a figure themselves.
	$( document ).on( 'click', '#lwc-pp-root .lwc-pp-fill-balance', function () {
		var amount = $( this ).attr( 'data-amount' );

		if ( amount !== undefined && amount !== '' ) {
			$( '#lwc-pp-root #lwc-pp-amount' ).val( amount ).trigger( 'focus' );
		}
	} );
} )( jQuery );
