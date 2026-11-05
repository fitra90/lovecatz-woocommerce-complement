(function ($) {
	'use strict';

	var selectingDefault = false;
	var applied = {};
	var attempts = {};
	var blockModeApplied = false;
	var cfg = window.lwcShippingDefault || {};
	var mode = cfg.mode || 'ship';
	var pickupIds = (cfg.pickupMethodIds && cfg.pickupMethodIds.length)
		? cfg.pickupMethodIds
		: ['local_pickup', 'pickup_location'];

	/** WooCommerce rate ids look like "method_id:instance_id". */
	function rateValue(radio) {
		return String(radio.value || radio.id || '');
	}

	function isPickup(radio) {
		var rate = rateValue(radio);
		var method = rate.split(':')[0];
		for (var i = 0; i < pickupIds.length; i++) {
			var id = String(pickupIds[i]);
			if (method === id || rate.indexOf(id + ':') === 0) {
				return true;
			}
		}
		return false;
	}

	function findPreferred(radios, wantPickup) {
		var found = null;
		radios.each(function () {
			if (!found && isPickup(this) === wantPickup) {
				found = this;
			}
		});
		return found;
	}

	/**
	 * The Checkout Block keeps "Ship / Pickup" in its own data store. When
	 * pickup is active the delivery radios are not rendered at all, so toggle
	 * the mode by clicking the other card. WooCommerce's own handler then
	 * updates both the checkout store and the cart.
	 */
	function applyBlockMode() {
		if ('woocommerce' === mode || blockModeApplied || !window.wp || !window.wp.data || !window.wp.data.select) {
			return;
		}

		var cards = document.querySelectorAll('.wc-block-checkout__shipping-method-option[role="radio"]');
		if (cards.length < 2) {
			return; // classic checkout, or the block has not rendered yet
		}

		var current;
		try {
			var checkout = window.wp.data.select('wc/store/checkout');
			current = checkout && checkout.prefersCollection ? checkout.prefersCollection() : undefined;
		} catch (e) {
			return;
		}
		if (typeof current !== 'boolean') {
			return; // cart data is not available yet
		}

		var wantPickup = ('pickup' === mode);
		if (current === wantPickup) {
			blockModeApplied = true;
			return;
		}

		var unselected = null;
		for (var i = 0; i < cards.length; i++) {
			if (cards[i].getAttribute('aria-checked') !== 'true') {
				unselected = cards[i];
				break;
			}
		}
		if (!unselected) {
			return;
		}

		blockModeApplied = true;
		unselected.click();
	}

	/** Keep native shipping radios visible and select the configured default. */
	function initialize() {
		$('ul#shipping_method, ul.woocommerce-shipping-methods, .wc-block-components-shipping-rates-control').each(function (packageIndex) {
			var container = $(this);
			var radios = container.find('input[type="radio"]:enabled');

			container.prev('.lwc-shipping-accordion__toggle').remove();
			container.prop('hidden', false)
				.removeAttr('data-lwc-shipping-accordion')
				.removeClass('lwc-shipping-accordion__options is-open')
				.addClass('lwc-shipping-rate-list');

			if ('woocommerce' === mode || applied[packageIndex] || !radios.length || selectingDefault) {
				return;
			}

			var wantPickup = ('pickup' === mode);
			var checked = radios.filter(':checked').get(0);
			if (checked && isPickup(checked) === wantPickup) {
				applied[packageIndex] = true;
				return;
			}

			// A React re-render can replace the rate list; wait until the
			// preferred kind is actually on screen before clicking anything.
			var target = findPreferred(radios, wantPickup);
			if (!target) {
				return; // preferred kind is not rendered yet
			}
			if ((attempts[packageIndex] || 0) >= 3) {
				applied[packageIndex] = true;
				return;
			}

			attempts[packageIndex] = (attempts[packageIndex] || 0) + 1;
			selectingDefault = true;
			target.click();
			window.setTimeout(function () { selectingDefault = false; }, 0);
		});

		applyBlockMode();
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
