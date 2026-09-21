(function ($) {
    'use strict';

    var fedexCheckTimer;
    var fedexCheckSequence = 0;
    var jtCheckTimer;
    var jtCheckSequence = 0;
    var jtcCheckTimer;
    var jtcCheckSequence = 0;
    var rayspeedCheckTimer;

    function setProviderStatus(statusEl, status, label) {
        if (!statusEl.length) {
            return;
        }
        statusEl.attr('data-status', status);
        statusEl.find('.lwc-provider-status-label').text(label);
    }

    function setJtServiceStatus(statusEl, result) {
        setProviderStatus(statusEl, result.status || 'partial', result.label || 'Not verified.');
        statusEl.find('.lwc-jt-api-exchange').remove();
        if (result.exchange && typeof result.exchange === 'object') {
            var details = $('<details class="lwc-jt-api-exchange"><summary>Original J&T request / response</summary><pre></pre></details>');
            details.find('pre').text(JSON.stringify(result.exchange, null, 2));
            statusEl.append(details);
        }
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
        var targets = ['sandbox', 'production', 'tracking'];

        function getCredentials(environment) {
            var group = $('.lwc-fedex-credential-group[data-environment="' + environment + '"]');

            return {
                accountNumber: (group.find('[data-credential="account_number"]').val() || '').trim(),
                apiKey: (group.find('[data-credential="api_key"]').val() || '').trim(),
                apiSecret: (group.find('[data-credential="api_secret"]').val() || '').trim()
            };
        }

        function getTrackingCredentials() {
            var group = $('.lwc-fedex-tracking-credential-group');

            return {
                accountNumber: (group.find('[data-credential="account_number"]').val() || '').trim(),
                apiKey: (group.find('[data-credential="api_key"]').val() || '').trim(),
                apiSecret: (group.find('[data-credential="api_secret"]').val() || '').trim()
            };
        }

        function summarize(results) {
            var labels = {sandbox: 'Sandbox', production: 'Production', tracking: 'Tracking API'};
            var connected = targets.filter(function (target) {
                return results[target].status === 'connected';
            });
            var failed = targets.filter(function (target) {
                return results[target].status === 'auth_failed' || results[target].status === 'request_failed';
            });
            var incomplete = targets.filter(function (target) {
                return results[target].status !== 'connected' && failed.indexOf(target) === -1;
            });
            var parts = [];

            if (connected.length === targets.length) {
                setFedexConnectionStatus('connected', 'Sandbox, Production & Tracking API connected (REST API ready)');
                return;
            }

            if (failed.length) {
				parts.push(failed.map(function (target) {
					var detail = target === 'tracking' && results[target].label ? ': ' + results[target].label : '';
					return labels[target] + ' connection failed' + detail;
				}).join('; '));
            }
            if (incomplete.length) {
                parts.push(incomplete.map(function (target) { return labels[target]; }).join(' & ') + ' credentials incomplete');
            }
            if (connected.length) {
                parts.push(connected.map(function (target) { return labels[target]; }).join(' & ') + ' connected');
            }

            setFedexConnectionStatus(failed.length ? 'auth_failed' : 'partial', parts.join('; '));
        }

        if (triggerAjax) {
            fedexCheckSequence += 1;
            var checkSequence = fedexCheckSequence;
            setFedexConnectionStatus('checking', 'Checking Sandbox, Production & Tracking API credentials...');

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

				var trackingCredentials = getTrackingCredentials();
				checks.push($.ajax({
					url: window.lwcShippingSettings.ajax_url,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'lwc_check_fedex_connection',
						nonce: window.lwcShippingSettings.nonce,
						service: 'tracking',
						account_number: trackingCredentials.accountNumber,
						api_key: trackingCredentials.apiKey,
						api_secret: trackingCredentials.apiSecret,
						environment: 'production'
					}
				}).then(function (response) {
					return response && response.success && response.data ? response.data : {status: 'request_failed'};
				}, function () {
					return {status: 'request_failed'};
				}));

                $.when.apply($, checks).done(function (sandboxResult, productionResult, trackingResult) {
                    if (checkSequence !== fedexCheckSequence) {
                        return;
                    }
                    summarize({sandbox: sandboxResult, production: productionResult, tracking: trackingResult});
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
		var trackingCredentials = getTrackingCredentials();
		preview.tracking = {
			status: trackingCredentials.apiKey && trackingCredentials.apiSecret ? 'partial' : 'idle'
		};
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
			var discountType = $('#lwc_promo_discount_type').val();
			var isPercent = discountType === 'percent';
			var isFixed = discountType === 'fixed_cart';
			var hasMaximum = isPercent || discountType === 'lwc_free_shipping';
            $('.lwc-percent-only').toggle(isPercent);
			$('.lwc-fixed-only').toggle(isFixed);
			$('.lwc-maximum-only').toggle(hasMaximum);
        }

        $('#lwc_promo_discount_type').on('change', toggleMaximumDiscount);
        toggleMaximumDiscount();
    }

    function initPromoEligibility() {
        var allUsers = $('#lwc_promo_all_users');
        var selectedUsers = $('#lwc_promo_selected_users');
        var userSelect = $('#lwc_promo_eligible_user_ids');

        if (!allUsers.length || !selectedUsers.length || !userSelect.length) {
            return;
        }

        function toggleEligibleUsers() {
            var isAllUsers = allUsers.is(':checked');
            selectedUsers.prop('hidden', isAllUsers).attr('aria-hidden', isAllUsers ? 'true' : 'false');
            userSelect.prop('disabled', isAllUsers);
            allUsers.attr('aria-expanded', isAllUsers ? 'false' : 'true');

            if (!isAllUsers) {
                $(document.body).trigger('wc-enhanced-select-init');
            }
        }

        allUsers.on('change', toggleEligibleUsers);
        toggleEligibleUsers();
    }

    function initPromoCombination() {
        var toggle = $('#lwc_promo_allow_combination');
        var choices = $('#lwc_promo_combination_coupons');

        function updateVisibility() {
            choices.prop('hidden', !toggle.prop('checked'));
        }

        toggle.on('change', function () {
            if (toggle.prop('checked') && !choices.find('input[name="combination_coupon_ids[]"]:checked').length) {
                choices.find('input[name="combination_coupon_ids[]"]:not(:disabled)').prop('checked', true);
            }
            updateVisibility();
        });
        choices.on('click', '.lwc-promo-combination-all', function () {
            choices.find('input[name="combination_coupon_ids[]"]:not(:disabled)').prop('checked', true);
        });
        choices.on('click', '.lwc-promo-combination-none', function () {
            choices.find('input[name="combination_coupon_ids[]"]').prop('checked', false);
        });
        updateVisibility();
    }

    function initPreorderScope() {
        var allProducts = $('#lwc_preorder_all_products');
        var selectedProducts = $('#lwc-preorder-selected-products');
        var productSelect = $('#lwc_preorder_product_ids');

        if (!allProducts.length || !selectedProducts.length || !productSelect.length) {
            return;
        }

        function togglePreorderProducts() {
            var useAllProducts = allProducts.is(':checked');

            selectedProducts.prop('hidden', useAllProducts).attr('aria-hidden', useAllProducts ? 'true' : 'false');
            productSelect.prop('disabled', useAllProducts);
            allProducts.attr('aria-expanded', useAllProducts ? 'false' : 'true');

            if (!useAllProducts) {
                $(document.body).trigger('wc-enhanced-select-init');
            }
        }

        allProducts.on('change', togglePreorderProducts);
        togglePreorderProducts();
    }

    function updateJtConnectionStatus(runCheck) {
        var statusList = $('.lwc-jt-connection-status');
        var environmentSelect = $('#lwc_jt_express_environment');
        if (!statusList.length) {
            return;
        }

        jtCheckSequence += 1;
        var sequence = jtCheckSequence;
        var environment = environmentSelect.val() === 'production' ? 'production' : 'sandbox';
        var environmentLabel = environment === 'production' ? 'Production:' : 'Sandbox:';
        var statusEl = statusList.find('.lwc-jt-summary-status').first();
        var group = $('.lwc-jt-credential-group[data-provider="express"][data-environment="' + environment + '"]');

        statusEl.attr('data-environment', environment);
        statusEl.find('.lwc-jt-active-environment-label').text(environmentLabel);
        if (!runCheck) {
            setProviderStatus(statusEl, 'partial', 'Credentials changed. Save settings, then check the active API services.');
            statusList.find('.lwc-jt-service-status').each(function () {
                setProviderStatus($(this), 'partial', 'Waiting for a new check.');
            });
            return;
        }

        setProviderStatus(statusEl, 'checking', (window.lwcShippingSettings && lwcShippingSettings.checking) || 'Checking credentials...');
        statusList.find('.lwc-jt-service-status').each(function () {
            setProviderStatus($(this), 'checking', 'Checking...');
        });

        clearTimeout(jtCheckTimer);
        jtCheckTimer = setTimeout(function () {
            if (!window.lwcShippingSettings || !window.lwcShippingSettings.ajax_url) {
                setProviderStatus(statusEl, 'request_failed', 'Connection request failed.');
                return;
            }

            var credentials = {};
            group.find('[data-credential]').each(function () {
                credentials[$(this).data('credential')] = ($(this).val() || '').trim();
            });

            $.ajax({
                url: window.lwcShippingSettings.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'lwc_check_jt_connection',
                    nonce: window.lwcShippingSettings.nonce,
                    environment: environment,
                    credentials: credentials
                }
            }).done(function (response) {
                if (sequence !== jtCheckSequence) {
                    return;
                }
                var data = response && response.data ? response.data : {};
                setProviderStatus(statusEl, response.success && data.status ? data.status : 'request_failed', data.label || data.message || lwcShippingSettings.requestFailed);
                if (data.services) {
                    $.each(data.services, function (service, result) {
                        setJtServiceStatus(statusList.find('.lwc-jt-service-status[data-service="' + service + '"]'), result);
                    });
                }
            }).fail(function () {
                if (sequence === jtCheckSequence) {
                    setProviderStatus(statusEl, 'request_failed', lwcShippingSettings.requestFailed || 'Connection request failed.');
                }
            });
        }, 500);
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

    function updateJtcHealthStatus() {
        var statusEl = $('#lwc-jtc-health-status');
        if (!statusEl.length) {
            return;
        }

        var environment = $('#lwc_jt_cargo_environment').val() === 'production' ? 'production' : 'sandbox';
        var group = $('.lwc-jtc-credential-group[data-environment="' + environment + '"]');
        var uuid = (group.find('[data-credential="uuid"]').val() || '').trim();
        var sequence = ++jtcCheckSequence;

        $('.lwc-jtc-environment-label').text(environment === 'production' ? 'Production:' : 'Sandbox:');
        setProviderStatus(statusEl, 'checking', (window.lwcShippingSettings && lwcShippingSettings.checking) || 'Checking API...');
        clearTimeout(jtcCheckTimer);
        jtcCheckTimer = setTimeout(function () {
            $.ajax({
                url: window.lwcShippingSettings.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'lwc_check_jtc_connection',
                    nonce: window.lwcShippingSettings.nonce,
                    environment: environment,
                    uuid: uuid
                }
            }).done(function (response) {
                if (sequence !== jtcCheckSequence) {
                    return;
                }
                var data = response && response.data ? response.data : {};
                setProviderStatus(statusEl, response.success && data.status ? data.status : 'request_failed', data.label || data.message || lwcShippingSettings.requestFailed);
            }).fail(function () {
                if (sequence === jtcCheckSequence) {
                    setProviderStatus(statusEl, 'request_failed', lwcShippingSettings.requestFailed || 'Connection request failed.');
                }
            });
        }, 400);
    }

    function initJtcSandboxConsole() {
        var interfaceSelect = $('#lwc-jtc-test-interface');
        var methodSelect = $('#lwc-jtc-test-method');
        var payloadInput = $('#lwc-jtc-test-payload');
        var headersInput = $('#lwc-jtc-test-headers');
        var responseOutput = $('#lwc-jtc-test-response');
        var viewOutput = $('#lwc-jtc-test-view');
        var statusEl = $('#lwc-jtc-test-status');
        var sendButton = $('#lwc-jtc-send-test');

        function updateProgress(progress) {
            if (!progress || !progress.interfaces) {
                return;
            }
            $.each(progress.interfaces, function (name, item) {
                var row = $('.lwc-jtc-progress-table tr[data-interface="' + name + '"]');
                row.attr('data-complete', parseInt(item.successes, 10) === 3 ? 'yes' : 'no');
                row.find('.lwc-jtc-progress-success strong').text((parseInt(item.successes, 10) || 0) + '/3');
                row.find('.lwc-jtc-progress-attempts').text(parseInt(item.attempts, 10) || 0);
                var lastHttp = parseInt(item.last_http, 10) || 0;
                var lastResult = lastHttp ? 'HTTP ' + lastHttp : '—';
                if (lastHttp && item.last_business_code) {
                    lastResult += ' · API ' + item.last_business_code;
                }
                row.find('.lwc-jtc-progress-http').text(lastResult);
                row.find('.lwc-jtc-progress-time').text(item.last_tested_at || '—');
            });
            setProviderStatus(
                $('#lwc-jtc-local-progress'),
                progress.local_complete ? 'connected' : 'partial',
                progress.completed_endpoints + '/' + progress.total_endpoints + ' endpoints complete · ' + progress.successful_hits + '/' + progress.required_hits + ' successful API hits'
            );
        }

        function updateEndpoint() {
            $('#lwc-jtc-test-url').val(interfaceSelect.find(':selected').data('url') || '');
        }

        function parseEditor(editor, label) {
            var value = (editor.val() || '').trim();
            if (!value) {
                return {};
            }
            try {
                return JSON.parse(value);
            } catch (error) {
                throw new Error(label + ': ' + error.message);
            }
        }

        interfaceSelect.on('change', updateEndpoint);
        methodSelect.on('change', updateEndpoint);
        updateEndpoint();

        sendButton.on('click', function () {
            try {
                parseEditor(payloadInput, 'Payload JSON');
                parseEditor(headersInput, 'Headers JSON');
            } catch (error) {
                setProviderStatus(statusEl, 'request_failed', error.message);
                responseOutput.text('');
                viewOutput.empty();
                return;
            }

            sendButton.prop('disabled', true);
            setProviderStatus(statusEl, 'checking', 'Sending Sandbox request...');
            responseOutput.text('');
            $.ajax({
                url: window.lwcShippingSettings.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'lwc_jtc_sandbox_request',
                    nonce: window.lwcShippingSettings.nonce,
                    interface: interfaceSelect.val(),
                    method: methodSelect.val(),
					format: 'form',
                    payload: payloadInput.val(),
                    headers: headersInput.val()
                }
            }).done(function (response) {
                var data = response && response.data ? response.data : {};
                updateProgress(data.progress);
                if (!response.success) {
                    setProviderStatus(statusEl, 'request_failed', data.message || 'Sandbox request failed.');
                    responseOutput.text(JSON.stringify(data, null, 2));
                    viewOutput.html(data.view_html || '');
                    return;
                }
                var code = parseInt(data.http_status, 10) || 0;
				var status = data.business_success ? 'connected' : (code >= 500 || !code ? 'request_failed' : 'partial');
				var businessLabel = data.business_code ? ' · API ' + data.business_code : '';
				setProviderStatus(statusEl, status, 'HTTP ' + code + businessLabel + ' · ' + data.elapsed_ms + ' ms');
                responseOutput.text(JSON.stringify(data, null, 2));
                viewOutput.html(data.view_html || '');
            }).fail(function (xhr) {
                var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
                updateProgress(data.progress);
                setProviderStatus(statusEl, 'request_failed', data.message || 'Sandbox request failed.');
                responseOutput.text(JSON.stringify(data, null, 2));
                viewOutput.html(data.view_html || '');
            }).always(function () {
                sendButton.prop('disabled', false);
            });
        });
    }

    $(document).ready(function () {
        if ($('#lwc_menu_icon_class').length) {
            initDashiconSelectors();
        }

        if ($('.lwc-fedex-credential-field').length) {
			$('.lwc-fedex-credential-field, .lwc-fedex-tracking-credential-field').on('input change', function () {
                updateFedexConnectionStatus(true);
            });

			updateFedexConnectionStatus(true);
        }

		if ($('.lwc-jt-connection-status').length) {
			$('.lwc-jt-credential-field').on('input change', function () {
				var activeEnvironment = $('#lwc_jt_express_environment').val() === 'production' ? 'production' : 'sandbox';
				if ($(this).closest('.lwc-jt-credential-group').data('environment') === activeEnvironment) {
					updateJtConnectionStatus(false);
				}
			});
			$('#lwc_jt_express_environment').on('change', function () { updateJtConnectionStatus(true); });
			$('#lwc-jt-check-services').on('click', function () { updateJtConnectionStatus(true); });
			updateJtConnectionStatus(true);
		}

		if ($('.lwc-jtc-connection-status').length) {
			$('.lwc-jtc-credential-field[data-credential="uuid"], #lwc_jt_cargo_environment').on('input change', updateJtcHealthStatus);
			$('#lwc-jtc-check-health').on('click', updateJtcHealthStatus);
			updateJtcHealthStatus();
		}

		if ($('.lwc-jtc-sandbox-console').length) {
			initJtcSandboxConsole();
		}

        if ($('.lwc-promo-image-select').length) {
            initPromoImageUploader();
        }

        if ($('#lwc_promo_discount_type').length) {
            initPromoDiscountType();
        }

        if ($('#lwc_promo_all_users').length) {
            initPromoEligibility();
        }

        if ($('#lwc_promo_allow_combination').length) {
            initPromoCombination();
        }

        if ($('#lwc_preorder_all_products').length) {
            initPreorderScope();
        }

        if ($('.lwc-rayspeed-credential-field').length) {
            $('.lwc-rayspeed-credential-field').on('input change', updateRaySpeedConnectionStatus);
            updateRaySpeedConnectionStatus();
        }

	});
})(jQuery);
