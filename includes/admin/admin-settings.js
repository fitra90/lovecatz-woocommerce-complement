(function ($) {
    'use strict';

    var fedexCheckTimer;
    var fedexCheckSequence = 0;
    var rayspeedCheckTimer;

    function setProviderStatus(statusEl, status, label) {
        if (!statusEl.length) {
            return;
        }
        statusEl.attr('data-status', status);
        statusEl.find('.lwc-provider-status-label').text(label);
    }

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
        var environments = ['sandbox', 'production'];

        function getCredentials(environment) {
            var group = $('.lwc-fedex-credential-group[data-environment="' + environment + '"]');

            return {
                accountNumber: (group.find('[data-credential="account_number"]').val() || '').trim(),
                apiKey: (group.find('[data-credential="api_key"]').val() || '').trim(),
                apiSecret: (group.find('[data-credential="api_secret"]').val() || '').trim()
            };
        }

        function summarize(results) {
            var labels = {sandbox: 'Sandbox', production: 'Production'};
            var connected = environments.filter(function (environment) {
                return results[environment].status === 'connected';
            });
            var failed = environments.filter(function (environment) {
                return results[environment].status === 'auth_failed' || results[environment].status === 'request_failed';
            });
            var incomplete = environments.filter(function (environment) {
                return results[environment].status !== 'connected' && failed.indexOf(environment) === -1;
            });
            var parts = [];

            if (connected.length === environments.length) {
                setFedexConnectionStatus('connected', 'Sandbox & Production connected (REST API ready)');
                return;
            }

            if (failed.length) {
                parts.push(failed.map(function (environment) { return labels[environment]; }).join(' & ') + ' connection failed');
            }
            if (incomplete.length) {
                parts.push(incomplete.map(function (environment) { return labels[environment]; }).join(' & ') + ' credentials incomplete');
            }
            if (connected.length) {
                parts.push(connected.map(function (environment) { return labels[environment]; }).join(' & ') + ' connected');
            }

            setFedexConnectionStatus(failed.length ? 'auth_failed' : 'partial', parts.join('; '));
        }

        if (triggerAjax) {
            fedexCheckSequence += 1;
            var checkSequence = fedexCheckSequence;
            setFedexConnectionStatus('checking', 'Checking Sandbox & Production credentials...');

            clearTimeout(fedexCheckTimer);
            fedexCheckTimer = setTimeout(function () {
                if (!window.lwcShippingSettings || !window.lwcShippingSettings.ajax_url) {
                    setFedexConnectionStatus('idle', 'Waiting for credentials');
                    return;
                }

                var checks = environments.map(function (environment) {
                    var credentials = getCredentials(environment);

                    return $.ajax({
                        url: window.lwcShippingSettings.ajax_url,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'lwc_check_fedex_connection',
                            nonce: window.lwcShippingSettings.nonce,
                            account_number: credentials.accountNumber,
                            api_key: credentials.apiKey,
                            api_secret: credentials.apiSecret,
                            environment: environment
                        }
                    }).then(function (response) {
                        return response && response.success && response.data ? response.data : {status: 'request_failed'};
                    }, function () {
                        return {status: 'request_failed'};
                    });
                });

                $.when.apply($, checks).done(function (sandboxResult, productionResult) {
                    if (checkSequence !== fedexCheckSequence) {
                        return;
                    }
                    summarize({sandbox: sandboxResult, production: productionResult});
                });
            }, 300);
            return;
        }

        var preview = {};
        environments.forEach(function (environment) {
            var credentials = getCredentials(environment);
            preview[environment] = {
                status: credentials.accountNumber && credentials.apiKey && credentials.apiSecret ? 'partial' : 'idle'
            };
        });
        summarize(preview);
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

    function updateRaySpeedConnectionStatus() {
        var status = $('#lwc-rayspeed-connection-status');
        if (!status.length) {
            return;
        }
        setProviderStatus(status, 'checking', (window.lwcShippingSettings && lwcShippingSettings.checking) || 'Checking credentials...');
        clearTimeout(rayspeedCheckTimer);
        rayspeedCheckTimer = setTimeout(function () {
            $.post(window.lwcShippingSettings.ajax_url, {
                action: 'lwc_check_rayspeed_connection',
                nonce: window.lwcShippingSettings.nonce,
                api_key: $('#lwc_rayspeed_api_key').val()
            }).done(function (response) {
                var data = response && response.data ? response.data : {};
                setProviderStatus(status, response.success && data.status ? data.status : 'request_failed', data.label || data.message || lwcShippingSettings.requestFailed);
            }).fail(function () {
                setProviderStatus(status, 'request_failed', lwcShippingSettings.requestFailed || 'Connection request failed.');
            });
        }, 300);
    }

    $(document).ready(function () {
        if ($('#lwc_menu_icon_class').length) {
            initDashiconSelectors();
        }

        if ($('.lwc-fedex-credential-field').length) {
            $('.lwc-fedex-credential-field').on('input change', function () {
                updateFedexConnectionStatus(true);
            });

			updateFedexConnectionStatus(true);
        }


        if ($('.lwc-promo-image-select').length) {
            initPromoImageUploader();
        }

        if ($('#lwc_promo_discount_type').length) {
            initPromoDiscountType();
        }

        if ($('.lwc-rayspeed-credential-field').length) {
            $('.lwc-rayspeed-credential-field').on('input change', updateRaySpeedConnectionStatus);
            updateRaySpeedConnectionStatus();
        }

	});
})(jQuery);
