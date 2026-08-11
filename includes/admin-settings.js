(function ($) {
	'use strict';

	function initDashiconSelectors() {
		$('.lwc-dashicon-choice').on('click', function () {
			$('.lwc-dashicon-choice').removeClass('selected');
			$(this).addClass('selected');
			$('#lwc_menu_icon_class').val($(this).data('icon')).trigger('change');
		});
	}

	function updateFedexConnectionStatus() {
		var accountNumber = $('#lwc_fedex_account_number').val().trim();
		var apiKey = $('#lwc_fedex_api_key').val().trim();
		var apiSecret = $('#lwc_fedex_api_secret').val().trim();
		var statusEl = $('#lwc-fedex-connection-status');

		if (!statusEl.length) {
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

		statusEl.attr('data-status', status);
		statusEl.find('.lwc-fedex-status-label').text(label);
	}

	$(document).ready(function () {
		if ($('#lwc_menu_icon_class').length) {
			initDashiconSelectors();
		}

		if ($('.lwc-fedex-credential-field').length) {
			$('.lwc-fedex-credential-field').on('input change', updateFedexConnectionStatus);
			updateFedexConnectionStatus();
		}
	});
})(jQuery);
