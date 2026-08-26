(function ($) {
    'use strict';
    $(function () {
        var box = $('.lwc-rayspeed-order');
        if (!box.length || !window.lwcRaySpeedOrder) return;
        var orderId = box.data('order-id');
        function request(action, button) {
            button.prop('disabled', true);
            $('#lwc-rayspeed-status').text('Working...');
            return $.post(lwcRaySpeedOrder.ajax_url, {action: action, nonce: lwcRaySpeedOrder.nonce, order_id: orderId})
                .done(function (response) {
                    var data = response && response.data ? response.data : {};
                    $('#lwc-rayspeed-status').text(data.message || (response.success ? 'Done.' : 'Request failed.'));
                    if (response.success && data.awb) {
                        $('#lwc-rayspeed-awb').text(data.awb);
                        $('#lwc-rayspeed-track').prop('disabled', false);
                    }
                    if (response.success && data.html) $('#lwc-rayspeed-tracking').html(data.html);
                    if (!response.success) button.prop('disabled', false);
                }).fail(function () {
                    $('#lwc-rayspeed-status').text('Request failed.');
                    button.prop('disabled', false);
                });
        }
        $('#lwc-rayspeed-create-awb').on('click', function () { request('lwc_rayspeed_create_awb', $(this)); });
        $('#lwc-rayspeed-track').on('click', function () { request('lwc_rayspeed_track', $(this)).always(function () { $('#lwc-rayspeed-track').prop('disabled', false); }); });
    });
})(jQuery);
