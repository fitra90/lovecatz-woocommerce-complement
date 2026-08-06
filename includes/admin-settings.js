(function ($) {
	'use strict';

	function initDashiconSelectors() {
		$('.lwc-dashicon-choice').on('click', function () {
			$('.lwc-dashicon-choice').removeClass('selected');
			$(this).addClass('selected');
			$('#lwc_menu_icon_class').val($(this).data('icon')).trigger('change');
		});
	}

	$(document).ready(function () {
		if ($('#lwc_menu_icon_class').length) {
			initDashiconSelectors();
		}
	});
})(jQuery);
