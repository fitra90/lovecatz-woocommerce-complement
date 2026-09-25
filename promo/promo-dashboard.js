(function ($) {
    'use strict';

    var storageKey = 'lwc_promo_coupon_code';
    var couponModalTrigger = null;

    function closeCouponModal() {
        $('#lwc-coupon-modal').prop('hidden', true);
        $('html, body').removeClass('lwc-coupon-modal-open');
        if (couponModalTrigger && document.documentElement.contains(couponModalTrigger)) {
            $(couponModalTrigger).trigger('focus');
        }
        couponModalTrigger = null;
    }

    function getCouponInput() {
        return $(lwcPromoDashboard.couponInputSelector).first();
    }

    function applyCoupon(couponCode, complete) {
        var attempts = 0;
        var finished = false;
        var finish = function () {
            if (finished) {
                return;
            }
            finished = true;
            $(document.body).off('.lwcCouponApply');
            if (typeof complete === 'function') {
                complete();
            }
        };
        var applyWhenReady = function () {
            var couponInput = getCouponInput();

            /* Checkout Block renders its input only after its accordion opens. */
            var couponToggle = $('.wc-block-components-totals-coupon .wc-block-components-panel__button[role="button"], .wc-block-components-totals-coupon__button, .wc-block-components-totals-coupon > button').first();
            if (couponToggle.length && 0 === attempts) {
                couponToggle.trigger('click');
            }

            if (couponInput.length) {
                /* React Checkout Block only enables Apply after a native input event. */
                var input = couponInput.get(0);
                var valueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                valueSetter.call(input, couponCode);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));

                window.setTimeout(function () {
                    var applyButton = $(lwcPromoDashboard.applyButtonSelector).filter(':visible:not(:disabled)').first();
                    if (applyButton.length) {
                        /* Classic checkout publishes these events. Blocks are
                         * covered by the timeout because their React store does
                         * not expose a stable browser event for coupon success. */
                        $(document.body).one('applied_coupon_in_checkout.lwcCouponApply applied_coupon.lwcCouponApply updated_checkout.lwcCouponApply wc-blocks_checkout_update.lwcCouponApply', function () {
                            window.setTimeout(finish, 450);
                        });
                        applyButton.get(0).click();
                        window.setTimeout(finish, 2500);
                    } else if (attempts < 50) {
                        attempts += 1;
                        window.setTimeout(applyWhenReady, 100);
                    } else {
                        finish();
                    }
                }, 100);
                return;
            }

            attempts += 1;
            if (attempts < 50) {
                window.setTimeout(applyWhenReady, 100);
            } else {
                finish();
            }
        };

        applyWhenReady();
        return true;
    }

    function applyCoupons(couponCodes, complete) {
        var queue = couponCodes.slice();
        var applyNext = function () {
            if (!queue.length) {
                if (typeof complete === 'function') {
                    complete();
                }
                return;
            }
            applyCoupon(queue.shift(), applyNext);
        };
        applyNext();
    }

    function getCouponById(couponId) {
        var coupons = lwcPromoDashboard.coupons || [];
        for (var i = 0; i < coupons.length; i += 1) {
            if (String(coupons[i].id) === String(couponId)) {
                return coupons[i];
            }
        }
        return null;
    }

    function couponsAreCompatible(first, second) {
        if (!first || !second || !first.allowCombination || !second.allowCombination) {
            return false;
        }

        var firstIds = first.combinationCouponIds;
        var secondIds = second.combinationCouponIds;
        var firstAllows = firstIds === null || firstIds.map(String).indexOf(String(second.id)) !== -1;
        var secondAllows = secondIds === null || secondIds.map(String).indexOf(String(first.id)) !== -1;
        return firstAllows && secondAllows;
    }

    function updateCouponSelection(changedInput) {
        var modal = $('#lwc-coupon-modal');
        var inputs = modal.find('.lwc-coupon-option-input');

        if (changedInput && changedInput.checked) {
			var changedCoupon = getCouponById($(changedInput).closest('.lwc-checkout-coupon-option').attr('data-coupon-id'));
			var conflictsWithApplied = false;
			inputs.filter(':checked').not(changedInput).each(function () {
				var other = getCouponById($(this).closest('.lwc-checkout-coupon-option').attr('data-coupon-id'));
				if (!couponsAreCompatible(changedCoupon, other)) {
					if ('1' === $(this).attr('data-locked')) {
						conflictsWithApplied = true;
					} else {
						$(this).prop('checked', false);
					}
				}
			});
			if (conflictsWithApplied) {
				$(changedInput).prop('checked', false);
			}
        }

		inputs.filter('[data-locked="0"]').prop('disabled', false).each(function () {
			$(this).closest('.lwc-checkout-coupon-option').removeClass('is-incompatible').find('.lwc-coupon-option-status').text('');
		});

		var selectedCoupons = (lwcPromoDashboard.appliedCouponRules || []).slice();
		var newlySelectedCoupons = inputs.filter('[data-locked="0"]:checked').map(function () {
			return getCouponById($(this).closest('.lwc-checkout-coupon-option').attr('data-coupon-id'));
		}).get();
		selectedCoupons = selectedCoupons.concat(newlySelectedCoupons);
		inputs.filter('[data-locked="0"]:not(:checked)').each(function () {
			var input = $(this);
			var candidate = getCouponById(input.closest('.lwc-checkout-coupon-option').attr('data-coupon-id'));
			var incompatible = selectedCoupons.some(function (selected) {
				return !couponsAreCompatible(candidate, selected);
			});
			if (incompatible) {
				input.prop('disabled', true);
				input.closest('.lwc-checkout-coupon-option').addClass('is-incompatible').find('.lwc-coupon-option-status').text('Tidak dapat digabung');
			}
		});

        modal.find('.lwc-checkout-coupon-option').each(function () {
            $(this).toggleClass('is-selected', $(this).find('.lwc-coupon-option-input').prop('checked'));
        });

		var availableInputs = inputs.filter('[data-locked="0"]:not(:disabled)');
        var count = availableInputs.filter(':checked').length;
        modal.find('.lwc-add-selected-coupons').prop('disabled', count === 0);
        modal.find('.lwc-coupon-modal__selection').text(
            count ? count + ' kupon dipilih' : (availableInputs.length ? 'Pilih satu atau lebih kupon' : 'Tidak ada kupon yang dapat dipilih')
        );
    }

    function applyCouponFromStorage() {
        if (typeof lwcPromoDashboard === 'undefined') {
            return;
        }

        var couponCode = window.localStorage.getItem(storageKey);
        if (!couponCode) {
            return;
        }

        if (applyCoupon(couponCode)) {
            window.localStorage.removeItem(storageKey);
        }
    }

    function isCheckoutLikePage() {
        var bodyClass = document.body ? String(document.body.className) : '';
        return bodyClass.indexOf('woocommerce-checkout') !== -1 || bodyClass.indexOf('woocommerce-cart') !== -1;
    }

    function getCheckoutCouponContainer() {
        var selectors = [
            '.lwc-checkout-promos',
            '.ct-order-review',
            '.ct-woocommerce-checkout .ct-order-review',
            '.cart_totals',
            '.woocommerce-checkout-review-order',
            '.woocommerce-checkout-payment',
            '.wc-block-components-totals-coupon',
            '.wp-block-woocommerce-checkout-totals-block .wc-block-components-totals-coupon',
            '.wc-block-checkout__totals .wc-block-components-totals-coupon',
            '.woocommerce-checkout .coupon',
            '.checkout_coupon',
            '#payment .checkout_coupon',
            '.wp-block-woocommerce-checkout-order-summary-block .wc-block-components-totals-coupon',
            '.entry-content .woocommerce-checkout .coupon',
            '.blocksy-content-wrapper .woocommerce-checkout .coupon'
        ];

        for (var i = 0; i < selectors.length; i += 1) {
            var container = $(selectors[i]).first();
            if (container.length) {
                return container;
            }
        }

        // Never fall back to <body>: outside a real checkout layout the
        // picker would render below the site footer.
        return $();
    }

    function renderCheckoutCouponPicker() {
        if (!isCheckoutLikePage()) {
            return;
        }

        var coupons = lwcPromoDashboard.coupons || [];
        var showAccountInvite = !!(lwcPromoDashboard.isGuest && lwcPromoDashboard.hasAccountPromos);

        if ($('#lwc-checkout-coupon-picker').length) {
            return;
        }

        var couponArea = getCheckoutCouponContainer();
        if (!couponArea.length) {
            return;
        }

        /* Checkout fragments can remove the trigger without removing a modal
         * that was portalled to <body>. Clear that orphan before rebuilding. */
        $('#lwc-coupon-modal').remove();
        $('html, body').removeClass('lwc-coupon-modal-open');

		var appliedCoupons = lwcPromoDashboard.appliedCouponRules || coupons.filter(function (coupon) {
			return !!(!coupon.expired && coupon.applied);
		});
        var modalItems = $.map(coupons, function (coupon) {
            var image = $('<span>').text(coupon.image || '').html();
            var code = $('<span>').text(coupon.code).html();
            var description = $('<span>').text(coupon.description).html();
			var expired = !!coupon.expired;
			var applied = !expired && !!coupon.applied;
			var incompatible = !expired && !applied && appliedCoupons.some(function (appliedCoupon) {
				return !couponsAreCompatible(coupon, appliedCoupon);
			});
			var optionState = expired ? ' is-expired' : (applied ? ' is-selected is-applied' : (incompatible ? ' is-incompatible' : ''));
			var locked = expired || applied;
			return '<label class="lwc-checkout-coupon-option' + optionState + '" data-coupon-id="' + String(coupon.id) + '">' +
				'<input type="checkbox" class="lwc-coupon-option-input" data-locked="' + (locked ? '1' : '0') + '" value="' + code + '"' + (applied ? ' checked' : '') + ((locked || incompatible) ? ' disabled' : '') + ' />' +
                '<img src="' + image + '" alt="" /><span class="lwc-checkout-coupon-option__content"><strong>' + code + '</strong><span>' + description + '</span></span>' +
				'<span class="lwc-coupon-option-check" aria-hidden="true"></span><em class="lwc-coupon-option-status">' + (expired ? 'Expired' : (applied ? 'Ditambahkan' : (incompatible ? 'Tidak dapat digabung' : ''))) + '</em></label>';
        }).join('');

        var emptyMessage = coupons.length ? '' :
            '<p class="lwc-coupon-modal__empty">Belum ada kupon aktif yang tersedia untuk akun ini.</p>';
        var couponPicker =
            '<button type="button" class="lwc-open-coupon-modal" aria-haspopup="dialog" aria-controls="lwc-coupon-modal" aria-expanded="false">Kupon &amp; promo <span aria-hidden="true">›</span></button>' +
            '<div id="lwc-coupon-modal" class="lwc-coupon-modal" role="dialog" aria-modal="true" aria-labelledby="lwc-coupon-modal-title" hidden>' +
            '<div class="lwc-coupon-modal__backdrop"></div><div class="lwc-coupon-modal__panel">' +
            '<div class="lwc-coupon-modal__header"><div><h3 id="lwc-coupon-modal-title">Pilih kupon</h3><p>Pilih satu atau beberapa promo untuk pesanan ini.</p></div><button type="button" class="lwc-close-coupon-modal" aria-label="Tutup">×</button></div>' +
            '<div class="lwc-coupon-modal__list">' + modalItems + emptyMessage + '</div>' +
            (coupons.length ? '<div class="lwc-coupon-modal__footer"><span class="lwc-coupon-modal__selection" aria-live="polite">Pilih satu atau lebih kupon</span><button type="button" class="lwc-add-selected-coupons" disabled>Add Coupon</button></div>' : '') +
            '</div></div>';
        var accountInvite = showAccountInvite ?
            '<div class="lwc-checkout-account-promo"><span class="lwc-checkout-account-promo__icon" aria-hidden="true">%</span>' +
            '<span class="lwc-checkout-account-promo__content"><strong>Kupon spesial menantimu!</strong><span>Login atau buat akun gratis untuk membuka promo eksklusif.</span></span>' +
            '<a class="lwc-checkout-account-promo__link" href="' + $('<span>').text(lwcPromoDashboard.accountUrl).html() + '">Login / Daftar</a></div>' : '';
        var picker = '<div id="lwc-checkout-coupon-picker" class="lwc-checkout-coupon-picker">' + couponPicker + accountInvite + '</div>';

        if (couponArea.is('.wc-block-components-totals-coupon')) {
            couponArea.after(picker);
        } else if (couponArea.is('.ct-order-review')) {
            couponArea.prepend(picker);
        } else {
            couponArea.append(picker);
        }

        /* Keep the fixed modal outside checkout/payment stacking contexts.
         * PayPal and card iframes otherwise paint above a nested modal. */
        $('#lwc-checkout-coupon-picker #lwc-coupon-modal').detach().appendTo(document.body);
    }

    $(document).ready(function () {
        $(document).on('click', '.lwc-promo-card', function (e) {
            e.preventDefault();
            if (typeof lwcPromoDashboard === 'undefined') {
                return;
            }

            if ($(this).hasClass('is-disabled') || 'true' === $(this).attr('aria-disabled')) {
                return;
            }

            var couponCode = $(this).data('coupon');
            if (!couponCode) {
                return;
            }

            window.localStorage.setItem(storageKey, couponCode);
            var href = $(this).attr('href');
            if ( href ) {
                window.location.href = href;
                return;
            }

            if ( window.location.href.indexOf( lwcPromoDashboard.checkoutUrl ) !== -1 ) {
                applyCouponFromStorage();
                return;
            }
            window.location.href = lwcPromoDashboard.checkoutUrl;
        });

        $(document).on('click', '.lwc-open-coupon-modal', function () {
            couponModalTrigger = this;
            $(this).attr('aria-expanded', 'true');
            $('html, body').addClass('lwc-coupon-modal-open');
            $('#lwc-coupon-modal').prop('hidden', false).find('.lwc-close-coupon-modal').trigger('focus');
            updateCouponSelection();
        });

        $(document).on('click', '.lwc-close-coupon-modal, .lwc-coupon-modal__backdrop', function () {
            $('.lwc-open-coupon-modal').attr('aria-expanded', 'false');
            closeCouponModal();
        });

        $(document).on('updated_checkout wc-blocks_checkout_update checkout_error', function () {
            renderCheckoutCouponPicker();
        });

        $(document).on('change', '.lwc-coupon-option-input', function () {
            updateCouponSelection(this);
        });

        $(document).on('click', '.lwc-add-selected-coupons', function () {
            var modal = $('#lwc-coupon-modal');
            var button = $(this);
            var couponCodes = modal.find('.lwc-coupon-option-input:checked:not(:disabled)').map(function () {
                return this.value;
            }).get();
            if (!couponCodes.length || button.prop('disabled')) {
                return;
            }

            modal.find('.lwc-coupon-option-input').prop('disabled', true);
            button.prop('disabled', true).text('Menambahkan…');
            modal.find('.lwc-coupon-modal__selection').text('Menerapkan ' + couponCodes.length + ' kupon…');
            applyCoupons(couponCodes, function () {
                $('.lwc-open-coupon-modal').attr('aria-expanded', 'false');
                closeCouponModal();
            });
        });

        $(document).on('keydown', function (event) {
            if ('Escape' === event.key) {
                $('.lwc-open-coupon-modal').attr('aria-expanded', 'false');
                closeCouponModal();
            }
        });

        applyCouponFromStorage();
        renderCheckoutCouponPicker();

        if (typeof MutationObserver !== 'undefined' && $('body').length) {
            var checkoutObserver = new MutationObserver(function () {
                if ($('#lwc-checkout-coupon-picker').length === 0) {
                    renderCheckoutCouponPicker();
                }
            });
            checkoutObserver.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: false
            });
        }

        $(document.body).on('updated_checkout wc-blocks_checkout_update checkout_error', renderCheckoutCouponPicker);
        window.setTimeout(function () {
            renderCheckoutCouponPicker();
        }, 1200);
    });
})(jQuery);
