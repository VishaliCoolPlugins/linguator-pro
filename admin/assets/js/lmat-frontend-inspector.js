/**
 * Untranslated Inspector panel.
 *
 * Reads the rows printed by LMAT_Frontend_Inspector, lists them in a side
 * panel and outlines each string on the page. Elementor rows are found by
 * their element id; title and content rows by an exact text match.
 */
(function () {
	'use strict';

	var cfg = window.lmatFrontendInspector;
	if (!cfg || !cfg.result || !document.body) {
		return;
	}

	var i18n = cfg.i18n || {};
	var result = cfg.result;
	var rows = Array.isArray(result.rows) ? result.rows : [];
	rows.forEach(function (row, idx) {
		row._index = idx + 1;
	});
	var panel = null;
	var active = null;

	var TEXT_SELECTOR = 'h1,h2,h3,h4,h5,h6,p,li,blockquote,figcaption,td,th,dt,dd,pre,a,button,span,div';
	var WIDTH_KEY = 'lmatFiWidth';
	var MIN_WIDTH = 300;
	var THEME_KEY = 'lmatFiTheme';

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (typeof text === 'string') {
			node.textContent = text;
		}
		return node;
	}

	function format(template, values) {
		var i = 0;
		return String(template || '').replace(/%(\d+\$)?[sd]/g, function (token, position) {
			var index = position ? parseInt(position, 10) - 1 : i++;
			return values[index] !== undefined ? String(values[index]) : token;
		});
	}

	function normalize(text) {
		return String(text || '').replace(/\s+/g, ' ').trim();
	}

	function isInspectorUi(node) {
		return !!(node.closest && (node.closest('.lmat-fi') || node.closest('#wpadminbar')));
	}

	function isVisible(node) {
		if (typeof node.checkVisibility === 'function') {
			return node.checkVisibility({ checkOpacity: false, checkVisibilityCSS: false });
		}
		return (node.offsetWidth > 0 || node.offsetHeight > 0) || (node.getClientRects && node.getClientRects().length > 0);
	}

	function findElementorTarget(id) {
		if (!/^[a-z0-9]+$/i.test(id)) {
			return null;
		}
		return document.querySelector('.elementor-element-' + id) || document.querySelector('[data-id="' + id + '"]');
	}

	var textIndex = null;

	function getTextIndex() {
		if (!textIndex) {
			textIndex = new Map();
			var nodes = document.body.querySelectorAll(TEXT_SELECTOR);
			for (var i = 0; i < nodes.length; i++) {
				var node = nodes[i];
				if (!isInspectorUi(node)) {
					var text = normalize(node.textContent);
					if (text) {
						var list = textIndex.get(text);
						if (!list) {
							list = [];
							textIndex.set(text, list);
						}
						list.push(node);
					}
				}
			}
		}
		return textIndex;
	}

	// The innermost visible element whose whole text equals the string, optionally inside root.
	function findTextTarget(match, root) {
		var wanted = normalize(match);
		if (!wanted) {
			return null;
		}

		var hits = [];
		var candidates = getTextIndex().get(wanted);
		if (candidates && candidates.length) {
			hits = root
				? candidates.filter(function (node) { return root.contains(node); })
				: candidates;
		}

		if (!hits.length) {
			var searchRoot = root || document.body;
			var nodes = searchRoot.querySelectorAll(TEXT_SELECTOR);
			var exactHits = [];
			var partialHits = [];
			for (var p = 0; p < nodes.length; p++) {
				var n = nodes[p];
				if (!isInspectorUi(n)) {
					var nodeText = normalize(n.textContent);
					if (nodeText) {
						if (nodeText === wanted) {
							exactHits.push(n);
						} else if (wanted.length >= 3 && nodeText.indexOf(wanted) !== -1) {
							partialHits.push(n);
						}
					}
				}
			}
			hits = exactHits.length ? exactHits : partialHits;
		}

		if (!hits.length) {
			return null;
		}

		var innermost = hits.length <= 1 ? hits : hits.filter(function (node) {
			return !hits.some(function (other) {
				return other !== node && node.contains(other);
			});
		});

		// Prefer unclaimed nodes so duplicate strings in the same widget get separate badges
		var unclaimed = innermost.filter(function (node) {
			return !node.hasAttribute('data-lmat-index');
		});
		var pool = unclaimed.length ? unclaimed : innermost;

		for (var i = 0; i < pool.length; i++) {
			if (isVisible(pool[i])) {
				return pool[i];
			}
		}

		return pool[0] || null;
	}

	function findTarget(row) {
		if (row._target !== undefined) {
			return row._target;
		}
		var target = null;
		if (row.kind === 'elementor' && row.element_id) {
			var widget = findElementorTarget(String(row.element_id));
			// Always search for the specific text element inside the widget first!
			// If not found, fall back to the widget container.
			if (widget && row.match) {
				target = findTextTarget(row.match, widget) || widget;
			} else {
				target = widget || (row.match ? findTextTarget(row.match) : null);
			}
		} else {
			target = findTextTarget(row.match);
		}
		row._target = target;
		return target;
	}

	var activePencil = null;

	function showPencil(target, editUrl) {
		if (activePencil && activePencil.parentNode) {
			activePencil.parentNode.removeChild(activePencil);
			activePencil = null;
		}
		if (!target || !editUrl) {
			return;
		}
		var btn = el('button', 'lmat-fi-pencil-btn');
		btn.type = 'button';
		btn.setAttribute('aria-label', i18n.edit || 'Edit translation');
		btn.title = i18n.edit || 'Edit translation';
		btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>';
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			window.open(editUrl, '_blank', 'noopener,noreferrer');
		});
		btn.addEventListener('mousedown', function (e) {
			e.stopPropagation();
		});
		target.appendChild(btn);
		activePencil = btn;
	}

	function focusRow(item, target, row, fromPage) {
		if (active) {
			active.item.classList.remove('is-active');
			active.target.classList.remove('lmat-fi-highlight');
		}

		for (var open = item.closest('details'); open; open = open.parentElement && open.parentElement.closest('details')) {
			open.open = true;
		}
		var section = item.closest('.lmat-fi__group');
		if (section && section.classList.contains('is-collapsed')) {
			section.querySelector('.lmat-fi__group-title').click();
		}
		item.classList.add('is-active');
		target.classList.add('lmat-fi-highlight');

		if (!fromPage) {
			target.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		window.setTimeout(function () {
			if (item && typeof item.scrollIntoView === 'function') {
				item.scrollIntoView({ behavior: 'smooth', block: fromPage ? 'center' : 'nearest' });
			}
		}, 60);

		showPencil(target, row ? row.edit_url : '');
		active = { item: item, target: target, row: row };
	}

	var pendingMarks = [];
	var marksScheduled = false;

	function scheduleMark(target, index) {
		pendingMarks.push({ target: target, index: index });
		if (!marksScheduled) {
			marksScheduled = true;
			window.requestAnimationFrame(function () {
				for (var i = 0; i < pendingMarks.length; i++) {
					var item = pendingMarks[i];
					item.target.classList.add('lmat-fi-mark');
					if (item.index) {
						var existing = item.target.getAttribute('data-lmat-index');
						item.target.setAttribute('data-lmat-index', existing ? existing + ',' + item.index : String(item.index));
					}
				}
				pendingMarks = [];
				marksScheduled = false;
			});
		}
	}

	// Why a found string is hidden, and how to bring it into view (null when it is visible).
	function hiddenContext(target) {
		var popup = target.closest('.elementor-popup-modal, .elementor-location-popup');
		var slide = target.closest('.swiper-slide');
		if (isVisible(target) && !popup && !(slide && !slide.classList.contains('swiper-slide-active'))) {
			return null;
		}
		if (popup) {
			var doc = popup.matches('[data-elementor-id]') ? popup : popup.querySelector('[data-elementor-id]');
			var popupId = doc && doc.getAttribute('data-elementor-id');
			return { note: i18n.hiddenPopup, reveal: popupId && window.elementorProFrontend ? function () {
				window.elementorProFrontend.modules.popup.showPopup({ id: popupId });
			} : null };
		}
		if (slide) {
			var swiper = slide.closest('.swiper, .swiper-container');
			return { note: i18n.hiddenSlider, reveal: swiper && swiper.swiper ? function () {
				var loopIndex = slide.getAttribute('data-swiper-slide-index');
				if (null !== loopIndex && swiper.swiper.slideToLoop) {
					swiper.swiper.slideToLoop(parseInt(loopIndex, 10));
				} else {
					swiper.swiper.slideTo(Array.prototype.indexOf.call(slide.parentNode.children, slide));
				}
			} : null };
		}
		var tabPanel = target.closest('details, [role="tabpanel"], .elementor-tab-content');
		if (tabPanel) {
			return { note: i18n.hiddenTab, reveal: function () {
				if ('DETAILS' === tabPanel.tagName) {
					tabPanel.open = true;
					return;
				}
				var tab = tabPanel.id ? document.querySelector('[aria-controls="' + tabPanel.id + '"]') : null;
				if (!tab && tabPanel.getAttribute('data-tab')) {
					tab = document.querySelector('.elementor-tab-title[data-tab="' + tabPanel.getAttribute('data-tab') + '"]');
				}
				if (tab) {
					tab.click();
				}
			} };
		}
		if (target.closest('[class*="elementor-hidden-"]')) {
			return { note: i18n.hiddenDevice, reveal: null };
		}
		return { note: i18n.hiddenOther, reveal: null };
	}

	function buildRow(row) {
		var item = el('li', 'lmat-fi__row');
		// A div, not a button, so the text can be selected and copied.
		var main = el('div', 'lmat-fi__row-main');

		var header = el('div', 'lmat-fi__row-header');
		if (row._index) {
			header.appendChild(el('span', 'lmat-fi__row-badge', String(row._index)));
		}
		var meta = row.widget ? row.widget + ' - ' + row.field : row.field;
		header.appendChild(el('span', 'lmat-fi__row-meta', meta || ''));
		main.appendChild(header);
		main.appendChild(el('span', 'lmat-fi__row-text', row.match || ''));
		item.appendChild(main);

		var target = findTarget(row);
		if (target) {
			scheduleMark(target, row._index);
			var hidden = hiddenContext(target);
			// Open the tab, slide or popup first, then point at the string.
			var show = function () {
				if (hidden && hidden.reveal) {
					hidden.reveal();
					window.setTimeout(function () {
						focusRow(item, target, row, false);
					}, 400);
				} else {
					focusRow(item, target, row, false);
				}
			};
			if (hidden) {
				item.classList.add('is-hidden');
				var note = el('span', 'lmat-fi__row-note', hidden.note || '');
				if (hidden.reveal) {
					var reveal = el('button', 'lmat-fi__row-reveal', i18n.reveal || 'Show it on the page');
					reveal.type = 'button';
					reveal.addEventListener('click', show);
					note.appendChild(reveal);
				}
				item.appendChild(note);
			}
			main.setAttribute('role', 'button');
			main.tabIndex = 0;
			main.addEventListener('click', show);
			main.addEventListener('keydown', function (event) {
				if ('Enter' === event.key || ' ' === event.key) {
					event.preventDefault();
					show();
				}
			});
			target.addEventListener('click', function (event) {
				if (event.target && event.target.closest && event.target.closest('.lmat-fi-pencil-btn')) {
					return;
				}
				if (event.target !== target && event.target.closest && event.target.closest('.lmat-fi-mark') && event.target.closest('.lmat-fi-mark') !== target) {
					return;
				}
				var link = event.target && event.target.closest ? event.target.closest('a') : null;
				if (link && !event.ctrlKey && !event.metaKey) {
					event.preventDefault();
				}
				focusRow(item, target, row, true);
			});
		} else {
			item.classList.add('is-missing');
			item.appendChild(el('span', 'lmat-fi__row-note', row.note || i18n.notFound || ''));
		}

		var actions = el('div', 'lmat-fi__row-actions');
		if (row.match) {
			actions.appendChild(buildCopy(row.match));
		}
		if (row.post_id && row.field_key && cfg.nonce) {
			actions.appendChild(buildQuickEdit(row, item, target));
		}
		if (row.edit_url) {
			var edit = el('a', 'lmat-fi__row-edit', i18n.edit || 'Edit');
			edit.href = row.edit_url;
			edit.target = '_blank';
			edit.rel = 'noopener noreferrer';
			actions.appendChild(edit);
		}
		if (actions.childNodes.length) {
			item.appendChild(actions);
		}

		return item;
	}

	var progress = { card: null, total: 0, remaining: 0 };

	// Update page text, clear badges, pencil button, and outlines without reloading.
	function applyQuickEditToPage(target, row, value) {
		if (activePencil && activePencil.parentNode) {
			activePencil.parentNode.removeChild(activePencil);
			activePencil = null;
		}

		if (!target) {
			return;
		}

		var textEl = target;
		if (row && row.match) {
			var inner = findTextTarget(row.match, target);
			if (inner) {
				textEl = inner;
			} else if (target.children.length > 0) {
				var candidate = target.querySelector('p, h1, h2, h3, h4, h5, h6, span, a, li');
				if (candidate) {
					textEl = candidate;
				}
			}
		}

		if (/<[a-z][\s\S]*>/i.test(value)) {
			textEl.innerHTML = value;
		} else {
			textEl.textContent = value;
		}

		var nodes = [textEl];
		if (target !== textEl) {
			nodes.push(target);
		}

		nodes.forEach(function (node) {
			node.classList.remove('lmat-fi-mark', 'lmat-fi-highlight');
			if (node.hasAttribute('data-lmat-index')) {
				var idxStr = node.getAttribute('data-lmat-index') || '';
				var remaining = idxStr
					.split(',')
					.map(function (s) { return s.trim(); })
					.filter(function (idx) { return idx && String(idx) !== String(row._index); });
				if (remaining.length > 0) {
					node.setAttribute('data-lmat-index', remaining.join(','));
				} else {
					node.removeAttribute('data-lmat-index');
				}
			}
		});

		if (active && (active.target === target || active.target === textEl)) {
			active = null;
		}
	}

	// Inline form that saves the translation without leaving the page.
	function buildQuickEdit(row, item, target) {
		var button = el('button', 'lmat-fi__row-copy', i18n.quickEdit || 'Quick edit');
		button.type = 'button';
		button.addEventListener('click', function () {
			if (item.querySelector('.lmat-fi__quick')) {
				return;
			}
			var form = el('div', 'lmat-fi__quick');
			var input = el('textarea', 'lmat-fi__quick-input');
			input.value = row.match || '';
			input.rows = 3;
			var save = el('button', 'lmat-fi__quick-save', i18n.save || 'Save');
			save.type = 'button';
			var cancel = el('button', 'lmat-fi__quick-cancel', i18n.cancel || 'Cancel');
			cancel.type = 'button';
			var error = el('p', 'lmat-fi__row-note');
			error.hidden = true;
			form.appendChild(input);
			form.appendChild(save);
			form.appendChild(cancel);
			form.appendChild(error);
			item.appendChild(form);
			input.focus();

			cancel.addEventListener('click', function () {
				form.remove();
			});
			save.addEventListener('click', function () {
				var value = input.value.trim();
				if (!value) {
					return;
				}
				save.disabled = true;
				save.textContent = i18n.saving || 'Saving...';
				var body = new FormData();
				body.append('action', 'lmat_quick_edit');
				body.append('nonce', cfg.nonce);
				body.append('post_id', row.post_id);
				body.append('field_key', row.field_key);
				body.append('element_id', row.element_id || '');
				body.append('value', value);
				body.append('original', row.match || '');
				body.append('is_segment', row.segment ? '1' : '0');
				fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (response) { return response.json(); })
					.then(function (json) {
						if (!json || !json.success) {
							throw new Error();
						}
						// Update text on page, remove badge and pencil immediately
						applyQuickEditToPage(target, row, value);

						var tree = item.closest('.lmat-fi__sub, .lmat-fi__tree');
						var section = item.closest('.lmat-fi__group');

						item.remove();

						if (tree) {
							var remainingTreeItems = tree.querySelectorAll('li.lmat-fi__row');
							if (remainingTreeItems.length === 0) {
								tree.remove();
							} else {
								var summary = tree.querySelector('.lmat-fi__sub-label, .lmat-fi__tree-summary');
								if (summary) {
									summary.textContent = summary.textContent.replace(/\(\d+\)/, '(' + remainingTreeItems.length + ')');
								}
							}
						}

						if (section) {
							var remainingGroupItems = section.querySelectorAll('li.lmat-fi__row');
							if (remainingGroupItems.length === 0) {
								section.remove();
							} else {
								var title = section.querySelector('.lmat-fi__group-title');
								if (title) {
									title.textContent = title.textContent.replace(/\(\d+\)/, '(' + remainingGroupItems.length + ')');
								}
							}
						}

						if (progress.card) {
							progress.remaining = Math.max(0, progress.remaining - 1);
							var card = buildProgress(progress.total, progress.remaining);
							progress.card.replaceWith(card);
							progress.card = card;
						}
					})
					.catch(function () {
						save.disabled = false;
						save.textContent = i18n.save || 'Save';
						error.textContent = i18n.saveFailed || '';
						error.hidden = false;
					});
			});
		});
		return button;
	}

	function buildCopy(text) {
		var button = el('button', 'lmat-fi__row-copy', i18n.copy || 'Copy');
		button.type = 'button';
		button.addEventListener('click', function () {
			copyText(text).then(function () {
				button.textContent = i18n.copied || 'Copied';
				window.setTimeout(function () {
					button.textContent = i18n.copy || 'Copy';
				}, 1500);
			}).catch(function () {});
		});
		return button;
	}

	// Clipboard API needs a secure page; the textarea covers plain http local sites.
	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var area = document.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', '');
			area.style.position = 'fixed';
			area.style.left = '-9999px';
			document.body.appendChild(area);
			area.select();
			var copied = false;
			try {
				copied = document.execCommand('copy');
			} catch (error) {
				copied = false;
			}
			document.body.removeChild(area);
			if (copied) {
				resolve();
			} else {
				reject();
			}
		});
	}

	// One collapsible widget group.
	function buildTreeNode(label, count) {
		var box = el('details', 'lmat-fi__sub');
		box.open = true;
		var summary = el('summary', 'lmat-fi__sub-title');
		summary.appendChild(el('span', 'lmat-fi__sub-arrow'));
		summary.appendChild(el('span', 'lmat-fi__sub-label', label + ' (' + count + ')'));
		box.appendChild(summary);
		return box;
	}
	function buildGroups(list) {
		var groups = [
			{ kind: 'title', label: i18n.groupTitle },
			{ kind: 'elementor', label: i18n.groupElementor },
			{ kind: 'text', label: i18n.groupText }
		].map(function (group) {
			return {
				kind: group.kind,
				label: group.label,
				rows: list.filter(function (row) {
					return row.kind === group.kind && !row.group;
				})
			};
		});

		// Rows from a rendered template (header, footer...) get one group per template.
		var templateGroups = Object.create(null);
		list.forEach(function (row) {
			if (!row.group) {
				return;
			}
			if (!templateGroups[row.group]) {
				templateGroups[row.group] = { label: row.group, rows: [] };
				groups.push(templateGroups[row.group]);
			}
			templateGroups[row.group].rows.push(row);
		});

		var wrap = el('div', 'lmat-fi__groups');
		var built = [];

		groups.forEach(function (group) {
			if (!group.rows.length) {
				return;
			}

			var label = group.label || '';
			var section = el('section', 'lmat-fi__group');
			var title = el('h3', 'lmat-fi__group-title', label + ' (' + group.rows.length + ')');
			title.setAttribute('role', 'button');
			title.setAttribute('aria-expanded', 'true');
			title.tabIndex = 0;
			title.addEventListener('click', function () {
				var collapsed = section.classList.toggle('is-collapsed');
				title.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			});
			title.addEventListener('keydown', function (event) {
				if ('Enter' === event.key || ' ' === event.key) {
					event.preventDefault();
					title.click();
				}
			});
			section.appendChild(title);
			var ul = el('ul', 'lmat-fi__list');
			var items = [];
			var addRow = function (list, row) {
				var item = buildRow(row);
				list.appendChild(item);
				items.push({
					item: item,
					haystack: [row.match, row.widget, row.field, label, String(row._index || '')].join(' ').toLowerCase()
				});
			};
			if (group.kind === 'elementor') {
				// Group Elementor rows by widget.
				var widgets = new Map();
				group.rows.forEach(function (row) {
					if (!widgets.has(row.element_id)) {
						widgets.set(row.element_id, { label: row.widget || '', rows: [] });
					}
					widgets.get(row.element_id).rows.push(row);
				});
				widgets.forEach(function (w) {
					var inner = buildTreeNode(w.label, w.rows.length);
					var list = el('ul', 'lmat-fi__list');
					w.rows.forEach(function (row) { addRow(list, row); });
					inner.appendChild(list);
					ul.appendChild(inner);
				});			} else {
				group.rows.forEach(function (row) { addRow(ul, row); });
			}
			section.appendChild(ul);
			wrap.appendChild(section);
			built.push({ label: label, section: section, title: title, items: items });
		});

		return { wrap: wrap, groups: built };
	}

	// Hides rows and groups that do not contain the typed text.
	function buildSearch(groups) {
		var wrap = el('div', 'lmat-fi__search');
		var field = el('div', 'lmat-fi__search-field');
		var input = el('input', 'lmat-fi__search-input');
		input.type = 'search';
		input.placeholder = i18n.search || '';
		input.setAttribute('aria-label', i18n.search || 'Search');
		field.appendChild(input);
		wrap.appendChild(field);

		var empty = el('p', 'lmat-fi__notice', i18n.noMatches || '');
		empty.hidden = true;
		wrap.appendChild(empty);

		input.addEventListener('input', function () {
			var query = normalize(input.value).toLowerCase();
			var total = 0;

			groups.forEach(function (group) {
				var shown = 0;
				group.items.forEach(function (entry) {
					var match = !query || entry.haystack.indexOf(query) !== -1;
					entry.item.hidden = !match;
					if (match) {
						shown++;
					}
				});
				group.section.querySelectorAll('details').forEach(function (box) {
					box.hidden = !box.querySelector('.lmat-fi__row:not([hidden])');
					if (query) {
						box.open = true;
					}
				});
				group.section.hidden = !shown;
				if (query && shown && group.section.classList.contains('is-collapsed')) {
					group.title.click();
				}
				group.title.textContent = group.label + ' (' + shown + ')';
				total += shown;
			});

			empty.hidden = total > 0;
		});

		return wrap;
	}

	function buildProgress(total, remaining) {
		var done = total - remaining;
		var percent = Math.round((done / total) * 100);
		var card = el('div', 'lmat-fi__progress' + (remaining ? '' : ' is-complete'));

		var head = el('div', 'lmat-fi__progress-head');
		head.appendChild(el('span', 'lmat-fi__progress-title', i18n.progressTitle || ''));
		head.appendChild(el('span', 'lmat-fi__progress-remaining', format(i18n.remaining, [remaining])));
		card.appendChild(head);

		var bar = el('div', 'lmat-fi__progress-bar');
		bar.setAttribute('role', 'progressbar');
		bar.setAttribute('aria-label', i18n.progressTitle || '');
		bar.setAttribute('aria-valuemin', '0');
		bar.setAttribute('aria-valuemax', '100');
		bar.setAttribute('aria-valuenow', String(percent));
		var fill = el('div', 'lmat-fi__progress-fill');
		fill.style.width = percent + '%';
		bar.appendChild(fill);
		card.appendChild(bar);

		card.appendChild(el('p', 'lmat-fi__progress-detail', format(i18n.progressOf, [done, total, percent + '%'])));
		return card;
	}

	function statusMessage(status) {
		var map = {
			source_language: i18n.sourceLanguage,
			no_source: i18n.noSource,
			source_unpublished: i18n.sourceUnpublished,
			not_translatable: i18n.notTranslatable
		};
		return map[status] || '';
	}

	// Drag the left edge to resize; the width is kept for the next reload.
	function initResize() {
		var handle = el('div', 'lmat-fi__resize');
		handle.setAttribute('aria-hidden', 'true');
		panel.appendChild(handle);

		function clamp(width) {
			var max = Math.max(MIN_WIDTH, window.innerWidth * 0.6);
			return Math.round(Math.min(Math.max(width, MIN_WIDTH), max));
		}

		// Panel and body margin both read this variable, so the page reflows with the panel.
		function setWidth(width) {
			document.documentElement.style.setProperty('--lmat-fi-width', clamp(width) + 'px');
		}

		try {
			var saved = parseInt(window.localStorage.getItem(WIDTH_KEY), 10);
			if (saved) {
				setWidth(saved);
			}
		} catch (e) {
			// Storage blocked; keep the default width.
		}

		handle.addEventListener('pointerdown', function (event) {
			event.preventDefault();
			handle.setPointerCapture(event.pointerId);
			document.body.classList.add('lmat-fi-resizing');
		});

		handle.addEventListener('pointermove', function (event) {
			if (handle.hasPointerCapture(event.pointerId)) {
				setWidth(window.innerWidth - event.clientX);
			}
		});

		handle.addEventListener('lostpointercapture', function () {
			document.body.classList.remove('lmat-fi-resizing');
			try {
				window.localStorage.setItem(WIDTH_KEY, String(parseInt(document.documentElement.style.getPropertyValue('--lmat-fi-width'), 10) || ''));
			} catch (e) {
				// Storage blocked; the width just won't be remembered.
			}
		});
	}

	// Light/dark toggle; a saved choice wins, otherwise the OS setting is used.
	function initTheme(header, close) {
		var toggle = el('button', 'lmat-fi__theme');
		toggle.type = 'button';

		function apply(dark) {
			panel.classList.toggle('is-dark', dark);
			toggle.textContent = dark ? '\u2600' : '\u263E';
			var label = (dark ? i18n.themeLight : i18n.themeDark) || '';
			toggle.setAttribute('aria-label', label);
			toggle.title = label;
		}

		var saved = null;
		try {
			saved = window.localStorage.getItem(THEME_KEY);
		} catch (e) {
			// Storage blocked; fall back to the OS setting.
		}
		apply(saved ? 'dark' === saved : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches));

		toggle.addEventListener('click', function () {
			var dark = !panel.classList.contains('is-dark');
			apply(dark);
			try {
				window.localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light');
			} catch (e) {
				// Storage blocked; the choice just won't be remembered.
			}
		});

		header.insertBefore(toggle, close);
	}

	var FLOATING_KEY = 'lmatFiFloating';
	var FLOAT_POS_KEY = 'lmatFiFloatPos';
	var dockGhost = null;

	function initDragAndDock(header, close) {
		// Corner resize handle for floating mode
		var cornerResize = el('div', 'lmat-fi__corner-resize');
		cornerResize.setAttribute('aria-hidden', 'true');
		cornerResize.innerHTML = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><line x1="10" y1="3" x2="3" y2="10"></line><line x1="10" y1="7" x2="7" y2="10"></line></svg>';
		panel.appendChild(cornerResize);

		// Dock ghost drop indicator
		dockGhost = el('div', 'lmat-fi-dock-ghost');
		dockGhost.innerHTML = '<div class="lmat-fi-dock-ghost__label"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg><span>' + (i18n.attachToSidebar || 'Attach to Right') + '</span></div>';
		document.body.appendChild(dockGhost);

		// Dock/Undock toggle button in header
		var dockBtn = el('button', 'lmat-fi__dock');
		dockBtn.type = 'button';

		var DOCK_SVG = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"></rect><path d="M15 3v18"></path><path d="M8 12h4m0 0l-2-2m2 2l-2 2"></path></svg>';
		var UNDOCK_SVG = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="7" y="7" rx="2"></rect><path d="M17 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h2"></path></svg>';

		function updateDockButton(floating) {
			dockBtn.innerHTML = floating ? DOCK_SVG : UNDOCK_SVG;
			var label = floating ? (i18n.attachToSidebar || 'Attach to sidebar') : (i18n.detachWindow || 'Detach to floating window');
			dockBtn.setAttribute('aria-label', label);
			dockBtn.title = label;
		}

		header.insertBefore(dockBtn, close);

		function setDocked() {
			panel.classList.remove('is-floating', 'is-dragging');
			document.body.classList.remove('lmat-fi-floating', 'lmat-fi-dragging-window');
			if (dockGhost) {
				dockGhost.classList.remove('is-active');
			}
			panel.style.left = '';
			panel.style.top = '';
			panel.style.width = '';
			panel.style.height = '';
			updateDockButton(false);
			try {
				window.localStorage.setItem(FLOATING_KEY, '0');
			} catch (e) {}
		}

		function setFloating(pos) {
			panel.classList.add('is-floating');
			document.body.classList.add('lmat-fi-floating');
			updateDockButton(true);

			var w = (pos && pos.width) || Math.min(Math.max(panel.offsetWidth || 380, 380), 450);
			var h = (pos && pos.height) || Math.min(Math.max(window.innerHeight * 0.75, 500), 680);
			var left = (pos && pos.left !== undefined) ? pos.left : Math.max(20, window.innerWidth - w - 40);
			var top = (pos && pos.top !== undefined) ? pos.top : 60;

			var maxLeft = Math.max(10, window.innerWidth - w - 10);
			var maxTop = Math.max(32, window.innerHeight - 80);
			left = Math.min(Math.max(10, left), maxLeft);
			top = Math.min(Math.max(32, top), maxTop);

			panel.style.width = w + 'px';
			panel.style.height = h + 'px';
			panel.style.left = left + 'px';
			panel.style.top = top + 'px';

			try {
				window.localStorage.setItem(FLOATING_KEY, '1');
				window.localStorage.setItem(FLOAT_POS_KEY, JSON.stringify({ left: left, top: top, width: w, height: h }));
			} catch (e) {}
		}

		dockBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (panel.classList.contains('is-floating')) {
				setDocked();
			} else {
				var savedPos = null;
				try {
					var raw = window.localStorage.getItem(FLOAT_POS_KEY);
					if (raw) {
						savedPos = JSON.parse(raw);
					}
				} catch (err) {}
				setFloating(savedPos);
			}
		});

		try {
			var savedFloating = window.localStorage.getItem(FLOATING_KEY);
			if (savedFloating === '1') {
				var savedPos = null;
				try {
					var raw = window.localStorage.getItem(FLOAT_POS_KEY);
					if (raw) {
						savedPos = JSON.parse(raw);
					}
				} catch (err) {}
				setFloating(savedPos);
			} else {
				updateDockButton(false);
			}
		} catch (e) {
			updateDockButton(false);
		}

		// Drag & Detach Logic
		var isDragging = false;
		var dragStartPointerX = 0;
		var dragStartPointerY = 0;
		var dragOffsetX = 0;
		var dragOffsetY = 0;
		var initialFloating = false;
		var hasDetachedDuringDrag = false;
		var canDock = false;

		header.addEventListener('pointerdown', function (event) {
			if (event.target.closest('button, a, input, select, textarea, [role="button"]')) {
				return;
			}
			event.preventDefault();

			isDragging = true;
			hasDetachedDuringDrag = false;
			canDock = false;
			dragStartPointerX = event.clientX;
			dragStartPointerY = event.clientY;
			initialFloating = panel.classList.contains('is-floating');

			var rect = panel.getBoundingClientRect();
			dragOffsetX = event.clientX - rect.left;
			dragOffsetY = event.clientY - rect.top;

			try {
				header.setPointerCapture(event.pointerId);
			} catch (e) {}
		});

		window.addEventListener('pointermove', function (event) {
			if (!isDragging) {
				return;
			}

			var dx = event.clientX - dragStartPointerX;
			var dy = event.clientY - dragStartPointerY;
			var dist = Math.sqrt(dx * dx + dy * dy);

			if (!initialFloating && !hasDetachedDuringDrag) {
				if (dist < 6) {
					return;
				}
				hasDetachedDuringDrag = true;
				panel.classList.add('is-floating');
				document.body.classList.add('lmat-fi-floating');
				updateDockButton(true);

				var rect = panel.getBoundingClientRect();
				var w = Math.min(Math.max(rect.width || 380, 380), 450);
				var h = Math.min(Math.max(window.innerHeight * 0.75, 500), 680);

				panel.style.width = w + 'px';
				panel.style.height = h + 'px';

				if (dragOffsetX > w - 40) {
					dragOffsetX = w - 40;
				}
				if (dragOffsetX < 40) {
					dragOffsetX = 40;
				}
			}

			panel.classList.add('is-dragging');
			document.body.classList.add('lmat-fi-dragging-window');

			var newLeft = event.clientX - dragOffsetX;
			var newTop = event.clientY - dragOffsetY;

			panel.style.left = newLeft + 'px';
			panel.style.top = newTop + 'px';

			// Snap detection zone: top-right or right edge
			var inDockZone = (event.clientX >= window.innerWidth - 120) ||
				(event.clientX >= window.innerWidth - 220 && event.clientY <= 140) ||
				(newLeft + panel.offsetWidth >= window.innerWidth - 25 && event.clientX >= window.innerWidth - 200);

			if (inDockZone) {
				dockGhost.classList.add('is-active');
				canDock = true;
			} else {
				dockGhost.classList.remove('is-active');
				canDock = false;
			}
		});

		function finishDrag() {
			if (!isDragging) {
				return;
			}
			isDragging = false;
			panel.classList.remove('is-dragging');
			document.body.classList.remove('lmat-fi-dragging-window');

			if (canDock) {
				setDocked();
			} else if (panel.classList.contains('is-floating')) {
				dockGhost.classList.remove('is-active');
				var w = panel.offsetWidth;
				var h = panel.offsetHeight;
				var maxLeft = Math.max(10, window.innerWidth - w - 10);
				var maxTop = Math.max(32, window.innerHeight - 80);
				var currentLeft = Math.min(Math.max(10, parseInt(panel.style.left, 10) || 20), maxLeft);
				var currentTop = Math.min(Math.max(32, parseInt(panel.style.top, 10) || 40), maxTop);

				panel.style.left = currentLeft + 'px';
				panel.style.top = currentTop + 'px';

				try {
					window.localStorage.setItem(FLOATING_KEY, '1');
					window.localStorage.setItem(FLOAT_POS_KEY, JSON.stringify({ left: currentLeft, top: currentTop, width: w, height: h }));
				} catch (e) {}
			}
		}

		window.addEventListener('pointerup', finishDrag);
		header.addEventListener('lostpointercapture', finishDrag);

		// Corner resize for floating mode
		var isResizing = false;
		var resizeStartX = 0;
		var resizeStartY = 0;
		var resizeStartW = 0;
		var resizeStartH = 0;

		cornerResize.addEventListener('pointerdown', function (event) {
			event.preventDefault();
			event.stopPropagation();
			isResizing = true;
			resizeStartX = event.clientX;
			resizeStartY = event.clientY;
			resizeStartW = panel.offsetWidth;
			resizeStartH = panel.offsetHeight;
			try {
				cornerResize.setPointerCapture(event.pointerId);
			} catch (e) {}
			document.body.classList.add('lmat-fi-resizing');
		});

		window.addEventListener('pointermove', function (event) {
			if (!isResizing) {
				return;
			}
			var maxW = window.innerWidth - panel.offsetLeft - 10;
			var maxH = window.innerHeight - panel.offsetTop - 10;
			var newW = Math.min(Math.max(320, resizeStartW + (event.clientX - resizeStartX)), maxW);
			var newH = Math.min(Math.max(260, resizeStartH + (event.clientY - resizeStartY)), maxH);

			panel.style.width = newW + 'px';
			panel.style.height = newH + 'px';
		});

		function finishCornerResize() {
			if (!isResizing) {
				return;
			}
			isResizing = false;
			document.body.classList.remove('lmat-fi-resizing');
			try {
				var raw = window.localStorage.getItem(FLOAT_POS_KEY);
				var pos = raw ? JSON.parse(raw) : {};
				pos.width = panel.offsetWidth;
				pos.height = panel.offsetHeight;
				window.localStorage.setItem(FLOAT_POS_KEY, JSON.stringify(pos));
			} catch (e) {}
		}

		window.addEventListener('pointerup', finishCornerResize);
		cornerResize.addEventListener('lostpointercapture', finishCornerResize);
	}

	var HIGHLIGHTS_KEY = 'lmatFiHighlights';

	function buildHighlightsToggle() {
		var footer = el('div', 'lmat-fi__footer');
		var label = el('label', 'lmat-fi__toggle-row');

		var labelContent = el('span', 'lmat-fi__toggle-label');
		labelContent.innerHTML = '<svg class="lmat-fi__toggle-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg> ' + (i18n.showHighlights || 'Show highlights on page');
		label.appendChild(labelContent);

		var switchWrap = el('span', 'lmat-fi__switch');
		var input = el('input', 'lmat-fi__switch-input');
		input.type = 'checkbox';

		var saved = null;
		try {
			saved = window.localStorage.getItem(HIGHLIGHTS_KEY);
		} catch (e) {}

		var isEnabled = saved !== '0';
		input.checked = isEnabled;
		document.body.classList.toggle('lmat-fi-hide-marks', !isEnabled);

		input.addEventListener('change', function () {
			var on = !!input.checked;
			document.body.classList.toggle('lmat-fi-hide-marks', !on);
			try {
				window.localStorage.setItem(HIGHLIGHTS_KEY, on ? '1' : '0');
			} catch (e) {}
		});

		var slider = el('span', 'lmat-fi__switch-slider');
		switchWrap.appendChild(input);
		switchWrap.appendChild(slider);
		label.appendChild(switchWrap);

		footer.appendChild(label);
		return footer;
	}

	function buildPanel() {
		panel = el('aside', 'lmat-fi');
		panel.id = 'lmat-fi';
		panel.setAttribute('role', 'complementary');
		panel.setAttribute('aria-label', i18n.title || 'Untranslated Inspector');

		var header = el('div', 'lmat-fi__header');
		var heading = el('div', 'lmat-fi__heading');
		heading.appendChild(el('span', 'lmat-fi__brand', i18n.brand || ''));
		heading.appendChild(el('h2', 'lmat-fi__title', i18n.title || 'Untranslated Inspector'));
		header.appendChild(heading);
		var close = el('a', 'lmat-fi__close', '\u00d7');
		close.href = cfg.closeUrl || window.location.pathname;
		close.setAttribute('aria-label', i18n.close || 'Close');
		close.title = i18n.close || 'Close';
		close.addEventListener('click', function (e) {
			e.preventDefault();
			if (dockGhost && dockGhost.parentNode) {
				dockGhost.parentNode.removeChild(dockGhost);
			}
			panel.remove();
			window.history.replaceState(null, '', close.href);
			var barLink = document.querySelector('#wp-admin-bar-lmat-frontend-inspector > a');
			if (barLink) {
				var openUrl = new URL(close.href, window.location.href);
				openUrl.searchParams.set('lmat-untranslated', '1');
				barLink.href = openUrl.href;
			}
			document.body.classList.remove('lmat-fi-open', 'lmat-fi-floating', 'lmat-fi-hide-marks', 'lmat-fi-dragging-window');
			document.querySelectorAll('.lmat-fi-mark, .lmat-fi-highlight, .lmat-fi-pencil-btn').forEach(function (node) {
				node.classList.contains('lmat-fi-pencil-btn') ? node.remove() : node.classList.remove('lmat-fi-mark', 'lmat-fi-highlight');
			});
		});
		header.appendChild(close);
		initTheme(header, close);
		initDragAndDock(header, close);
		panel.appendChild(header);

		var body = el('div', 'lmat-fi__body');

		if (result.status !== 'ok') {
			body.appendChild(el('p', 'lmat-fi__notice', statusMessage(result.status)));
		} else {
			body.appendChild(el('p', 'lmat-fi__comparing', format(i18n.comparing, [
				String(result.current_lang || '').toUpperCase(),
				String(result.source_lang || '').toUpperCase()
			])));

			var total = Math.max(parseInt(result.total, 10) || 0, rows.length);
			if (total) {
				progress = { card: buildProgress(total, rows.length), total: total, remaining: rows.length };
				body.appendChild(progress.card);
			}

			if (!rows.length) {
				body.appendChild(el('p', 'lmat-fi__notice is-success', i18n.none || ''));
			} else {
				var built = buildGroups(rows);
				body.appendChild(buildSearch(built.groups));
				body.appendChild(built.wrap);
			}
		}

		panel.appendChild(body);
		if (result.status === 'ok' && rows.length) {
			panel.appendChild(buildHighlightsToggle());
		}
		document.body.appendChild(panel);
		initResize();
		document.body.classList.add('lmat-fi-open');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', buildPanel);
	} else {
		buildPanel();
	}
})();
