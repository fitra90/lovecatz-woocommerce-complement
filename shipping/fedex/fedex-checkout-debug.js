(function () {
    'use strict';

    if (!window.lwcFedexCheckoutDebug || new URLSearchParams(window.location.search).get('lwc_fedex_debug') !== '1') {
        return;
    }

    var config = window.lwcFedexCheckoutDebug;
    var seen = {};
    var latestEvents = [];
    var latestDirectResult = null;
    var panel = document.createElement('section');
    panel.id = 'lwc-fedex-debug-panel';
    panel.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:999999;width:min(560px,calc(100vw - 24px));max-height:42vh;overflow:auto;background:#101827;color:#dbeafe;border:1px solid #3b82f6;border-radius:8px;padding:12px;font:12px/1.45 monospace;box-shadow:0 8px 30px rgba(0,0,0,.35)';
    panel.innerHTML = '<div style="display:flex;justify-content:space-between;gap:12px"><strong>LoveCatz FedEx checkout debug</strong><button type="button" data-close style="background:none;border:0;color:#fff;cursor:pointer">×</button></div><div style="display:flex;gap:8px;margin:10px 0;flex-wrap:wrap"><button type="button" data-test style="padding:7px 10px;background:#2563eb;color:#fff;border:0;border-radius:4px;cursor:pointer">Run direct FedEx test</button><button type="button" data-copy style="padding:7px 10px;background:#475569;color:#fff;border:0;border-radius:4px;cursor:pointer">Copy debug log</button></div><div data-copy-status aria-live="polite"></div><div data-result></div><div data-events>Waiting for WooCommerce to calculate shipping…</div>';
    panel.querySelector('[data-close]').addEventListener('click', function () { panel.remove(); });
    panel.querySelector('[data-test]').addEventListener('click', runDirectTest);
    panel.querySelector('[data-copy]').addEventListener('click', copyDebugLog);
    document.body.appendChild(panel);

    function request(extra) {
        var body = new URLSearchParams(Object.assign({
            action: 'lwc_fedex_checkout_debug',
            nonce: config.nonce
        }, extra || {}));

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function (response) { return response.json(); });
    }

    function render(payload) {
        if (!payload || !payload.success || !payload.data) return;

        var events = payload.data.events || [];
        latestEvents = events;
        events.forEach(function (event) {
            if (seen[event.id]) return;
            seen[event.id] = true;
            console.groupCollapsed('[LoveCatz FedEx] ' + event.time + ' — ' + event.stage);
            console.table(event.context || {});
            console.groupEnd();
        });

        if (!events.length) return;
        panel.querySelector('[data-events]').innerHTML = events.slice(-16).map(function (event) {
            return '<div style="border-top:1px solid #334155;padding:6px 0"><b style="color:#93c5fd">' + escapeHtml(event.time + ' ' + event.stage) + '</b><br><span>' + escapeHtml(JSON.stringify(event.context || {})) + '</span></div>';
        }).join('');
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>'"]/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[character];
        });
    }

    function fieldValue(selectors) {
        for (var i = 0; i < selectors.length; i++) {
            var field = document.querySelector(selectors[i]);
            if (field && field.value) return field.value;
        }
        return '';
    }

    function runDirectTest() {
        var button = panel.querySelector('[data-test]');
        var resultBox = panel.querySelector('[data-result]');
        var address = {
            country: fieldValue(['#shipping-country', 'select[name="shipping_country"]', '#billing-country', 'select[name="billing_country"]']),
            state: fieldValue(['#shipping-state', 'select[name="shipping_state"]', 'input[name="shipping_state"]', '#billing-state']),
            city: fieldValue(['#shipping-city', 'input[name="shipping_city"]', '#billing-city', 'input[name="billing_city"]']),
            postcode: fieldValue(['#shipping-postcode', 'input[name="shipping_postcode"]', '#billing-postcode', 'input[name="billing_postcode"]'])
        };

        button.disabled = true;
        button.textContent = 'Testing FedEx…';
        resultBox.textContent = 'Sending ' + JSON.stringify(address);

        request(Object.assign({action: 'lwc_fedex_checkout_debug_quote'}, address)).then(function (payload) {
            var data = payload && payload.data ? payload.data : {};
            latestDirectResult = payload;
            resultBox.innerHTML = '<div style="padding:8px;background:' + (payload.success ? '#14532d' : '#7f1d1d') + '">' + escapeHtml(data.message || 'Unknown response') + '</div>';
            console.log('[LoveCatz FedEx] Direct test response', payload);
            if (data.events) render({success: true, data: {events: data.events}});
            if (data.quotes) console.table(data.quotes);
        }).catch(function (error) {
            resultBox.textContent = error.message;
        }).finally(function () {
            button.disabled = false;
            button.textContent = 'Run direct FedEx test';
        });
    }

    function formatDebugLog() {
        var lines = [
            'LoveCatz FedEx checkout debug',
            'URL: ' + window.location.href,
            'Captured: ' + new Date().toISOString(),
            ''
        ];

        latestEvents.forEach(function (event) {
            lines.push(event.time + ' ' + event.stage);
            lines.push(JSON.stringify(event.context || {}));
            lines.push('');
        });

        if (latestDirectResult) {
            lines.push('Direct test response');
            lines.push(JSON.stringify(latestDirectResult, null, 2));
        }

        if (!latestEvents.length && !latestDirectResult) {
            lines.push('No diagnostic events captured yet.');
        }

        return lines.join('\n');
    }

    function copyDebugLog() {
        var text = formatDebugLog();
        var status = panel.querySelector('[data-copy-status]');

        function copied() {
            status.textContent = 'Debug log copied to clipboard.';
            window.setTimeout(function () { status.textContent = ''; }, 2500);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(copied).catch(function () {
                legacyCopy(text, copied);
            });
            return;
        }

        legacyCopy(text, copied);
    }

    function legacyCopy(text, onSuccess) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.cssText = 'position:fixed;left:-9999px;top:0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            onSuccess();
        } catch (error) {
            panel.querySelector('[data-copy-status]').textContent = 'Unable to copy automatically. Open the browser console to copy the log.';
        }

        textarea.remove();
    }

    request({enable: 'yes', clear: 'yes'}).then(render).catch(function (error) {
        console.error('[LoveCatz FedEx] Unable to start checkout diagnostics', error);
    });

    window.setInterval(function () {
        request().then(render).catch(function () {});
    }, 1500);
}());
