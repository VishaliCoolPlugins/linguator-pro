(function($, LmatGlossary) {
    'use strict';

    LmatGlossary.escapeHtml = function(text) {
        if (typeof text !== 'string') return text;
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    };

    // Attribute-context escaping for values placed inside double-quoted HTML attributes.
    LmatGlossary.escapeAttr = function(text) {
        if (typeof text !== 'string') return text;
        const map = {
            '&': '&amp;',
            '"': '&quot;',
            "'": '&#039;',
            '<': '&lt;',
            '>': '&gt;'
        };
        return text.replace(/[&"'<>]/g, function(m) { return map[m]; });
    };

    // Whitelist glossary kinds used as CSS class suffixes (must match PHP get_allowed_kinds).
    LmatGlossary.allowedGlossaryKinds = ['general', 'name'];
    LmatGlossary.sanitizeGlossaryKind = function(kind) {
        if (typeof kind !== 'string') return 'general';
        const normalized = kind.toLowerCase().trim();
        return LmatGlossary.allowedGlossaryKinds.indexOf(normalized) !== -1 ? normalized : 'general';
    };

    LmatGlossary.sanitizeInput = function(input) {
        return input.replace(/[<>]/g, ''); // Remove < and > characters
    };

    LmatGlossary.initCache = function() {
        if (LmatGlossary._cacheReady) return;

        LmatGlossary.$glossaryTable = $('.lmat-glossary-table');
        LmatGlossary.$glossaryTableWrapper = $('.lmat-glossary-table-wrapper');
        LmatGlossary.$languageFilters = $('.lmat-language-filters');
        LmatGlossary.$alphabet = $('.lmat-alphabet');
        LmatGlossary.$addGlossaryForm = $('#lmat-add-glossary-form');
        LmatGlossary.$addBtn = $('.lmat-add-btn');
        LmatGlossary.$importBtn = $('.lmat-import-btn');
        LmatGlossary.$noResults = $('#lmat-no-results');
        LmatGlossary.$addGlossarySuccess = $('#add-glossary-success');
        LmatGlossary.$glossaryType = $('.lmat-glossary-type');
        LmatGlossary.$glossarySearch = $('.lmat-search');
        LmatGlossary.$glossaryRows = LmatGlossary.$glossaryTable.find('tbody tr');
        LmatGlossary.searchDebounceTimer = null;

        LmatGlossary._cacheReady = true;
    };

    LmatGlossary.refreshGlossaryRows = function() {
        LmatGlossary.$glossaryRows = LmatGlossary.$glossaryTable.find('tbody tr');
        return LmatGlossary.$glossaryRows;
    };

    LmatGlossary.getActiveLangFilterBtn = function() {
        return LmatGlossary.$languageFilters.find('.lmat-lang-filter-btn.active');
    };

    LmatGlossary.getActiveAlphabetBtn = function() {
        return LmatGlossary.$alphabet.find('.lmat-alphabet-btn.active:not([disabled])');
    };

    LmatGlossary.applyZebraStriping = function() {
        LmatGlossary.refreshGlossaryRows().removeClass('lmat-row-striped');
        LmatGlossary.$glossaryRows.filter(':visible:not(.lmat-glossary-edit-row)').each(function(i) {
            if (i % 2 === 1) {
                $(this).addClass('lmat-row-striped');
            }
        });
    };

    LmatGlossary.updateGlossaryTableVisibility = function() {
        var $visibleRows = LmatGlossary.refreshGlossaryRows().filter(':visible');
        if ($visibleRows.length === 0) {
            LmatGlossary.$glossaryTableWrapper.hide();
            if (!LmatGlossary.$noResults.length) {
                LmatGlossary.$glossaryTableWrapper.after('<div id="lmat-no-results" style="text-align:center; margin: 32px 0; color: #888; font-size: 1.2em;">No glossary entries found.</div>');
                LmatGlossary.$noResults = $('#lmat-no-results');
            }
        } else {
            LmatGlossary.$glossaryTableWrapper.show();
            LmatGlossary.$noResults.remove();
            LmatGlossary.$noResults = $();
        }
        LmatGlossary.applyZebraStriping();
    };

    LmatGlossary.updateAlphabetButtonStates = function() {
        LmatGlossary.$alphabet.find('.lmat-alphabet-btn').prop('disabled', false);
        var visibleLetters = {};
        LmatGlossary.refreshGlossaryRows().filter(':visible').each(function() {
            var letter = $(this).data('letter');
            if (letter) visibleLetters[letter] = true;
        });
        LmatGlossary.$alphabet.find('.lmat-alphabet-btn').each(function() {
            var $btn = $(this);
            var letter = $btn.data('letter');
            if (!visibleLetters[letter]) {
                $btn.prop('disabled', true);
                $btn.removeClass('active');
            }
        });

        // After updating the table and language filter buttons
        var $alphabetBtns = LmatGlossary.$alphabet.find('.lmat-alphabet-btn');
        var $visibleRows = LmatGlossary.$glossaryRows.filter(':visible');

        // If there are no visible rows, remove active state from all alphabet buttons
        if ($visibleRows.length === 0) {
            $alphabetBtns.removeClass('active');
        }
    };

    LmatGlossary.filterGlossaryRows = function() {
        var selectedLang = LmatGlossary.getActiveLangFilterBtn().data('lang') || '';
        var selectedType = LmatGlossary.$glossaryType.val() || '';
        var selectedLetter = LmatGlossary.getActiveAlphabetBtn().data('letter') || '';
        var search = (LmatGlossary.$glossarySearch.val() || '').toLowerCase();

        LmatGlossary.refreshGlossaryRows().each(function() {
            var $row = $(this);
            var rowOriginalLang = $row.data('original-language');
            var rowType = $row.data('type');
            var rowLetter = $row.data('letter');
            var term = $row.find('.lmat-entry-title').text().toLowerCase();
            var desc = $row.find('.lmat-entry-desc').text().toLowerCase();

            // Start with strict language filter
            var show = (!selectedLang || rowOriginalLang === selectedLang);

            // Apply other filters only if row passes language filter
            if (show && selectedType) {
                show = (rowType === selectedType);
            }

            if (show && selectedLetter) {
                show = (rowLetter === selectedLetter);
            }

            if (show && search) {
                show = (term.indexOf(search) !== -1 || desc.indexOf(search) !== -1);
            }

            // Handle edit rows visibility
            if ($row.hasClass('lmat-glossary-edit-row')) {
                show = $row.prev('tr').is(':visible');
            }

            $row.toggle(show);
        });

        LmatGlossary.updateGlossaryTableVisibility();
        LmatGlossary.applyZebraStriping();
    };

    LmatGlossary.updateLanguageFilterButtons = function(originalLang) {
        if (!originalLang) return;

        const $filters = $('.lmat-language-filters');
        // Check if a button for this language already exists in the filter
        if ($filters.find('.lmat-lang-filter-btn[data-lang="' + originalLang + '"]').length === 0) {
            // Find the language object for label and flag
            let langObj = (lmat_glossary.lmat_languages || []).find(l => l.code === originalLang);
            let label = langObj ? (langObj.alt + ' Terms') : (originalLang + ' Terms');
            let flag = langObj && langObj.img ? `<img src="${langObj.img}" alt="${langObj.alt}" /> ` : '';

            $filters.append(`
                <button class="lmat-lang-filter-btn" data-lang="${originalLang}">
                    ${flag}${label}
                </button>
            `);
        }

        // After append, check total number of filter buttons
        const totalBtns = $filters.find('.lmat-lang-filter-btn').length;
        if (totalBtns <= 1) {
            // If only one, remove all (hide filter bar)
            $filters.empty();
        }
    };

    // Cache selectors as soon as DOM is ready (before other module inits).
    $(function() {
        LmatGlossary.initCache();
    });

})(jQuery, window.LmatGlossary = window.LmatGlossary || {});
