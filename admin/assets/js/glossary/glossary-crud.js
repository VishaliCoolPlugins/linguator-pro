(function($, LmatGlossary) {
    'use strict';

    function toggleTranslationsSection($form) {
        var $translations = $form.find('.lmat-add-translations');
        var sourceLang = $form.find('.lmat-add-source-lang').val() || '';
        var term = $form.find('.lmat-add-term').val() || '';
        var type = $form.find('.lmat-add-type').val() || '';

        // Remove hidden class from all fields first
        $translations.find('.lmat-translation-field').removeClass('lmat-hidden');

        // Hide the translation field for the selected original language
        if (sourceLang) {
            $translations.find('.lmat-translation-field-' + sourceLang).addClass('lmat-hidden');
        }

        if (
            term.trim() !== '' &&
            type.trim() !== '' &&
            sourceLang.trim() !== ''
        ) {
            $translations.addClass('lmat-show').css('display', 'flex');
        } else {
            $translations.removeClass('lmat-show').css('display', 'none');
        }
    }

    LmatGlossary.Crud = {
        init: function() {
            LmatGlossary.initCache();

            const $glossaryTableWrapper = LmatGlossary.$glossaryTableWrapper;
            const $addGlossaryForm = LmatGlossary.$addGlossaryForm;
            const $addGlossarySuccess = LmatGlossary.$addGlossarySuccess;

            $(document).off('click', '.lmat-edit-btn, .lmat-edit-btn-svg').on('click', '.lmat-edit-btn, .lmat-edit-btn-svg', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Handle any existing edit rows first
                const $existingEditRow = $('.lmat-glossary-edit-row');
                if ($existingEditRow.length) {
                    // Show the original row that was being edited
                    $existingEditRow.prev('tr').show();
                    // Remove the edit row
                    $existingEditRow.remove();
                }

                // Find the row being edited
                const $row = $(this).closest('tr');
                const term = $row.data('term') || '';
                const desc = $row.find('.lmat-entry-desc').text().trim() || '';
                const type = $row.data('type') || 'general';
                const source_lang = $row.data('original-language') || '';

                // Validate required data
                if (!source_lang) {
                    console.warn('Missing source language for row');
                    return;
                }

                // Safely build translations object
                const translations = {};
                if (typeof lmat_glossary !== 'undefined' && Array.isArray(lmat_glossary.lmat_languages)) {
                    lmat_glossary.lmat_languages.forEach(function(lang) {
                    if (!lang || !lang.code || lang.code === source_lang) return;
                    const $cell = $row.find(`td.lmat-lang-col-${lang.code}`);
                    const $term = $cell.find('.lmat-translated-term');
                        let value = '';
                        if ($term.length) {
                            // Try to get full text from data attribute first
                            value = $term.data('full-text') || $term.attr('data-full-text') || $term.text().trim();
                        }
                        translations[lang.code] = typeof value === 'string' ? value : (value ? String(value) : '');
                    });
                }

                const safeTerm = typeof term === 'string' ? term : '';
                const safeDesc = typeof desc === 'string' ? desc : '';
                const safeType = LmatGlossary.sanitizeGlossaryKind(type);
                const safeSourceLang = typeof source_lang === 'string' ? source_lang : '';

                try {
                    const templateHtml = (typeof lmat_glossary !== 'undefined' && lmat_glossary.edit_row_template) ? lmat_glossary.edit_row_template : '';

                    if (!templateHtml) {
                        console.error('Template not found or empty');
                        alert('Edit template not found. Please refresh the page.');
                        return;
                    }

                    const templateFn = _.template(templateHtml);
                    const editRowHtml = templateFn({
                        term: safeTerm,
                        desc: safeDesc,
                        type: safeType,
                        translations: translations,
                        languages: lmat_glossary.lmat_languages.filter(lang => lang.code !== safeSourceLang),
                        source_lang: safeSourceLang
                    });

                    $row.after(editRowHtml);
                    $row.hide();
                    $row.next('.lmat-glossary-edit-row').show();
                    LmatGlossary.refreshGlossaryRows();

                } catch (error) {
                    console.error('Error generating edit row:', error, {
                        safeTerm,
                        safeDesc,
                        safeType,
                        translations,
                        safeSourceLang
                    });
                    alert('An error occurred while trying to edit this entry. Please try again.');
                }
            });


            // Add input event handler for real-time validation
            LmatGlossary.$glossaryTable.on('input', '.lmat-edit-translation, .lmat-edit-term, .lmat-edit-desc', function() {
                const $textarea = $(this);
                const $error = $textarea.next('.lmat-translation-error');
                const value = $textarea.val().trim();
                const maxLength = $textarea.hasClass('lmat-edit-term') || $textarea.hasClass('lmat-edit-desc') ? 240 : 220;

                if (value.length > maxLength) {
                    $textarea.addClass('error');
                    $error.text(`Must be less than ${maxLength} characters`).show();
                } else {
                    $textarea.removeClass('error');
                    $error.hide();
                }
            });

            // Update the save handler to include validation
            $glossaryTableWrapper.off('click', '.lmat-save-edit-btn').on('click', '.lmat-save-edit-btn', function(e) {
                e.preventDefault();
                const $editRow = $(this).closest('tr');
                const $origRow = $editRow.prev('tr');

                // Validate required fields
                const $termField = $editRow.find('.lmat-edit-term');
                const $descField = $editRow.find('.lmat-edit-desc');
                const term = $termField.val().trim();
                const desc = $descField.val().trim();
                const type = $editRow.find('.lmat-edit-type').val();
                const source_lang = $origRow.data('original-language');

                let hasError = false;

                // Add validation for term and description length
                if (term.length > 240) {
                    $termField.addClass('error');
                    $termField.next('.lmat-translation-error').text('Term must be less than 240 characters.').show();
                    hasError = true;
                }

                if (desc.length > 240) {
                    $descField.addClass('error');
                    $descField.next('.lmat-translation-error').text('Description must be less than 240 characters.').show();
                    hasError = true;
                }

                // Check translation lengths
                $editRow.find('.lmat-edit-translation').each(function() {
                    const $textarea = $(this);
                    const value = $textarea.val().trim();
                    if (value.length > 240) {
                        $textarea.addClass('error');
                        $textarea.next('.lmat-translation-error').text('Translation must be less than 240 characters.').show();
                        hasError = true;
                    }
                });

                if (hasError) {
                    return;
                }

                const translations = {};
                $editRow.find('.lmat-edit-translation').each(function() {
                    const lang = $(this).data('lang');
                    if (lang) {
                        const value = $(this).val().trim();
                        // Only include non-empty translations
                        if (value !== '') {
                            translations[lang] = value;
                        }
                    }
                });


                // Modified AJAX request
                $.ajax({
                    url: lmat_glossary.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'lmat_update_glossary',
                        _wpnonce: lmat_glossary.update_glossary_validate,
                        data: {
                            term: term,
                            description: desc,
                            type: type,
                            source_lang: source_lang,
                            translations: translations,
                            original_term: $origRow.data('term'),
                            original_type: $origRow.data('type'),
                            original_source_lang: source_lang
                        }
                    },
                    success: function(resp) {
                        if (resp.success && resp.data && resp.data.updated_entry) {
                            const updatedEntry = resp.data.updated_entry;

                            // Update the original row with data from server response
                            const sanitizedKind = LmatGlossary.sanitizeGlossaryKind(updatedEntry.kind);
                            $origRow.data('term', updatedEntry.original_term);
                            $origRow.data('type', sanitizedKind);

                            // Update term and description from server data
                            $origRow.find('.lmat-entry-title').text(updatedEntry.original_term);
                            $origRow.find('.lmat-entry-desc').text(updatedEntry.description || '');

                            // Update type badge from server data (kind is whitelisted before class use)
                            $origRow.find('.lmat-type-badge')
                                .removeClass(LmatGlossary.allowedGlossaryKinds.join(' '))
                                .addClass('lmat-type-badge ' + sanitizedKind)
                                .text(sanitizedKind.charAt(0).toUpperCase() + sanitizedKind.slice(1));

                            // Update translations from server data
                            if (updatedEntry.translations && Array.isArray(updatedEntry.translations)) {
                                // Create a map of translations from server - only include non-empty translations
                                const serverTranslations = {};
                                updatedEntry.translations.forEach(function(translation) {
                                    if (translation.target_language_code &&
                                        translation.translated_term &&
                                        translation.translated_term.trim() !== '') {
                                        serverTranslations[translation.target_language_code] = translation.translated_term.trim();
                                    }
                                });

                                // Update all language columns based on server data
                                $origRow.find('[class*="lmat-lang-col-"]').each(function() {
                                    const $cell = $(this);
                                    const langCode = $cell.attr('class').match(/lmat-lang-col-([a-zA-Z_-]+)/);

                                    if (langCode && langCode[1] && !$cell.data('is-source')) {
                                        const lang = langCode[1];
                                        const translatedTerm = serverTranslations[lang];

                                        if (translatedTerm && translatedTerm.trim() !== '') {
                                            // Has translation - show it
                                            const truncatedText = translatedTerm.length > 7 ? translatedTerm.substring(0, 7) + '…' : translatedTerm;
                                            const safeTruncated = LmatGlossary.escapeHtml(truncatedText);

                                            $cell.html(`
                                                <span class="lmat-translated-term"
                                                      data-full-text="${LmatGlossary.escapeAttr(translatedTerm)}"
                                                      title="${LmatGlossary.escapeAttr(translatedTerm)}">
                                                    ${safeTruncated}
                                                </span>
                                            `);
                                        } else {
                                            // No translation - show add button
                                            $cell.html(`
                                                <button type="button" class="lmat-edit-btn-svg" data-term="${LmatGlossary.escapeAttr(updatedEntry.original_term)}" data-source-lang="${LmatGlossary.escapeAttr(updatedEntry.original_language_code)}">
                                                    <img src="${lmat_glossary.url}assets/images/file.svg" alt="Add Translation" />
                                                </button>
                                            `);
                                        }
                                    }
                                });
                            }

                            // Show the updated row and remove edit row
                            $origRow.show();
                            $editRow.remove();
                            LmatGlossary.refreshGlossaryRows();

                            // Reapply filters and update UI
                            LmatGlossary.filterGlossaryRows();
                            LmatGlossary.updateGlossaryTableVisibility();
                            LmatGlossary.updateAlphabetButtonStates();
                            LmatGlossary.applyZebraStriping();
                        } else {
                            alert('Update failed: ' + (resp.data?.message || resp.data || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        alert('An error occurred while saving. Please try again.');
                    }
                });
            });

            // Handle Delete in glossary table
            $(document).off('click', '.lmat-delete-btn').on('click', '.lmat-delete-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();

                if (!confirm('Are you sure you want to delete this glossary entry?')) return;

                const $deleteBtn = $(this);
                const $row = $deleteBtn.closest('tr');
                const term = $row.data('term');
                const source_lang = $row.data('original-language');

                // Store original button content
                const originalContent = $deleteBtn.html();

                // Show loading state only on the delete button
                $deleteBtn.addClass('lmat-delete-loading');
                $deleteBtn.html('<div class="lmat-delete-spinner"></div>');
                $deleteBtn.prop('disabled', true);

                $.post(lmat_glossary.ajaxurl, {
                    action: 'lmat_delete_glossary',
                    _wpnonce: lmat_glossary.delete_glossary_validate,
                    term: term,
                    source_lang: source_lang
                }, function(resp) {
                    if (resp.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            LmatGlossary.refreshGlossaryRows();

                            // After row removal, check if any rows are visible
                            var $visibleRows = LmatGlossary.$glossaryRows.filter(':visible');
                            if ($visibleRows.length === 0) {
                                var $langBtns = LmatGlossary.$languageFilters.find('.lmat-lang-filter-btn');
                                var $activeBtn = $langBtns.filter('.active');
                                var idx = $langBtns.index($activeBtn);

                                // Remove the filter button for the language with no data
                                $activeBtn.remove();
                                $langBtns = LmatGlossary.$languageFilters.find('.lmat-lang-filter-btn'); // Refresh after removal

                                // Disable and deactivate all alphabet buttons if no data
                                var $alphabetBtns = LmatGlossary.$alphabet.find('.lmat-alphabet-btn');
                                $alphabetBtns.removeClass('active').prop('disabled', true);

                                // Hide language filter if only one button remains
                                if ($langBtns.length <= 1) {
                                    LmatGlossary.$languageFilters.hide();
                                }

                                // After removal, $langBtns is refreshed
                                if ($langBtns.length > 0) {
                                    // Try to activate the button at the same index (which is now the next one)
                                    var newIdx = Math.min(idx, $langBtns.length - 1);
                                    $langBtns.eq(newIdx).trigger('click');
                                } else {
                                    LmatGlossary.updateGlossaryTableVisibility();
                                }
                            } else {
                                LmatGlossary.updateGlossaryTableVisibility();
                            }
                        });
                    } else {
                        // Reset button state on error
                        $deleteBtn.removeClass('lmat-delete-loading');
                        $deleteBtn.html(originalContent);
                        $deleteBtn.prop('disabled', false);
                        alert('Delete failed: ' + (resp.data || 'Unknown error'));
                    }
                }).fail(function() {
                    // Reset button state on AJAX failure
                    $deleteBtn.removeClass('lmat-delete-loading');
                    $deleteBtn.html(originalContent);
                    $deleteBtn.prop('disabled', false);
                    alert('An error occurred while deleting. Please try again.');
                });
            });

            // Attach event listeners to required fields
            $addGlossaryForm.on('input', '.lmat-add-term, .lmat-add-type, .lmat-add-source-lang', function() {
                toggleTranslationsSection($addGlossaryForm);
            });

            // Ensure correct state on page load
            $(function() {
                toggleTranslationsSection($addGlossaryForm);
            });

            // Add input event handler for translation fields in add form
            $addGlossaryForm.on('input', '.lmat-add-translation', function() {
                const $textarea = $(this);
                const $error = $textarea.next('.lmat-translation-error');
                const value = $textarea.val().trim();
                const maxLength = 240;

                if (value.length > maxLength) {
                    $textarea.addClass('error');
                    $error.text(`Must be less than ${maxLength} characters`).show();
                } else {
                    $textarea.removeClass('error');
                    $error.hide();
                }
            });

            // Update the add glossary form submission handler
            $addGlossaryForm.off('submit').on('submit', function(e) {
                e.preventDefault();
                const $form = $(this);

                const $termField = $form.find('.lmat-add-term');
                const $descField = $form.find('.lmat-add-desc');
                const term = LmatGlossary.sanitizeInput($termField.val().trim());
                const desc = LmatGlossary.sanitizeInput($descField.val().trim());

                let hasError = false;

                // Check for script tags or other potentially harmful content
                if ($termField.val().trim() !== term || $descField.val().trim() !== desc) {
                    alert('Invalid input');
                    hasError = true;
                }

                // Check term and description length
                if (term.length > 240) {
                    $termField.addClass('error');
                    $termField.next('.lmat-translation-error').text('Term must be less than 240 characters.').show();
                    hasError = true;
                }

                if (desc.length > 240) {
                    $descField.addClass('error');
                    $descField.next('.lmat-translation-error').text('Description must be less than 240 characters.').show();
                    hasError = true;
                }

                // Check translation lengths and sanitize
                $form.find('.lmat-add-translation').each(function() {
                    const $textarea = $(this);
                    const originalValue = $textarea.val().trim();
                    const sanitizedValue = LmatGlossary.sanitizeInput(originalValue);

                    if (originalValue !== sanitizedValue) {
                        $textarea.addClass('error');
                        $textarea.next('.lmat-translation-error').text('Invalid input').show();
                        hasError = true;
                    }

                    if (sanitizedValue.length > 240) {
                        $textarea.addClass('error');
                        $textarea.next('.lmat-translation-error').text('Translation must be less than 240 characters.').show();
                        hasError = true;
                    }
                });

                if (hasError) {
                    return;
                }

                // Check for duplicate in the table
                var duplicate = false;
                $('.lmat-glossary-table tbody tr').each(function() {
                    var rowTerm = $(this).data('term');
                    var rowLang = $(this).data('original-language');
                    rowTerm = rowTerm && '' !== rowTerm ? String(rowTerm) : rowTerm;
                    if (rowTerm && rowLang && rowTerm.trim().toLowerCase() === term.toLowerCase() && rowLang === $form.find('.lmat-add-source-lang').val()) {
                        duplicate = true;
                        return false; // break loop
                    }
                });
                if (duplicate) {
                    alert('This term already exists in this language.');
                    return;
                }

                if (!$('.lmat-glossary-loader').length) {
                    const translations = $('#lmat-add-glossary-form').find('.lmat-add-translations')
                    translations.removeClass('lmat-show').css('display', 'none');
                    $('.lmat-glossary-modal-content').append('<div class="lmat-glossary-loader"><div class="lmat-glossary-loader-spinner"></div></div>');
                }

                const data = {
                    action: 'lmat_add_glossary',
                    _wpnonce: lmat_glossary.add_glossary_validate,
                    type: $form.find('.lmat-add-type').val(),
                    term: term,
                    description: desc,
                    source_lang: $form.find('.lmat-add-source-lang').val(),
                    translations: {}
                };
                (lmat_glossary.lmat_languages || []).forEach(function(lang) {
                    const langCode = lang.code;
                    if (langCode === data.source_lang) return;
                    const translationValue = LmatGlossary.sanitizeInput($form.find('[name="translation_' + langCode + '"]').val().trim());
                    // Only include non-empty translations
                    if (translationValue !== '' && translationValue.trim() !== '') {
                        data.translations[langCode] = translationValue;
                    }
                });

                $.post(lmat_glossary.ajaxurl, data, function(resp) {
                    $('.lmat-glossary-loader').remove();
                    if (resp.success) {
                        // Check if we have the added entry data from server
                        if (resp.data && resp.data.added_entry) {
                        $form.hide();
                        $('.lmat-glossary-modal-content h2').hide();
                        $addGlossarySuccess.removeClass('lmat-hidden');

                        // Use the data returned from the server
                        const addedEntry = resp.data.added_entry;
                        const savedTerm = addedEntry.original_term;
                        const savedDesc = addedEntry.description || '';
                        const savedType = addedEntry.kind;
                        const savedSourceLang = addedEntry.original_language_code;

                        // Create a map of saved translations - only include non-empty translations
                        const savedTranslations = {};
                        if (addedEntry.translations && Array.isArray(addedEntry.translations)) {
                            addedEntry.translations.forEach(function(translation) {
                                if (translation.target_language_code &&
                                    translation.translated_term &&
                                    translation.translated_term.trim() !== '') {
                                    savedTranslations[translation.target_language_code] = translation.translated_term.trim();
                                }
                            });
                        }

                        // Determine the previous state before adding the new entry
                        const existingRows = $('.lmat-glossary-table tbody tr');
                        const uniqueLanguagesBeforeAdd = new Set();
                        existingRows.each(function() {
                            const lang = $(this).data('original-language');
                            if (lang) uniqueLanguagesBeforeAdd.add(lang);
                        });
                        const wasSingleLanguageMode = uniqueLanguagesBeforeAdd.size <= 1;

                        // Determine if we're in single language mode after adding (like PHP logic)
                        const uniqueLanguagesAfterAdd = new Set(uniqueLanguagesBeforeAdd);
                        uniqueLanguagesAfterAdd.add(savedSourceLang);
                        const singleLanguageMode = uniqueLanguagesAfterAdd.size === 1;
                        const singleLanguageCode = singleLanguageMode ? savedSourceLang : '';

                        // Initialize translationsHtml
                        let translationsHtml = '';

                        // Generate columns for languages, respecting single language mode
                        lmat_glossary.lmat_languages.forEach(lang => {
                            const langCode = lang.code;

                            // Skip source language column if in single language mode
                            if (singleLanguageMode && langCode === singleLanguageCode) {
                                return;
                            }

                            if (langCode === savedSourceLang) {
                                // Source language - show the original term (only when not in single language mode)
                                const safeTerm = LmatGlossary.escapeHtml(savedTerm);
                                translationsHtml += `
                                    <td colspan="2" class="lmat-lang-col-${langCode}" data-lang="${langCode}" data-is-source="true">
                                        <span class="lmat-source-term">
                                            ${safeTerm}
                                        </span>
                                    </td>
                                `;
                            } else {
                                // Translation languages - check if we have a saved translation
                                const savedTranslation = savedTranslations[langCode];

                                if (!savedTranslation || savedTranslation.trim() === '') {
                                    // No translation - show add button
                                    translationsHtml += `
                                        <td colspan="2" class="lmat-lang-col-${langCode}" data-lang="${langCode}">
                                            <button type="button" class="lmat-edit-btn-svg" data-term="${LmatGlossary.escapeAttr(savedTerm)}" data-source-lang="${LmatGlossary.escapeAttr(savedSourceLang)}">
                                                <img src="${lmat_glossary.url}assets/images/file.svg" alt="Add Translation" />
                                            </button>
                                        </td>
                                    `;
                                } else {
                                    // Has translation - show it
                                    const truncatedText = savedTranslation.length > 7 ? savedTranslation.substring(0, 7) + '…' : savedTranslation;
                                    const safeTruncated = LmatGlossary.escapeHtml(truncatedText);
                                    translationsHtml += `
                                        <td colspan="2" class="lmat-lang-col-${langCode}" data-lang="${langCode}">
                                            <span class="lmat-translated-term"
                                                  data-full-text="${LmatGlossary.escapeAttr(savedTranslation)}"
                                                  title="${LmatGlossary.escapeAttr(savedTranslation)}">
                                                ${safeTruncated}
                                            </span>
                                        </td>
                                    `;
                                }
                            }
                        });

                        const firstLetter = savedTerm ? (isNaN(savedTerm[0]) ? savedTerm[0].toUpperCase() : '123') : '';
                        const sanitizedKind = LmatGlossary.sanitizeGlossaryKind(savedType);
                        const safeTerm = LmatGlossary.escapeHtml(savedTerm);
                        const safeDesc = LmatGlossary.escapeHtml(savedDesc);
                        const safeType = LmatGlossary.escapeHtml(sanitizedKind);
                        const safeTermAttr = LmatGlossary.escapeAttr(savedTerm);
                        const safeTypeAttr = LmatGlossary.escapeAttr(sanitizedKind);
                        const safeSourceLangAttr = LmatGlossary.escapeAttr(savedSourceLang);
                        const firstLetterAttr = LmatGlossary.escapeAttr(firstLetter);

                        const newEntryHtml = `
                            <tr data-term="${safeTermAttr}" data-original-language="${safeSourceLangAttr}" data-type="${safeTypeAttr}" data-letter="${firstLetterAttr}">
                                <td>
                                    <div class="lmat-entry-title">${safeTerm}</div>
                                    <div class="lmat-entry-desc">${safeDesc}</div>
                                </td>
                                <td>
                                    <span class="lmat-type-badge ${safeTypeAttr}">${safeType.charAt(0).toUpperCase() + safeType.slice(1)}</span>
                                </td>
                                ${translationsHtml}
                                <td class="lmat-actions-cell">
                                    <div class="lmat-action-buttons">
                                        <button type="button" class="lmat-edit-btn" data-term="${safeTermAttr}" data-source-lang="${safeSourceLangAttr}">Edit</button>
                                        <button type="button" class="lmat-delete-btn" data-term="${safeTermAttr}" data-source-lang="${safeSourceLangAttr}">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        `;

                        // Only recreate headers when transitioning from single to multi-language mode OR when no table exists
                        const needsHeaderUpdate = wasSingleLanguageMode && !singleLanguageMode;

                        // If the table does not exist, create it with headers
                        // OR if we're transitioning from single to multi-language mode, recreate headers
                        if ($('.lmat-glossary-table').length === 0 || needsHeaderUpdate) {

                            // Save existing table body if updating headers
                            let existingTbody = '';
                            if (needsHeaderUpdate) {
                                existingTbody = $('.lmat-glossary-table tbody').html();
                            }

                            let tableHeader = `
                                <table class="lmat-glossary-table" data-default-lang="${savedSourceLang}">
                                    <thead>
                                        <tr>
                                            <th colspan="2"></th>
                                            ${lmat_glossary.lmat_languages.filter(lang =>
                                                !(singleLanguageMode && lang.code === singleLanguageCode)
                                            ).map(lang =>
                                                `<th colspan="2" title="${lang.alt}" class="lmat-lang-header lmat-lang-col-${lang.code}" data-lang="${lang.code}">
                                                    ${lang.flag ? lang.flag : `<img src="${lang.img}" alt="${lang.alt}" />`}
                                                </th>`
                                            ).join('')}
                                            <th class="lmat-actions-cell">
                                                <div class="lmat-action-buttons-header">
                                                    <button class="lmat-actions-header-btn" id="lmat-actions-header-btn-left" title="Scroll Left">
                                                        <img src="${lmat_glossary.url}assets/images/arrow-left.svg" />
                                                    </button>
                                                    <button class="lmat-actions-header-btn" id="lmat-actions-header-btn-right" title="Scroll Right">
                                                        <img src="${lmat_glossary.url}assets/images/arrow-right.svg" />
                                                    </button>
                                                </div>
                                            </th>
                                        </tr>
                                        <tr>
                                            <th>Glossary Entry</th>
                                            <th>Type</th>
                                            ${lmat_glossary.lmat_languages.filter(lang =>
                                                !(singleLanguageMode && lang.code === singleLanguageCode)
                                            ).map(lang =>
                                                `<th colspan="2" data-lang="${lang.code}">${lang.alt}</th>`
                                            ).join('')}
                                            <th colspan="2">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            `;
                            LmatGlossary.$glossaryTableWrapper.html(tableHeader);

                            // Apply current language filter column hiding to headers
                            const currentActiveLang = $('.lmat-lang-filter-btn.active').data('lang');
                            if (currentActiveLang) {
                                $('.lmat-glossary-table').find(`th[data-lang="${currentActiveLang}"]`).hide();
                            }

                            // Restore existing table body if we were updating headers
                            if (needsHeaderUpdate && existingTbody) {
                                $('.lmat-glossary-table tbody').html(existingTbody);

                                // Need to update all existing rows to match new column structure
                                $('.lmat-glossary-table tbody tr').each(function() {
                                    const $row = $(this);
                                    const rowOriginalLang = $row.data('original-language');
                                    const rowTerm = $row.data('term');

                                    // Skip edit rows
                                    if ($row.hasClass('lmat-glossary-edit-row')) return;

                                     // Get existing translation data - EXCLUDE source terms to prevent mixing
                                     const existingTranslations = {};
                                     $row.find('[class*="lmat-lang-col-"]').each(function() {
                                         const $cell = $(this);
                                         const langMatch = $cell.attr('class').match(/lmat-lang-col-([a-zA-Z_-]+)/);
                                         if (langMatch && langMatch[1]) {
                                             const langCode = langMatch[1];

                                             // Check if this cell contains a source term - DON'T extract source terms as translations
                                             const hasSourceTerm = $cell.find('.lmat-source-term').length > 0;
                                             const isSourceByData = $cell.data('is-source') === true || $cell.data('is-source') === 'true';

                                             // Only get actual translations, NOT source terms
                                            if (!hasSourceTerm && !isSourceByData) {
                                                const $translatedTerm = $cell.find('.lmat-translated-term');
                                                if ($translatedTerm.length) {
                                                    let translation = $translatedTerm.data('full-text') || $translatedTerm.text().trim();

                                                    if(translation){
                                                        translation=translation.toString();
                                                    }

                                                    if (translation && typeof translation === 'string' && translation.trim() !== '') {
                                                        existingTranslations[langCode] = translation.trim();
                                                    }
                                                }
                                            }
                                         }
                                     });

                                    // Rebuild row HTML with all language columns
                                    let newTranslationsHtml = '';
                                    lmat_glossary.lmat_languages.forEach(lang => {
                                        const langCode = lang.code;

                                        // Skip source language column if in single language mode
                                        if (singleLanguageMode && langCode === singleLanguageCode) {
                                            return;
                                        }

                                        if (langCode === rowOriginalLang) {
                                            // Source language - show original term
                                            newTranslationsHtml += `
                                                <td colspan="2" class="lmat-lang-col-${langCode}" data-lang="${langCode}" data-is-source="true">
                                                    <span class="lmat-source-term">
                                                        ${LmatGlossary.escapeHtml(rowTerm)}
                                                    </span>
                                                </td>
                                            `;
                                        } else {
                                            // Translation language
                                            const existingTranslation = existingTranslations[langCode];
                                            if (existingTranslation && existingTranslation.trim() !== '') {
                                                // Has translation
                                                const truncatedText = existingTranslation.length > 7 ? existingTranslation.substring(0, 7) + '…' : existingTranslation;
                                                newTranslationsHtml += `
                                                    <td colspan="2" class="lmat-lang-col-${langCode}" data-lang="${langCode}">
                                                        <span class="lmat-translated-term"
                                                              data-full-text="${LmatGlossary.escapeAttr(existingTranslation)}"
                                                              title="${LmatGlossary.escapeAttr(existingTranslation)}">
                                                            ${LmatGlossary.escapeHtml(truncatedText)}
                                                        </span>
                                                    </td>
                                                `;
                                            } else {
                                                // No translation - show add button
                                                newTranslationsHtml += `
                                                    <td colspan="2" class="lmat-lang-col-${langCode}" data-lang="${langCode}">
                                                        <button type="button" class="lmat-edit-btn-svg" data-term="${LmatGlossary.escapeAttr(rowTerm)}" data-source-lang="${LmatGlossary.escapeAttr(rowOriginalLang)}">
                                                            <img src="${lmat_glossary.url}assets/images/file.svg" alt="Add Translation" />
                                                        </button>
                                                    </td>
                                                `;
                                            }
                                        }
                                    });

                                    // Update the row HTML
                                    const $termCell = $row.find('td:first');
                                    const $typeCell = $row.find('td:nth-child(2)');
                                    const $actionsCell = $row.find('.lmat-actions-cell');

                                    $row.html(`
                                        ${$termCell[0].outerHTML}
                                        ${$typeCell[0].outerHTML}
                                        ${newTranslationsHtml}
                                        ${$actionsCell[0].outerHTML}
                                    `);
                                });

                                // Apply current language filter column hiding to restored rows
                                const currentActiveLang = $('.lmat-lang-filter-btn.active').data('lang');
                                if (currentActiveLang) {
                                    $('.lmat-glossary-table tbody tr').each(function() {
                                        $(this).find(`td[data-lang="${currentActiveLang}"][data-is-source="true"]`).hide();
                                    });
                                }
                            }
                        }

                        // Append the new entry to the glossary table
                        $('.lmat-glossary-table tbody').append(newEntryHtml);
                        LmatGlossary.refreshGlossaryRows();

                        // Apply current language filter column hiding to the new row
                        const currentActiveLang = LmatGlossary.getActiveLangFilterBtn().data('lang');
                        if (currentActiveLang) {
                            const $newRow = $('.lmat-glossary-table tbody tr:last');
                            // Hide the source language column for the new row
                            $newRow.find(`td[data-lang="${currentActiveLang}"][data-is-source="true"]`).hide();
                        }

                        // Re-select the new table
                        LmatGlossary.$glossaryTable = $('.lmat-glossary-table');

                        // Update the UI (show table, remove no-results, apply zebra striping, etc.)
                        $('.lmat-glossary-table').show();
                        LmatGlossary.$glossaryTableWrapper.show();
                        LmatGlossary.$noResults.remove();
                        LmatGlossary.$noResults = $();
                        LmatGlossary.updateGlossaryTableVisibility();
                        LmatGlossary.applyZebraStriping();

                        // Update scroll button visibility
                        LmatGlossary.updateScrollButtonVisibility();

                        // Update language filter buttons, passing the saved language
                        LmatGlossary.updateLanguageFilterButtons(savedSourceLang);

                        // After adding, check if there are now two or more unique original languages and show filter bar if needed
                        const $rows = $('.lmat-glossary-table tbody tr');
                        const uniqueLangs = {};
                        $rows.each(function() {
                            const lang = $(this).data('original-language');
                            if (lang) uniqueLangs[lang] = true;
                        });
                        const langCodes = Object.keys(uniqueLangs);
                        const $filterBar = $('.lmat-language-filters');

                        // Remember the currently active filter before recreating buttons
                        const currentlyActiveLang = $('.lmat-lang-filter-btn.active').data('lang');

                        if (langCodes.length > 1) {
                            // If filter bar is empty, create buttons for all unique languages
                            if ($filterBar.length === 0) {
                                // Insert filter bar after controls if not present
                                $('<div class="lmat-language-filters"></div>').insertAfter('.lmat-alphabet');
                            }
                            const $newFilterBar = $('.lmat-language-filters');
                            $newFilterBar.empty();
                            langCodes.forEach(function(code, i) {
                                const langObj = (lmat_glossary.lmat_languages || []).find(l => l.code === code);
                                const label = langObj ? (langObj.alt + ' Terms') : (code + ' Terms');
                                const flag = langObj && langObj.img ? `<img src="${langObj.img}" alt="${langObj.alt}" /> ` : '';

                                // Preserve the currently active filter, or default to the newly added language if no active filter
                                const shouldBeActive = currentlyActiveLang === code || (!currentlyActiveLang && code === savedSourceLang);

                                $newFilterBar.append(`
                                    <button class="lmat-lang-filter-btn${shouldBeActive ? ' active' : ''}" data-lang="${code}">
                                        ${flag}${label}
                                    </button>
                                `);
                            });
                            $newFilterBar.show();
                        } else {
                            // If only one language, hide filter bar
                            $filterBar.empty().hide();
                        }

                        // Reapply the current language filter to ensure the new entry is visible
                        const activeLang = $('.lmat-lang-filter-btn.active').data('lang');
                        if (activeLang) {
                            // Trigger the language filter click to properly apply column hiding
                            $('.lmat-lang-filter-btn.active').trigger('click');
                        }
                        // Update alphabet buttons
                        LmatGlossary.updateAlphabetButtonStates();
                        } else {
                            // Fallback: Success but no server data - use original method with request data
                            console.warn('Success but no server data returned, using fallback method');
                            $form.hide();
                            $addGlossarySuccess.removeClass('lmat-hidden');
                            // Could add fallback logic here if needed, or just show success
                        }
                    } else {
                        alert(resp.data?.message || resp.data || 'Unknown error');
                    }
                });
            });

            // Add cancel edit handler
            LmatGlossary.$glossaryTable.on('click', '.lmat-cancel-edit-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const $editRow = $(this).closest('tr.lmat-glossary-edit-row');
                const $originalRow = $editRow.prev('tr');

                $originalRow.show();
                $editRow.remove();
                LmatGlossary.refreshGlossaryRows();
            });

            // Add input event handler for add form fields
            $addGlossaryForm.on('input', '.lmat-add-term, .lmat-add-desc', function() {
                const $textarea = $(this);
                const $error = $textarea.next('.lmat-translation-error');
                const value = $textarea.val().trim();
                const maxLength = 240;

                // Check for invalid characters (like script tags)
                if (value.includes('<') || value.includes('>')) {
                    $textarea.addClass('error');
                    $error.text('Invalid input').show();
                    return; // Stop further validation for this field
                }

                if (value.length > maxLength) {
                    $textarea.addClass('error');
                    $error.text(`Must be less than ${maxLength} characters`).show();
                } else {
                    $textarea.removeClass('error');
                    $error.hide();
                }
            });
        }
    };

})(jQuery, window.LmatGlossary = window.LmatGlossary || {});
