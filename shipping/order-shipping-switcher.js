(function ($) {
	'use strict';

	function setStatus(box, message, error) {
		box.find('.lwc-shipping-switch-status').toggleClass('is-error', !!error).toggleClass('is-success', !error).text(message || '');
	}

	$(document).on('click', '.lwc-load-shipping-options', function () {
		var button = $(this);
		var box = button.closest('.lwc-order-shipping-switcher');
		button.prop('disabled', true);
		setStatus(box, lwcOrderShippingSwitcher.i18n.loading, false);
		$.post(lwcOrderShippingSwitcher.ajaxUrl, {
			action: 'lwc_get_order_shipping_options',
			nonce: lwcOrderShippingSwitcher.nonce,
			order_id: box.data('order-id')
		}).done(function (response) {
			var data = response && response.data ? response.data : {};
			if (!response.success) {
				setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
				return;
			}
			var select = box.find('.lwc-new-shipping-rate').empty();
			$.each(data.options || [], function (_, option) {
				$('<option>').val(option.id).text(option.label + ' — ' + option.formatted).appendTo(select);
			});
			box.find('.lwc-shipping-options').prop('hidden', false);
			setStatus(box, '', false);
		}).fail(function (xhr) {
			var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
			setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
		}).always(function () {
			button.prop('disabled', false);
		});
	});

	$(document).on('click', '.lwc-change-shipping', function () {
		var button = $(this);
		var box = button.closest('.lwc-order-shipping-switcher');
		if (!window.confirm(lwcOrderShippingSwitcher.i18n.confirm)) {
			return;
		}
		button.prop('disabled', true);
		setStatus(box, lwcOrderShippingSwitcher.i18n.changing, false);
		$.post(lwcOrderShippingSwitcher.ajaxUrl, {
			action: 'lwc_change_order_shipping',
			nonce: lwcOrderShippingSwitcher.nonce,
			order_id: box.data('order-id'),
			rate_id: box.find('.lwc-new-shipping-rate').val(),
			cancel_confirmed: box.find('.lwc-shipping-cancel-confirm input').is(':checked') ? '1' : '0'
		}).done(function (response) {
			var data = response && response.data ? response.data : {};
			if (!response.success) {
				setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
				button.prop('disabled', false);
				return;
			}
			setStatus(box, data.message, false);
			window.location.reload();
		}).fail(function (xhr) {
			var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
			setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
			button.prop('disabled', false);
		});
	});
})(jQuery);
