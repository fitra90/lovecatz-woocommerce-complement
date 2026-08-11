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
	});
})(jQuery);
