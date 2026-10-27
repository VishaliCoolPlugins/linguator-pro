/**
 * Untranslated Inspector: select a widget in the Elementor editor.
 *
 * The inspector's "Edit translation" link adds ?lmat_element={id}. Once the
 * document is attached to the preview, the widget is selected (Elementor then
 * opens its settings panel) and scrolled into view.
 */
(function () {
	'use strict';

	var id = new URLSearchParams(window.location.search).get('lmat_element');
	if (!id || !/^[a-z0-9]+$/i.test(id) || !window.elementor || typeof window.elementor.on !== 'function') {
		return;
	}

	var MAX_ATTEMPTS = 20;
	var RETRY_MS = 250;
	var done = false;

	function findContainer() {
		try {
			return window.elementor.getContainer(id) || null;
		} catch (e) {
			return null;
		}
	}

	function tryFocus(attempt) {
		if (done) {
			return;
		}

		var container = window.$e ? findContainer() : null;
		if (!container || !container.view) {
			if (attempt < MAX_ATTEMPTS) {
				window.setTimeout(function () {
					tryFocus(attempt + 1);
				}, RETRY_MS);
			}
			return;
		}

		done = true;
		window.$e.run('document/elements/select', { container: container });

		var node = container.view.el;
		if (node && typeof node.scrollIntoView === 'function') {
			node.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
	}

	// document:loaded fires after the widgets exist; Elementor then opens its
	// default panel, so wait a moment before selecting.
	window.elementor.on('document:loaded', function () {
		window.setTimeout(function () {
			tryFocus(0);
		}, 500);
	});
})();
