(function ($) {
    'use strict';

    var fedexCheckTimer;

    function initDashiconSelectors() {
        $('.lwc-dashicon-choice').on('click', function () {
            $('.lwc-dashicon-choice').removeClass('selected');
            $(this).addClass('selected');
            $('#lwc_menu_icon_class').val($(this).data('icon')).trigger('change');
        });
    }

    function setFedexConnectionStatus(status, label) {
        var statusEl = $('#lwc-fedex-connection-status');
        if (!statusEl.length) {
            return;
        }

        statusEl.attr('data-status', status);
        statusEl.find('.lwc-fedex-status-label').text(label);
    }

    function updateFedexConnectionStatus(triggerAjax) {
        var accountNumber = $('#lwc_fedex_account_number').val().trim();
        var apiKey = $('#lwc_fedex_api_key').val().trim();
        var apiSecret = $('#lwc_fedex_api_secret').val().trim();

        if (triggerAjax) {
            setFedexConnectionStatus('checking', 'Checking credentials...');

            clearTimeout(fedexCheckTimer);
            fedexCheckTimer = setTimeout(function () {
                if (!window.lwcFedexSettings || !window.lwcFedexSettings.ajax_url) {
                    setFedexConnectionStatus('idle', 'Waiting for credentials');
                    return;
                }

                $.ajax({
                    url: window.lwcFedexSettings.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'lwc_check_fedex_connection',
                        nonce: window.lwcFedexSettings.nonce,
                        account_number: accountNumber,
                        api_key: apiKey,
                        api_secret: apiSecret
                    },
                    success: function (response) {
                        if (response && response.success && response.data) {
                            setFedexConnectionStatus(response.data.status, response.data.label);
                        } else {
                            setFedexConnectionStatus('idle', 'Waiting for credentials');
                        }
                    },
                    error: function () {
                        setFedexConnectionStatus('idle', 'Waiting for credentials');
                    }
                });
            }, 300);
            return;
        }

        var status = 'idle';
        var label = 'Waiting for credentials';

        if (accountNumber && apiKey && apiSecret) {
            status = 'connected';
            label = 'Connected (REST API ready)';
        } else if (accountNumber || apiKey || apiSecret) {
            status = 'partial';
            label = 'Incomplete credentials';
        }

        setFedexConnectionStatus(status, label);
    }

    function initPromoImageUploader() {
        var frame;
        var target;

        function openPromoMediaFrame(targetSelector) {
            target = $(targetSelector);
            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Select Promo Image',
                button: {
                    text: 'Use this image'
                },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                if (target && target.length) {
                    target.val(attachment.id).trigger('change');
                    target.closest('.lwc-promo-image-field').find('.lwc-promo-image-preview').html('<img src="' + attachment.url + '" alt="">');
                }
            });
        }

        $('.lwc-promo-image-select').on('click', function (e) {
            e.preventDefault();
            openPromoMediaFrame($(this).data('target'));
        });
    }

    function initPromoDiscountType() {
        function toggleMaximumDiscount() {
            var isPercent = $('#lwc_promo_discount_type').val() === 'percent';
            $('.lwc-percent-only').toggle(isPercent);
            $('.lwc-fixed-only').toggle(!isPercent);
        }

        $('#lwc_promo_discount_type').on('change', toggleMaximumDiscount);
        toggleMaximumDiscount();
    }

    function initFedExCurrencyAdapter() {
        if ($('input[name="lwc_fedex_engine"]').length === 0) {
            return;
        }

        function toggleManualRateField() {
            var engine = $('input[name="lwc_fedex_engine"]:checked').val();
            var mode = $('input[name="lwc_fedex_conversion_mode"]:checked').val();
            $('.lwc-fedex-manual-rate-field').toggle(engine === 'octolize' && mode === 'manual');
        }

        function toggleAdapterSettings() {
            var engine = $('input[name="lwc_fedex_engine"]:checked').val();
            $('.lwc-fedex-adapter-settings').toggle(engine === 'octolize');
            toggleManualRateField();
        }

        $('input[name="lwc_fedex_engine"]').on('change', toggleAdapterSettings);
        $('input[name="lwc_fedex_conversion_mode"]').on('change', toggleManualRateField);
        toggleAdapterSettings();
    }

    $(document).ready(function () {
        if ($('#lwc_menu_icon_class').length) {
            initDashiconSelectors();
        }

        if ($('.lwc-fedex-credential-field').length) {
            $('.lwc-fedex-credential-field').on('input change', function () {
                updateFedexConnectionStatus(true);
            });
            updateFedexConnectionStatus(false);
        }

        if ($('.lwc-promo-image-select').length) {
            initPromoImageUploader();
        }

        if ($('#lwc_promo_discount_type').length) {
            initPromoDiscountType();
        }

        initFedExCurrencyAdapter();
    });
})(jQuery);
