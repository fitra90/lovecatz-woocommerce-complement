(function ($) {
    'use strict';

    $(function () {
        var cfg = window.lwcFedexOrder;
        if (!cfg || !cfg.order_id) {
            return;
        }

        var statusEl = $('#lwc-fedex-order-status');
        var trackingEl = $('#lwc-fedex-tracking');
        var downloadLink = $('#lwc-fedex-download-link');
        var removedOrderItemIds = [];

        function setStatus(message, state) {
            statusEl
                .removeClass('is-ok is-error is-checking')
                .addClass('is-' + state)
                .text(message)
                .removeAttr('hidden');
        }

        function clearStatus() {
            statusEl.attr('hidden', true).text('');
        }

        function getPackageValues() {
            return {
                weight: $('#lwc_fedex_package_weight').val(),
                length: $('#lwc_fedex_package_length').val(),
                width: $('#lwc_fedex_package_width').val(),
				height: $('#lwc_fedex_package_height').val()
            };
        }

        function getManifest() {
            var itemIds = [];
            var extraProductIds = [];
            $('.lwc-fedex-item-checkbox:checked').each(function () { itemIds.push($(this).val()); });
            $('.lwc-fedex-extra-product:checked').each(function () { extraProductIds.push($(this).val()); });
            return { itemIds: itemIds, extraProductIds: extraProductIds };
        }

        function validatePackage(packageValues, manifest) {
            var hasCustomWeight = packageValues.weight !== '';
            var dimensionKeys = ['length', 'width', 'height'];
            var suppliedDimensions = dimensionKeys.filter(function (key) { return packageValues[key] !== ''; });
            if (!manifest.itemIds.length && !manifest.extraProductIds.length) {
                setStatus(cfg.i18n.no_items, 'error');
                return false;
            }
            if (suppliedDimensions.length && (!hasCustomWeight || suppliedDimensions.length !== dimensionKeys.length)) {
                setStatus(cfg.i18n.package_incomplete, 'error');
                return false;
            }
            if ((hasCustomWeight && Number(packageValues.weight) <= 0) || suppliedDimensions.some(function (key) { return Number(packageValues[key]) <= 0; })) {
                setStatus(cfg.i18n.package_invalid, 'error');
                return false;
            }
            return true;
        }

        function requestPackageData(packageValues, manifest) {
            return {
                order_id: cfg.order_id,
                manifest_mode: '1',
				service_type: $('#lwc_fedex_service_type').val(),
                item_ids: manifest.itemIds,
                extra_product_ids: manifest.extraProductIds,
                replaced_item_ids: manifest.extraProductIds.length ? removedOrderItemIds : [],
                package_weight: packageValues.weight,
                package_length: packageValues.length,
				package_width: packageValues.width,
				package_height: packageValues.height
            };
        }

        function updateCreateButton() {
            var manifest = getManifest();
            $('#lwc_fedex_create_label_btn').prop('disabled', !manifest.itemIds.length && !manifest.extraProductIds.length);
        }

        function formatRate(quote) {
            var currency = quote.currency || cfg.currency || '';
            try {
                return new Intl.NumberFormat(undefined, { style: currency ? 'currency' : 'decimal', currency: currency || undefined, maximumFractionDigits: currency === 'IDR' ? 0 : 2 }).format(quote.rate);
            } catch (e) {
                return (currency ? currency + ' ' : '') + quote.rate;
            }
        }

        function resetProductSearch(search) {
            search.empty().val(null).trigger('change');
            search.prop('disabled', false);
            window.setTimeout(function () {
                var selection = search.next('.select2-container').find('.select2-selection').first();
                if (selection.length) {
                    selection.trigger('focus');
                }
            }, 0);
        }

        $('#lwc_fedex_quote_btn').on('click', function () {
            var btn = $(this);
            var packageValues = getPackageValues();
            var manifest = getManifest();
            if (!validatePackage(packageValues, manifest)) {
                return;
            }
            btn.prop('disabled', true);
            setStatus(cfg.i18n.checking, 'checking');

            var data = requestPackageData(packageValues, manifest);
            $.extend(data, {
                action: 'lwc_fedex_get_rate_quote',
                nonce: cfg.nonce,
                country: cfg.address.country,
                state: cfg.address.state,
                postcode: cfg.address.postcode,
                city: cfg.address.city
            });
            $.post(cfg.ajax_url, data).done(function (res) {
                btn.prop('disabled', false);
                if (res && res.success && res.quotes && res.quotes.length) {
                    var lines = [];
                    var context = res.quote_context || {};
                    var dimensions = context.length ? ' · ' + context.length + ' × ' + context.width + ' × ' + context.height + ' cm' : '';
                    lines.push((context.weight ? context.weight + ' kg' : cfg.i18n.estimated_weight) + dimensions + ' · ' + context.item_count + ' item');
                    $.each(res.quotes, function (i, quote) {
                        lines.push(quote.label + ': ' + formatRate(quote));
                    });
                    setStatus(lines.join('\n'), 'ok');
                } else if (res && res.success && typeof res.rate !== 'undefined') {
                    setStatus(cfg.i18n.quote_prefix + res.rate, 'ok');
                } else {
                    setStatus((res && res.message) || cfg.i18n.quote_failed, 'error');
                }
            }).fail(function () {
                btn.prop('disabled', false);
                setStatus(cfg.i18n.request_failed, 'error');
            });
        });

        $('#lwc_fedex_create_label_btn').on('click', function () {
            var btn = $(this);
            var packageValues = getPackageValues();
            var manifest = getManifest();
            if (!validatePackage(packageValues, manifest)) {
                return;
            }

            btn.prop('disabled', true);
            setStatus(cfg.i18n.creating, 'checking');

            var data = requestPackageData(packageValues, manifest);
            $.extend(data, {
                action: 'lwc_fedex_create_shipment',
                nonce: cfg.nonce
            });
            $.post(cfg.ajax_url, data).done(function (res) {
                btn.prop('disabled', false);
                if (res && res.success) {
                    trackingEl.text(res.tracking_number || '—');
                    if (res.label_url && downloadLink.length) {
                        downloadLink.attr('href', res.label_url).removeAttr('hidden');
                    }
                    setStatus(cfg.i18n.created, 'ok');
                    // Reload so the shipped-item marks and shipment history refresh.
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    setStatus((res && res.message) || cfg.i18n.create_failed, 'error');
                }
            }).fail(function () {
                btn.prop('disabled', false);
                setStatus(cfg.i18n.request_failed, 'error');
            });
        });

        $('#lwc-fedex-package-items').on('click', '.lwc-fedex-remove-package-item', function () {
            var row = $(this).closest('.lwc-fedex-item');
            var orderItemId = row.find('.lwc-fedex-item-checkbox').val();
            if (orderItemId && removedOrderItemIds.indexOf(String(orderItemId)) === -1) {
                removedOrderItemIds.push(String(orderItemId));
            }
            row.remove();
            updateCreateButton();
            clearStatus();
        });

        $('#lwc-fedex-package-items').on('change', 'input[type="checkbox"]', function () {
            updateCreateButton();
            clearStatus();
        });

        $('#lwc_fedex_add_package_item').on('click', function () {
            var search = $('#lwc_fedex_product_search');
            var productId = String(search.val() || '');
            if (!productId) {
                setStatus(cfg.i18n.select_product, 'error');
                return;
            }
            var selected = search.select2 && search.select2('data');
            var productName = selected && selected[0] ? selected[0].text : search.find('option:selected').text();
            var row = $('<div>', { 'class': 'lwc-fedex-item is-catalog', 'data-product-id': productId });
            row.append($('<input>', { type: 'checkbox', 'class': 'lwc-fedex-extra-product', value: productId, checked: true }));
            row.append($('<span>').text(productName + ' × 1').append($('<em>').text(' ' + cfg.i18n.catalog_item)));
            row.append($('<button>', { type: 'button', 'class': 'button-link-delete lwc-fedex-remove-package-item', text: cfg.i18n.remove_item }));
            $('#lwc-fedex-package-items').append(row);
            resetProductSearch(search);
            updateCreateButton();
            clearStatus();
        });

        updateCreateButton();

        $('.lwc-fedex-cancel-shipment').on('click', function () {
            var btn = $(this);
            var tracking = String(btn.data('tracking') || '');
            if (!window.confirm(cfg.i18n.confirm_awb.replace('%s', tracking))) {
                return;
            }

            btn.prop('disabled', true);
            setStatus(cfg.i18n.cancel_awb, 'checking');
            $.post(cfg.ajax_url, {
                action: 'lwc_fedex_cancel_shipment',
                nonce: cfg.nonce,
                order_id: cfg.order_id,
                shipment: btn.data('shipment')
            }).done(function (res) {
                if (res && res.success) {
                    setStatus(res.message, 'ok');
                    window.setTimeout(function () { window.location.reload(); }, 1000);
                } else {
                    btn.prop('disabled', false);
                    setStatus((res && res.message) || cfg.i18n.request_failed, 'error');
                }
            }).fail(function () {
                btn.prop('disabled', false);
                setStatus(cfg.i18n.request_failed, 'error');
            });
        });

        $('#lwc_fedex_refresh_tracking_btn').on('click', function () {
            var btn = $(this);
            btn.prop('disabled', true);
            setStatus(cfg.i18n.tracking, 'checking');
            $.post(cfg.ajax_url, {
                action: 'lwc_fedex_refresh_tracking',
                nonce: cfg.nonce,
                order_id: cfg.order_id
            }).done(function (res) {
                btn.prop('disabled', false);
                if (res && res.success) {
                    if (res.html) {
                        $('#lwc-fedex-tracking-content').html(res.html);
                    }
                    setStatus(cfg.i18n.tracking_ok, 'ok');
                } else {
                    setStatus((res && res.message) || cfg.i18n.request_failed, 'error');
                }
            }).fail(function () {
                btn.prop('disabled', false);
                setStatus(cfg.i18n.request_failed, 'error');
            });
        });

        function pickupData(action) {
            return {
                action: action,
                nonce: cfg.nonce,
                order_id: cfg.order_id,
                date: $('#lwc_fedex_pickup_date').val(),
                ready_time: $('#lwc_fedex_pickup_ready').val(),
                close_time: $('#lwc_fedex_pickup_close').val(),
                carrier: $('#lwc_fedex_pickup_carrier').val()
            };
        }

        $('#lwc_fedex_check_pickup_btn').on('click', function () {
            var btn = $(this);
            var scheduleBtn = $('#lwc_fedex_schedule_pickup_btn');
            btn.prop('disabled', true);
            scheduleBtn.prop('disabled', true);
            setStatus(cfg.i18n.pickup_check, 'checking');
            $.post(cfg.ajax_url, pickupData('lwc_fedex_pickup_availability')).done(function (res) {
                btn.prop('disabled', false);
                if (res && res.success) {
                    scheduleBtn.prop('disabled', false);
                    var message = res.message || cfg.i18n.pickup_ok;
                    if (res.options && res.options.length && res.options[0].cutoff_time) {
                        message += '\nCutoff: ' + res.options[0].cutoff_time;
                    }
                    setStatus(message, 'ok');
                } else {
                    setStatus((res && res.message) || cfg.i18n.request_failed, 'error');
                }
            }).fail(function () {
                btn.prop('disabled', false);
                setStatus(cfg.i18n.request_failed, 'error');
            });
        });

        $('.lwc-fedex-pickup-fields input, .lwc-fedex-pickup-fields select').on('change', function () {
            $('#lwc_fedex_schedule_pickup_btn').prop('disabled', true);
        });

        $('#lwc_fedex_schedule_pickup_btn').on('click', function () {
            if (!window.confirm(cfg.i18n.confirm_pickup)) {
                return;
            }
            var btn = $(this);
            btn.prop('disabled', true);
            setStatus(cfg.i18n.pickup_create, 'checking');
            $.post(cfg.ajax_url, pickupData('lwc_fedex_schedule_pickup')).done(function (res) {
                if (res && res.success) {
                    setStatus(res.message || cfg.i18n.pickup_ok, 'ok');
                    window.setTimeout(function () { window.location.reload(); }, 1000);
                } else {
                    btn.prop('disabled', false);
                    setStatus((res && res.message) || cfg.i18n.request_failed, 'error');
                }
            }).fail(function () {
                btn.prop('disabled', false);
                setStatus(cfg.i18n.request_failed, 'error');
            });
        });

        $('#lwc_fedex_cancel_pickup_btn').on('click', function () {
            if (!window.confirm(cfg.i18n.confirm_cancel)) {
                return;
            }
            var btn = $(this);
            btn.prop('disabled', true);
            setStatus(cfg.i18n.pickup_cancel, 'checking');
            $.post(cfg.ajax_url, {
                action: 'lwc_fedex_cancel_pickup',
                nonce: cfg.nonce,
                order_id: cfg.order_id
            }).done(function (res) {
                if (res && res.success) {
                    setStatus(res.message, 'ok');
                    window.setTimeout(function () { window.location.reload(); }, 1000);
                } else {
                    btn.prop('disabled', false);
                    setStatus((res && res.message) || cfg.i18n.request_failed, 'error');
                }
            }).fail(function () {
                btn.prop('disabled', false);
                setStatus(cfg.i18n.request_failed, 'error');
            });
        });
    });
})(jQuery);
