(function ($) {
	'use strict';

	function setStatus(box, message, error) {
		box.find('.lwc-shipping-switch-status').toggleClass('is-error', !!error).toggleClass('is-success', !error).text(message || '');
	}

	function invalidateQuote(box) {
		box.removeData('shippingQuoteToken');
		box.find('.lwc-shipping-rate-quote').prop('hidden', true);
		box.find('.lwc-shipping-rate-quote__value').text('');
		box.find('.lwc-change-shipping').prop('disabled', true);
	}

	// Read the buyer shipping address that is currently on the order edit
	// screen so a tariff refresh does not need a save first. Both the legacy
	// and the HPOS editor render the address under _shipping_* fields.
	function collectDestination() {
		var read = function (name) {
			var el = $(document).find('input[name="' + name + '"], select[name="' + name + '"], textarea[name="' + name + '"]').first();
			if (!el.length) {
				return '';
			}
			var value = el.val();
			return value === null || typeof value === 'undefined' ? '' : String(value).trim();
		};

		return {
			country: read('_shipping_country'),
			state: read('_shipping_state'),
			city: read('_shipping_city'),
			postcode: read('_shipping_postcode'),
			address_1: read('_shipping_address_1'),
			address_2: read('_shipping_address_2'),
			district: read('_lwc_shipping_district_name') || read('_wc_shipping/lwc/indonesia-district')
		};
	}

	function optionLabel(option) {
		var text = option.label + ' — ' + option.formatted;
		if (option.manual) {
			text += ' (' + lwcOrderShippingSwitcher.i18n.manualCost + ')';
		}
		if (option.unavailable && option.unavailable_reason) {
			text += ' — ' + option.unavailable_reason;
		}
		if (option.current) {
			text += ' (' + lwcOrderShippingSwitcher.i18n.current + ')';
		}
		return text;
	}

	// Build the select with one optgroup per courier so several services per
	// courier stay readable.
	function buildOptionNode(option) {
		var node = $('<option>').val(option.id).text(optionLabel(option));
		if (option.unavailable) {
			node.prop('disabled', true);
		}
		return node;
	}

	function fillOptions(select, options) {
		var groups = {};
		var order = [];

		select.empty();

		$.each(options || [], function (_, option) {
			var courier = option.courier || '';
			if (!groups[courier]) {
				groups[courier] = [];
				order.push(courier);
			}
			groups[courier].push(option);
		});

		$.each(order, function (_, courier) {
			var group = groups[courier];
			var target = select;

			if (courier) {
				var optgroup = $('<optgroup>').attr('label', group[0].label);
				optgroup.appendTo(select);
				target = optgroup;
			}

			$.each(group, function (__, option) {
				buildOptionNode(option).appendTo(target);
			});
		});
	}

	// Append fallback options that are not already present, keeping the
	// live-rate services listed first.
	function appendOptions(select, options) {
		var existing = {};
		select.find('option').each(function () {
			existing[String(this.value)] = true;
		});

		$.each(options || [], function (_, option) {
			if (existing[String(option.id)]) {
				return;
			}
			existing[String(option.id)] = true;
			buildOptionNode(option).appendTo(select);
		});
	}

	function applyDestinationNotice(box, data) {
		var notice = box.find('.lwc-destination-message');
		var message = '';
		var isError = false;

		if (!data.destination_valid && data.destination_message) {
			message = data.destination_message;
			isError = true;
		} else if (data.rate_notice) {
			message = data.rate_notice;
		}

		if (message) {
			notice.text(message).prop('hidden', false).toggleClass('is-error', isError);
		} else {
			notice.text('').prop('hidden', true).removeClass('is-error');
		}
	}

	// Only send an address when the edit screen actually exposes one, so the
	// server can fall back to the saved order address otherwise.
	function destinationPayload() {
		var destination = collectDestination();
		var found = false;
		$.each(destination, function (_, value) {
			if (value) {
				found = true;
				return false;
			}
		});
		return found ? JSON.stringify(destination) : '';
	}

	function showNextStep(box, data) {
		var status = box.find('.lwc-shipping-switch-status').removeClass('is-error').addClass('is-success').empty();
		if (data.message) {
			status.append($('<p>').text(data.message));
		}
		if (data.next_step_html) {
			status.append(data.next_step_html);
		}
	}

	$(document).on('click', '.lwc-load-shipping-options', function () {
		var button = $(this);
		var box = button.closest('.lwc-order-shipping-switcher');
		button.prop('disabled', true);
		setStatus(box, lwcOrderShippingSwitcher.i18n.loading, false);
		$.post(lwcOrderShippingSwitcher.ajaxUrl, {
			action: 'lwc_get_order_shipping_options',
			nonce: lwcOrderShippingSwitcher.nonce,
			order_id: box.data('order-id'),
			destination: destinationPayload()
		}).done(function (response) {
			var data = response && response.data ? response.data : {};
			if (!response.success) {
				setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
				return;
			}
			fillOptions(box.find('.lwc-new-shipping-rate'), data.options);
			appendOptions(box.find('.lwc-new-shipping-rate'), data.manual_options);
			invalidateQuote(box);
			applyDestinationNotice(box, data);
			box.find('.lwc-shipping-options').prop('hidden', false);
			setStatus(box, '', false);
		}).fail(function (xhr) {
			var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
			setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
		}).always(function () {
			button.prop('disabled', false);
		});
	});

	$(document).on('change input', '.lwc-new-shipping-rate, .lwc-shipping-manual-cost, input[name^="_shipping_"], select[name^="_shipping_"], textarea[name^="_shipping_"], input[name="_lwc_shipping_district_name"], select[name="_wc_shipping/lwc/indonesia-district"]', function () {
		$('.lwc-order-shipping-switcher').each(function () {
			invalidateQuote($(this));
		});
	});

	$(document).on('click', '.lwc-check-shipping-rate', function () {
		var button = $(this);
		var box = button.closest('.lwc-order-shipping-switcher');
		var selected = box.find('.lwc-new-shipping-rate').val();
		invalidateQuote(box);
		if (!selected) {
			setStatus(box, lwcOrderShippingSwitcher.i18n.rateRequired, true);
			return;
		}

		button.prop('disabled', true);
		setStatus(box, lwcOrderShippingSwitcher.i18n.checkingRate, false);
		$.post(lwcOrderShippingSwitcher.ajaxUrl, {
			action: 'lwc_quote_order_shipping',
			nonce: lwcOrderShippingSwitcher.nonce,
			order_id: box.data('order-id'),
			rate_id: selected,
			manual_cost: box.find('.lwc-shipping-manual-cost').val() || '',
			destination: destinationPayload()
		}).done(function (response) {
			var data = response && response.data ? response.data : {};
			if (!response.success || !data.quote_token) {
				setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
				return;
			}
			box.data('shippingQuoteToken', data.quote_token);
			box.find('.lwc-shipping-rate-quote__value').text(data.label + ' — ' + data.formatted);
			box.find('.lwc-shipping-rate-quote').prop('hidden', false);
			box.find('.lwc-change-shipping').prop('disabled', false);
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
		var quoteToken = box.data('shippingQuoteToken') || '';
		if (!quoteToken) {
			setStatus(box, lwcOrderShippingSwitcher.i18n.rateRequired, true);
			return;
		}
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
			quote_token: quoteToken,
			manual_cost: box.find('.lwc-shipping-manual-cost').val() || '',
			cancel_confirmed: box.find('.lwc-shipping-cancel-confirm input').is(':checked') ? '1' : '0',
			destination: destinationPayload()
		}).done(function (response) {
			var data = response && response.data ? response.data : {};
			if (!response.success) {
				setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
				invalidateQuote(box);
				return;
			}
			showNextStep(box, data);
			invalidateQuote(box);
		}).fail(function (xhr) {
			var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
			setStatus(box, data.message || lwcOrderShippingSwitcher.i18n.error, true);
			invalidateQuote(box);
		});
	});

	$(document).on('click', '.lwc-shipping-reload', function () {
		window.location.reload();
	});
})(jQuery);
