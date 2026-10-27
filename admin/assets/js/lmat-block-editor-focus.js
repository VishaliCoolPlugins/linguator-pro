/**
 * Untranslated Inspector: select a string in the block editor.
 *
 * The inspector's "Edit translation" link adds ?lmat_text={start of text}.
 * Once the blocks are loaded, the title or the first block whose text contains
 * it is selected and its text highlighted, so the cursor lands on it.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.data || typeof wp.domReady !== 'function') {
		return;
	}

	var MAX_ATTEMPTS = 40;
	var RETRY_MS = 250;

	function normalize(text) {
		return String(text || '').replace(/\s+/g, ' ').trim();
	}

	var needle = normalize(new URLSearchParams(window.location.search).get('lmat_text'));
	if (!needle) {
		return;
	}

	// DOMParser does not run scripts or load images, unlike innerHTML.
	function rawText(value) {
		var html = '';
		if (typeof value === 'string') {
			html = value;
		} else if (value && typeof value.toHTMLString === 'function') {
			html = value.toHTMLString();
		}
		if (!html) {
			return '';
		}
		return new DOMParser().parseFromString(html, 'text/html').body.textContent || '';
	}

	function contains(text) {
		return normalize(text).indexOf(needle) !== -1;
	}

	// Text inside arrays or objects, e.g. core/table head/body/foot -> cells -> content.
	function nestedContains(value, depth) {
		if (!value || typeof value !== 'object' || depth > 5) {
			return false;
		}
		if (typeof value.toHTMLString === 'function') {
			return contains(rawText(value));
		}
		for (var key in value) {
			if (Object.prototype.hasOwnProperty.call(value, key)) {
				var child = value[key];
				if (typeof child === 'string' ? contains(rawText(child)) : nestedContains(child, depth + 1)) {
					return true;
				}
			}
		}
		return false;
	}

	function findBlock(blocks) {
		for (var i = 0; i < blocks.length; i++) {
			var block = blocks[i];
			var attributes = block.attributes || {};

			for (var key in attributes) {
				if (Object.prototype.hasOwnProperty.call(attributes, key)) {
					var value = attributes[key];
					var text = rawText(value);
					if (text && contains(text)) {
						return { clientId: block.clientId, key: key, length: text.length, nested: false };
					}
					if (!text && nestedContains(value, 0)) {
						return { clientId: block.clientId, nested: true };
					}
				}
			}

			var inner = findBlock(block.innerBlocks || []);
			if (inner) {
				return inner;
			}
		}
		return null;
	}

	function canvasDocuments() {
		var docs = [];
		var frame = document.querySelector('iframe[name="editor-canvas"]');
		if (frame && frame.contentDocument) {
			docs.push(frame.contentDocument);
		}
		docs.push(document);
		return docs;
	}

	function selectContents(doc, node) {
		node.focus();
		var range = doc.createRange();
		range.selectNodeContents(node);
		var selection = doc.getSelection();
		selection.removeAllRanges();
		selection.addRange(range);
		node.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}

	// Innermost editable field inside a block (e.g. one table cell) holding the text.
	function findEditable(blockNode) {
		var hits = Array.prototype.filter.call(blockNode.querySelectorAll('[contenteditable="true"]'), function (node) {
			return contains(node.textContent);
		});
		var innermost = hits.filter(function (node) {
			return !hits.some(function (other) {
				return other !== node && node.contains(other);
			});
		});
		return innermost[0] || null;
	}

	function focusTitle() {
		var editor = wp.data.select('core/editor');
		if (!editor || !contains(editor.getEditedPostAttribute('title'))) {
			return false;
		}

		var docs = canvasDocuments();
		for (var i = 0; i < docs.length; i++) {
			var node = docs[i].querySelector('.editor-post-title__input, .wp-block-post-title[contenteditable="true"]');
			if (node) {
				selectContents(docs[i], node);
				return true;
			}
		}
		return false;
	}

	function focusBlock() {
		var blockEditor = wp.data.select('core/block-editor');
		if (!blockEditor) {
			return false;
		}

		var found = findBlock(blockEditor.getBlocks());
		if (!found) {
			return false;
		}

		var actions = wp.data.dispatch('core/block-editor');
		actions.selectBlock(found.clientId);

		// Let the editor focus the block first, then highlight its text.
		window.setTimeout(function () {
			if (!found.nested) {
				actions.selectionChange(found.clientId, found.key, 0, found.length);
			}

			var docs = canvasDocuments();
			for (var i = 0; i < docs.length; i++) {
				var node = docs[i].querySelector('[data-block="' + found.clientId + '"]');
				if (!node) {
					continue;
				}

				var editable = found.nested ? findEditable(node) : null;
				if (editable) {
					selectContents(docs[i], editable);
				} else {
					node.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
				break;
			}
		}, 150);

		return true;
	}

	function tryFocus(attempt) {
		if (focusTitle() || focusBlock()) {
			return;
		}
		if (attempt < MAX_ATTEMPTS) {
			window.setTimeout(function () {
				tryFocus(attempt + 1);
			}, RETRY_MS);
		}
	}

	wp.domReady(function () {
		window.setTimeout(function () {
			tryFocus(0);
		}, 500);
	});
})(window.wp);
