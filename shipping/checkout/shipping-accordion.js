(function ($) {
	'use strict';

	var sequence = 0;

	function getRateLabel(container, radio) {
		var label;
		if (!radio || !radio.length) {
			return '';
		}
		if (radio.attr('id')) {
			label = container.find('label[for="' + radio.attr('id') + '"]').first();
		}
		if (!label || !label.length) {
			label = radio.closest('label');
		}
		if (!label.length) {
			label = radio.closest('li, .wc-block-components-radio-control__option');
		}
		return $.trim(label.text().replace(/\s+/g, ' '));
	}

	function updateButton(button, container) {
		var selected = container.find('input[type="radio"]:checked').first();
		if (!selected.length) {
			selected = container.find('input[type="radio"]').first();
		}
		var label = getRateLabel(container, selected) || window.lwcShippingAccordion.noneSelected;
		var selection = button.find('.lwc-shipping-accordion__selection');
		if (selection.text() !== label) {
			selection.text(label);
		}
	}

	function setOpen(button, container, open) {
		button.attr('aria-expanded', open ? 'true' : 'false');
		container.prop('hidden', !open).toggleClass('is-open', open);
	}

	function enhance(container) {
		var radios = container.find('input[type="radio"]');
		if (radios.length < 2) {
			var oldButton = container.prev('.lwc-shipping-accordion__toggle');
			oldButton.remove();
			container.prop('hidden', false).removeClass('lwc-shipping-accordion__options is-open').removeAttr('data-lwc-shipping-accordion');
			return;
		}

		var button = container.prev('.lwc-shipping-accordion__toggle');
		if (!container.attr('id')) {
			sequence += 1;
			container.attr('id', 'lwc-shipping-options-' + sequence);
		}
		if (!button.length) {
			button = $('<button type="button" class="lwc-shipping-accordion__toggle" aria-expanded="false"><span class="lwc-shipping-accordion__caption"></span><strong class="lwc-shipping-accordion__selection"></strong><span class="lwc-shipping-accordion__chevron" aria-hidden="true"></span></button>');
			button.find('.lwc-shipping-accordion__caption').text(window.lwcShippingAccordion.caption);
			button.attr('aria-controls', container.attr('id'));
			button.insertBefore(container);
		}

		container.addClass('lwc-shipping-accordion__options').attr('data-lwc-shipping-accordion', 'yes');
		updateButton(button, container);
		setOpen(button, container, false);
	}

	function initialize() {
		$('ul#shipping_method, ul.woocommerce-shipping-methods, .wc-block-components-shipping-rates-control').each(function () {
			enhance($(this));
		});
	}

	$(document).on('click', '.lwc-shipping-accordion__toggle', function () {
		var button = $(this);
		var container = $('#' + button.attr('aria-controls'));
		setOpen(button, container, button.attr('aria-expanded') !== 'true');
	});

	$(document).on('change', '.lwc-shipping-accordion__options input[type="radio"]', function () {
		var container = $(this).closest('.lwc-shipping-accordion__options');
		var button = container.prev('.lwc-shipping-accordion__toggle');
		updateButton(button, container);
		setOpen(button, container, false);
	});

	$(document.body).on('updated_checkout wc_fragments_refreshed wc-blocks_checkout_update', initialize);

	$(function () {
		initialize();
		var checkout = document.querySelector('.woocommerce-checkout, .wp-block-woocommerce-checkout');
		if (checkout && window.MutationObserver) {
			new MutationObserver(function () {
				window.clearTimeout(checkout.lwcShippingAccordionTimer);
				checkout.lwcShippingAccordionTimer = window.setTimeout(initialize, 80);
			}).observe(checkout, {childList: true, subtree: true});
		}
	});
})(jQuery);
