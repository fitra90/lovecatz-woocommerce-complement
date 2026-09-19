(function () {
	'use strict';

	function localizeTrackingTimes(root) {
		var scope = root && root.querySelectorAll ? root : document;
		var times = scope.querySelectorAll('time.lwc-fedex-local-time[datetime]:not([data-localized])');

		times.forEach(function (time) {
			var date = new Date(time.getAttribute('datetime'));
			if (Number.isNaN(date.getTime())) {
				return;
			}

			try {
				time.textContent = new Intl.DateTimeFormat(undefined, {
					year: 'numeric',
					month: 'long',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
					hourCycle: 'h23',
					hour12: false,
					timeZoneName: 'short'
				}).format(date);
				time.setAttribute('data-localized', '1');
			} catch (error) {
				// Keep the server-formatted fallback when Intl is unavailable.
			}
		});
	}

	function initialize() {
		localizeTrackingTimes(document);
		if (!window.MutationObserver || !document.body) {
			return;
		}

		new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(function (node) {
					if (node.nodeType !== 1) {
						return;
					}
					if (node.matches && node.matches('time.lwc-fedex-local-time[datetime]')) {
						localizeTrackingTimes(node.parentNode || document);
					} else {
						localizeTrackingTimes(node);
					}
				});
			});
		}).observe(document.body, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize);
	} else {
		initialize();
	}
}());
