/**
 * BuildingCare Lite — PWA installer & service worker registration.
 */
(function () {
	'use strict';

	if (typeof bclPwa === 'undefined') {
		return;
	}

	// Register the service worker.
	if ('serviceWorker' in navigator) {
		window.addEventListener('load', function () {
			navigator.serviceWorker
				.register(bclPwa.swUrl, { scope: bclPwa.scope })
				.catch(function (err) {
					if (window.console) {
						window.console.warn('BuildingCare SW registration failed:', err);
					}
				});
		});
	}

	// Install prompt handling.
	var deferredPrompt = null;

	function installButton() {
		return document.getElementById('bcl-install-app');
	}

	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		deferredPrompt = e;
		var btn = installButton();
		if (btn) {
			btn.hidden = false;
		}
	});

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('#bcl-install-app') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();
		if (!deferredPrompt) {
			return;
		}
		deferredPrompt.prompt();
		var choice = deferredPrompt.userChoice;
		if (choice && choice.then) {
			choice.then(function () {
				deferredPrompt = null;
				btn.hidden = true;
			});
		}
	});

	window.addEventListener('appinstalled', function () {
		var btn = installButton();
		if (btn) {
			btn.hidden = true;
		}
		deferredPrompt = null;
	});
})();
