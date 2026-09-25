/**
 * Order Number admin metabox — save button handler.
 *
 * @package LoveCatzWC
 */

(function ($) {
  'use strict';

  $(function () {
    var $btn = $('#lwc_ona_save');
    if (!$btn.length) {
      return;
    }

    var $field   = $('#lwc_ona_custom_id');
    var $status  = $('#lwc_ona_status');
    var $idDisplay = $('.lwc-ona-custom-id');

    $btn.on('click', function () {
      var orderId = $btn.data('order-id');
      var customId = $field.val() || '';

      $btn.prop('disabled', true).text(lwcOrderNumberAdmin.i18n.saving);
      $status.removeClass('lwc-ona-error').text('');

      $.ajax({
        url: lwcOrderNumberAdmin.ajaxUrl,
        type: 'POST',
        data: {
          action  : lwcOrderNumberAdmin.action,
          nonce   : lwcOrderNumberAdmin.ajaxNonce,
          order_id : orderId,
          custom_id: customId
        },
        success: function (resp) {
          if (resp.success) {
            var savedId = resp.data.custom_id || '';
            // Update the displayed custom ID without a full page reload.
            if (savedId) {
              $idDisplay.text(savedId).removeClass('lwc-ona-no-id');
              $field.val(savedId);
            } else {
              $idDisplay.text('—').addClass('lwc-ona-no-id');
              $field.val('');
            }
            $status.addClass('lwc-ona-success').text(lwcOrderNumberAdmin.i18n.saved);
          } else {
            $status.addClass('lwc-ona-error').text(resp.data.error || lwcOrderNumberAdmin.i18n.error);
          }
        },
        error: function () {
          $status.addClass('lwc-ona-error').text(lwcOrderNumberAdmin.i18n.error);
        },
        complete: function () {
          $btn.prop('disabled', false).text(lwcOrderNumberAdmin.i18n.save);
        }
      });
    });
  });
})(jQuery);