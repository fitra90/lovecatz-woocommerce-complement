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

        $('#lwc_fedex_quote_btn').on('click', function () {
            var btn = $(this);
            btn.prop('disabled', true);
            setStatus(cfg.i18n.checking, 'checking');

            $.post(cfg.ajax_url, {
                action: 'lwc_fedex_get_rate_quote',
                nonce: cfg.nonce,
                country: cfg.address.country,
                state: cfg.address.state,
                postcode: cfg.address.postcode,
                city: cfg.address.city
            }).done(function (res) {
                btn.prop('disabled', false);
                if (res && res.success && res.quotes && res.quotes.length) {
                    var lines = [];
                    $.each(res.quotes, function (i, quote) {
                        lines.push(quote.label + ': ' + quote.rate);
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
            var itemIds = [];
            $('.lwc-fedex-item-checkbox:checked').each(function () {
                itemIds.push($(this).val());
            });

            if (!itemIds.length) {
                setStatus(cfg.i18n.no_items, 'error');
                return;
            }

            btn.prop('disabled', true);
            setStatus(cfg.i18n.creating, 'checking');

            $.post(cfg.ajax_url, {
                action: 'lwc_fedex_create_shipment',
                nonce: cfg.nonce,
                order_id: cfg.order_id,
                item_ids: itemIds
            }).done(function (res) {
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
