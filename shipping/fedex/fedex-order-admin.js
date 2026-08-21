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
    });
})(jQuery);
