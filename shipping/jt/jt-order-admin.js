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
			order_id: box.data('order-id'),
			insurance: action === 'lwc_jt_create_order' ? $('#lwc-jt-insurance').val() : undefined
		}).done(function (response) {
			var data = response && response.data ? response.data : {};
			status.addClass(response.success ? 'is-success' : 'is-error').text(data.message || 'J&T request failed.');
			if (data.exchange) {
				$('<details class="lwc-jt-api-exchange"><summary>Original J&T request / response</summary><pre></pre></details>')
					.find('pre').text(JSON.stringify(data.exchange, null, 2)).end()
					.appendTo(status);
			}
			if (response.success && data.html) {
				$('#lwc-jt-tracking').html(data.html);
			}
			if (response.success && (action === 'lwc_jt_create_order' || action === 'lwc_jt_cancel_order' || (action === 'lwc_jt_refresh_tracking' && data.cancelled))) {
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
	$(document).on('click', '#lwc-jt-print-label', function () {
		var button = $(this);
		var fallbackLink = $('#lwc-jt-open-label');
		var printWindow = window.open('', '_blank');

		fallbackLink.prop('hidden', true).attr('href', '#');
		if (printWindow) {
			printWindow.document.title = 'J&T Label';
			printWindow.document.body.textContent = (window.lwcJtOrder && lwcJtOrder.preparing_label) || 'Preparing J&T label...';
		}

		request('lwc_jt_print_label', button).done(function (response) {
			var data = response && response.data ? response.data : {};
			if (response.success && data.label_url) {
				if (printWindow) {
					printWindow.opener = null;
					printWindow.location.replace(data.label_url);
				} else {
					fallbackLink.attr('href', data.label_url).prop('hidden', false);
				}
			} else if (printWindow) {
				printWindow.close();
			}
		}).fail(function () {
			if (printWindow) {
				printWindow.close();
			}
		});
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
