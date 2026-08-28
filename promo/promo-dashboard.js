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

        if ((!coupons.length && !showAccountInvite) || $('#lwc-checkout-coupon-picker').length) {
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

        var modalItems = $.map(coupons, function (coupon) {
            var image = $('<span>').text(coupon.image || '').html();
            var code = $('<span>').text(coupon.code).html();
            var description = $('<span>').text(coupon.description).html();
            return '<button type="button" class="lwc-checkout-coupon-option" data-coupon="' + $('<span>').text(coupon.code).html() + '">' +
                '<img src="' + image + '" alt="" /><span class="lwc-checkout-coupon-option__content"><strong>' + code + '</strong><span>' + description + '</span></span><em>Pilih</em></button>';
        }).join('');

        var couponPicker = coupons.length ?
            '<button type="button" class="lwc-open-coupon-modal" aria-haspopup="dialog" aria-controls="lwc-coupon-modal" aria-expanded="false">Pilih kupon tersedia <span aria-hidden="true">›</span></button>' +
            '<div id="lwc-coupon-modal" class="lwc-coupon-modal" role="dialog" aria-modal="true" aria-labelledby="lwc-coupon-modal-title" hidden>' +
            '<div class="lwc-coupon-modal__backdrop"></div><div class="lwc-coupon-modal__panel">' +
            '<div class="lwc-coupon-modal__header"><div><h3 id="lwc-coupon-modal-title">Pilih kupon</h3><p>Pilih promo untuk diterapkan pada pesanan ini.</p></div><button type="button" class="lwc-close-coupon-modal" aria-label="Tutup">×</button></div>' +
            '<div class="lwc-coupon-modal__list">' + modalItems + '</div></div></div>' : '';
        var accountInvite = showAccountInvite ?
            '<div class="lwc-checkout-account-promo"><span class="lwc-checkout-account-promo__icon" aria-hidden="true">%</span>' +
            '<span class="lwc-checkout-account-promo__content"><strong>Kupon spesial menantimu!</strong><span>Login atau buat akun gratis untuk membuka promo eksklusif.</span></span>' +
            '<a class="lwc-checkout-account-promo__link" href="' + $('<span>').text(lwcPromoDashboard.accountUrl).html() + '">Login / Daftar</a></div>' : '';
        var picker = '<div id="lwc-checkout-coupon-picker" class="lwc-checkout-coupon-picker">' + couponPicker + accountInvite + '</div>';

        if (couponArea.is('.ct-order-review')) {
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
            couponModalTrigger = this;
            $(this).attr('aria-expanded', 'true');
            $('html, body').addClass('lwc-coupon-modal-open');
            $('#lwc-coupon-modal').prop('hidden', false).find('.lwc-close-coupon-modal').trigger('focus');
        });

        $(document).on('click', '.lwc-close-coupon-modal, .lwc-coupon-modal__backdrop', function () {
            $('.lwc-open-coupon-modal').attr('aria-expanded', 'false');
            closeCouponModal();
        });

        $(document).on('updated_checkout wc-blocks_checkout_update checkout_error', function () {
            renderCheckoutCouponPicker();
        });

        $(document).on('click', '.lwc-checkout-coupon-option', function () {
            var couponCode = $(this).data('coupon');
            applyCoupon(couponCode);
            $('.lwc-open-coupon-modal').attr('aria-expanded', 'false');
            closeCouponModal();
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
