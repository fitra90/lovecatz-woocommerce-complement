/**
 * J&T Cargo order metabox: create the carrier order without leaving the screen.
 */
(function ($) {
	'use strict';

	$(function () {
		var box = $('.lwc-jtc-order-box');
		if (!box.length || typeof lwcJtcOrder === 'undefined') {
			return;
		}

		var status = $('#lwc-jtc-order-status');
		var button = $('#lwc-jtc-create-order');

		button.on('click', function () {
			button.prop('disabled', true);
			status.removeClass('is-error is-success').text(lwcJtcOrder.creating);

			$.post(lwcJtcOrder.ajax_url, {
				action: 'lwc_jtc_create_order',
				nonce: lwcJtcOrder.nonce,
				order_id: box.data('order-id')
			})
				.done(function (response) {
					if (!response || !response.success) {
						status.addClass('is-error').text(
							(response && response.data && response.data.message) || 'J&T Cargo request failed.'
						);
						button.prop('disabled', false);
						return;
					}
					status.addClass('is-success').text(response.data.message);
					window.location.reload();
				})
				.fail(function (xhr) {
					var message = 'J&T Cargo request failed.';
					if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					}
					status.addClass('is-error').text(message);
					button.prop('disabled', false);
				});
		});
	});
})(jQuery);
