(function ($) {
	'use strict';

	var selectingCheapest = false;
	var initializedPackages = {};

	/** Keep native shipping radios visible and default to the first/cheapest. */
	function initialize() {
		$('ul#shipping_method, ul.woocommerce-shipping-methods, .wc-block-components-shipping-rates-control').each(function (packageIndex) {
			var container = $(this);
			var radios = container.find('input[type="radio"]:enabled');

			container.prev('.lwc-shipping-accordion__toggle').remove();
			container.prop('hidden', false)
				.removeAttr('data-lwc-shipping-accordion')
				.removeClass('lwc-shipping-accordion__options is-open')
				.addClass('lwc-shipping-rate-list');

			if (radios.length && !initializedPackages[packageIndex] && !selectingCheapest) {
				initializedPackages[packageIndex] = true;
				if (radios.first().is(':checked')) {
					return;
				}
				selectingCheapest = true;
				radios.first().get(0).click();
				window.setTimeout(function () { selectingCheapest = false; }, 0);
			}
		});
	}

	$(document.body).on('updated_checkout wc_fragments_refreshed wc-blocks_checkout_update', initialize);

	$(function () {
		initialize();
		var checkout = document.querySelector('.woocommerce-checkout, .wp-block-woocommerce-checkout');
		if (checkout && window.MutationObserver) {
			new MutationObserver(function () {
				window.clearTimeout(checkout.lwcShippingListTimer);
				checkout.lwcShippingListTimer = window.setTimeout(initialize, 80);
			}).observe(checkout, { childList: true, subtree: true });
		}
	});
})(jQuery);
