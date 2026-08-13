(function ($) {
    'use strict';

    var storageKey = 'lwc_promo_coupon_code';

    function getCouponInput() {
        return $(lwcPromoDashboard.couponInputSelector).first();
    }

    function applyCoupon(couponCode) {
        var attempts = 0;
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
                        applyButton.get(0).click();
                    } else if (attempts < 20) {
                        attempts += 1;
                        window.setTimeout(applyWhenReady, 100);
                    }
                }, 100);
                return;
            }

            attempts += 1;
            if (attempts < 20) {
                window.setTimeout(applyWhenReady, 100);
            }
        };

        applyWhenReady();
        return true;
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

    function renderCheckoutCouponPicker() {
        if (!lwcPromoDashboard.coupons || !lwcPromoDashboard.coupons.length || $('#lwc-checkout-coupon-picker').length) {
            return;
        }

        var couponArea = $('.wc-block-components-totals-coupon').first();
        if (!couponArea.length) {
            return;
        }

        var modalItems = $.map(lwcPromoDashboard.coupons, function (coupon) {
            var image = $('<span>').text(coupon.image || '').html();
            var code = $('<span>').text(coupon.code).html();
            var description = $('<span>').text(coupon.description).html();
            return '<button type="button" class="lwc-checkout-coupon-option" data-coupon="' + $('<span>').text(coupon.code).html() + '">' +
                '<img src="' + image + '" alt="" /><span class="lwc-checkout-coupon-option__content"><strong>' + code + '</strong><span>' + description + '</span></span><em>Pilih</em></button>';
        }).join('');

        var picker = '<div id="lwc-checkout-coupon-picker" class="lwc-checkout-coupon-picker">' +
            '<button type="button" class="lwc-open-coupon-modal" aria-haspopup="dialog">Pilih kupon tersedia <span aria-hidden="true">›</span></button>' +
            '<div class="lwc-coupon-modal" role="dialog" aria-modal="true" aria-labelledby="lwc-coupon-modal-title" hidden>' +
            '<div class="lwc-coupon-modal__backdrop"></div><div class="lwc-coupon-modal__panel">' +
            '<div class="lwc-coupon-modal__header"><div><h3 id="lwc-coupon-modal-title">Pilih kupon</h3><p>Pilih promo untuk diterapkan pada pesanan ini.</p></div><button type="button" class="lwc-close-coupon-modal" aria-label="Tutup">×</button></div>' +
            '<div class="lwc-coupon-modal__list">' + modalItems + '</div></div></div></div>';
        couponArea.append(picker);
    }

    $(document).ready(function () {
        $(document).on('click', '.lwc-promo-card', function (e) {
            e.preventDefault();
            if (typeof lwcPromoDashboard === 'undefined') {
                return;
            }

            var couponCode = $(this).data('coupon');
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
            $(this).siblings('.lwc-coupon-modal').prop('hidden', false).find('.lwc-close-coupon-modal').trigger('focus');
        });

        $(document).on('click', '.lwc-close-coupon-modal, .lwc-coupon-modal__backdrop', function () {
            $(this).closest('.lwc-coupon-modal').prop('hidden', true);
        });

        $(document).on('click', '.lwc-checkout-coupon-option', function () {
            var couponCode = $(this).data('coupon');
            applyCoupon(couponCode);
            $(this).closest('.lwc-coupon-modal').prop('hidden', true);
        });

        $(document).on('keydown', function (event) {
            if ('Escape' === event.key) {
                $('.lwc-coupon-modal').prop('hidden', true);
            }
        });

        applyCouponFromStorage();
        renderCheckoutCouponPicker();
        $(document.body).on('updated_checkout wc-blocks_checkout_update', renderCheckoutCouponPicker);
    });
})(jQuery);
