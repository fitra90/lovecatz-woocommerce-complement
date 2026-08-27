(function ($) {
	'use strict';

	function request(action, button) {
		var box = $('.lwc-jt-order-box');
		var status = $('#lwc-jt-order-status');
		button.prop('disabled', true);
		status.removeClass('is-error is-success').text('Processing J&T request...');
		return $.post(lwcJtOrder.ajax_url, {
			action: action,
			nonce: lwcJtOrder.nonce,
			order_id: box.data('order-id')
		}).done(function (response) {
			var data = response && response.data ? response.data : {};
			status.addClass(response.success ? 'is-success' : 'is-error').text(data.message || 'J&T request failed.');
			if (response.success && data.html) {
				$('#lwc-jt-tracking').html(data.html);
			}
			if (response.success && action === 'lwc_jt_create_order') {
				window.location.reload();
			}
		}).fail(function () {
			status.addClass('is-error').text('J&T request failed.');
		}).always(function () {
			button.prop('disabled', false);
		});
	}

	$(document).on('click', '#lwc-jt-create-order', function () {
		request('lwc_jt_create_order', $(this));
	});
	$(document).on('click', '#lwc-jt-refresh-tracking', function () {
		request('lwc_jt_refresh_tracking', $(this));
	});
	$(document).on('click', '#lwc-jt-cancel-order', function () {
		if (window.confirm('Cancel this J&T shipment?')) {
			request('lwc_jt_cancel_order', $(this));
		}
	});
})(jQuery);
