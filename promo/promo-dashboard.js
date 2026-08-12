(function ($) {
    'use strict';

    var storageKey = 'lwc_promo_coupon_code';

    function applyCouponFromStorage() {
        if (typeof lwcPromoDashboard === 'undefined') {
            return;
        }

        var couponCode = window.localStorage.getItem(storageKey);
        if (!couponCode) {
            return;
        }

        var couponInput = $(lwcPromoDashboard.couponInputSelector);
        if (couponInput.length) {
            couponInput.val(couponCode);
            var applyButton = $(lwcPromoDashboard.applyButtonSelector);
            if (applyButton.length) {
                applyButton.trigger('click');
            }
            window.localStorage.removeItem(storageKey);
        }
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

        applyCouponFromStorage();
    });
})(jQuery);
