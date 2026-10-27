(function($, LmatGlossary) {
    'use strict';

    LmatGlossary.Filters = {
        init: function() {
            LmatGlossary.initCache();

            // Language Filter Buttons
            $(document).off('click', '.lmat-lang-filter-btn').on('click', '.lmat-lang-filter-btn', function() {
                LmatGlossary.$glossaryTableWrapper.show();
                LmatGlossary.$noResults.remove();
                LmatGlossary.$noResults = $();
                // Reset alphabet filter
                LmatGlossary.$alphabet.find('.lmat-alphabet-btn').removeClass('active');

                var $btn = $(this);
                var selectedLang = $btn.data('lang');
                var $table = LmatGlossary.$glossaryTable;
                var defaultLang = $table.data('default-lang');
                var previousSelectedLang = LmatGlossary.getActiveLangFilterBtn().data('lang');

                // Update active state
                LmatGlossary.$languageFilters.find('.lmat-lang-filter-btn').removeClass('active');
                $btn.addClass('active');

                // First show all columns
                $table.find('th[data-lang], td[data-lang]').show();

                // Show the previously hidden default language column
                if (defaultLang) {
                    $table.find(`th[data-lang="${defaultLang}"], td[data-lang="${defaultLang}"]`).show();
                }

                // Hide the newly selected language column when it's the source
                if (selectedLang) {
                    $table.find(`td[data-lang="${selectedLang}"][data-is-source="true"]`).hide();
                    $table.find(`th[data-lang="${selectedLang}"]`).hide();
                }

                // Apply filters to rows
                LmatGlossary.refreshGlossaryRows().each(function() {
                    var $row = $(this);
                    var rowOriginalLang = $row.data('original-language');
                    var rowType = $row.data('type');
                    var show = true;

                    // Show row if it matches the selected language
                    if (selectedLang) {
                        show = (rowOriginalLang === selectedLang);

                        if (show) {
                            // For visible rows, ensure correct column visibility
                            $row.find('td[data-lang]').each(function() {
                                var $cell = $(this);
                                var cellLang = $cell.data('lang');
                                var isSource = $cell.data('is-source') === true;

                                // Hide if this is the source language column
                                if (cellLang === selectedLang && isSource) {
                                    $cell.hide();
                                } else {
                                    $cell.show();
                                }
                            });
                        }
                    }

                    // Apply type filter if active
                    var currentType = LmatGlossary.$glossaryType.val();
                    if (show && currentType) {
                        show = (rowType === currentType);
                    }

                    // Handle edit rows
                    if ($row.hasClass('lmat-glossary-edit-row')) {
                        show = $row.prev('tr').is(':visible');
                    }

                    $row.toggle(show);
                });

                LmatGlossary.updateGlossaryTableVisibility();
                LmatGlossary.updateAlphabetButtonStates();
                LmatGlossary.applyZebraStriping();
            });

            // On page load, if filter buttons exist, trigger click on the first one
            if (LmatGlossary.$languageFilters.length && LmatGlossary.$languageFilters.find('.lmat-lang-filter-btn').length > 0) {
                LmatGlossary.$languageFilters.find('.lmat-lang-filter-btn').first().trigger('click');
            }

            // Alphabet filter functionality
            $(document).off('click', '.lmat-alphabet-btn:not([disabled])').on('click', '.lmat-alphabet-btn:not([disabled])', function() {
                var $btn = $(this);
                if ($btn.hasClass('active')) {
                    $btn.removeClass('active');
                } else {
                    LmatGlossary.$alphabet.find('.lmat-alphabet-btn').removeClass('active');
                    $btn.addClass('active');
                }
                LmatGlossary.filterGlossaryRows();
            });

            // --- GLOSSARY SEARCH FUNCTIONALITY ---
            $(document).on('input', '.lmat-search', function() {
                clearTimeout(LmatGlossary.searchDebounceTimer);
                LmatGlossary.searchDebounceTimer = setTimeout(function() {
                    LmatGlossary.$glossaryTableWrapper.show();
                    LmatGlossary.$noResults.remove();
                    LmatGlossary.$noResults = $();
                    // filterGlossaryRows is the single source of truth (lang/type/letter/search)
                    LmatGlossary.filterGlossaryRows();
                }, 150);
            });

            // --- GLOSSARY TYPE FILTER FUNCTIONALITY ---
            $(document).on('change', '.lmat-glossary-type', function() {
                var selectedType = $(this).val();
                if (selectedType) {
                    LmatGlossary.$glossaryTableWrapper.show();
                    LmatGlossary.$noResults.remove();
                    LmatGlossary.$noResults = $();
                }
                LmatGlossary.filterGlossaryRows();
            });
        }
    };

})(jQuery, window.LmatGlossary = window.LmatGlossary || {});
