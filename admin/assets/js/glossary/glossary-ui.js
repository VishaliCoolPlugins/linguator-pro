(function($, LmatGlossary) {
    'use strict';

    LmatGlossary.Ui = {
        init: function() {
            LmatGlossary.initCache();

            const $addBtn = LmatGlossary.$addBtn;
            const $importBtn = LmatGlossary.$importBtn;
            const $addGlossaryForm = LmatGlossary.$addGlossaryForm;
            const $addGlossarySuccess = LmatGlossary.$addGlossarySuccess;
            const $glossaryTable = LmatGlossary.$glossaryTable;
            const $glossaryTableWrapper = LmatGlossary.$glossaryTableWrapper;

            // Open modal
            $addBtn.on('click', function() {
                $('#lmat-glossary-modal-add').removeClass('lmat-hidden').addClass('active');
                $('body').addClass('lmat-modal-open');
                $('.lmat-glossary-modal-content h2').show();
                // Reset add form and translations section
                $addGlossaryForm.find('.lmat-add-translations').removeClass('lmat-show').css('display', 'none');
                $addGlossaryForm.find('.lmat-translation-field').show();
                $addGlossaryForm[0].reset();
            });
            $importBtn.on('click', function() {
                LmatGlossary.resetImportModalUI();
                $('#lmat-glossary-modal-import').removeClass('lmat-hidden').addClass('active');
            });

            // Close modal & reset
            $(document).on('click', '.lmat-modal-close-btn, .lmat-glossary-modal-actions-left', function() {
                $('.lmat-glossary-modal-content h2').show();
                const modal = $(this).closest('.lmat-glossary-modal');
                const importSuccessUI = modal.find('#lmat-import-success-ui');
                if ((importSuccessUI.length && !importSuccessUI.hasClass('lmat-hidden') && importSuccessUI.is(':visible'))) {
                    window.location.reload();
                }
                modal.addClass('lmat-hidden').removeClass('active');
                modal.find('form').show();
                $addGlossarySuccess.addClass('lmat-hidden');

                $('body').removeClass('lmat-modal-open');
                LmatGlossary.resetImportModalUI();
            });

            // Add close button handler for success message
            $addGlossarySuccess.on('click', '#lmat-glossary-success-close', function() {
                $('#lmat-glossary-modal-add').addClass('lmat-hidden').removeClass('active');
                $('#lmat-glossary-modal-add').find('form').show();
                $addGlossarySuccess.addClass('lmat-hidden');
            });

            // Optionally, also close on "Cancel" in the modal
            $(document).on('click', '.lmat-glossary-modal-actions-left', function() {
                $(this).closest('.lmat-glossary-modal').addClass('lmat-hidden').removeClass('active');
            });

            // Initial check for actions header visibility
            LmatGlossary.updateActionsHeaderVisibility();

            // Update on window resize
            $(window).on('resize', _.debounce(function() {
                LmatGlossary.updateActionsHeaderVisibility();
            }, 250));

            // Update after any content changes that might affect table width
            const observer = new MutationObserver(_.debounce(function() {
                LmatGlossary.updateActionsHeaderVisibility();
            }, 250));

            // Observe the table wrapper for changes
            if ($glossaryTableWrapper.length) {
                observer.observe($glossaryTableWrapper[0], {
                    childList: true,
                    subtree: true,
                    attributes: true
                });
            }

            // Call this function on page load
            $(document).ready(function() {
                LmatGlossary.updateScrollButtonVisibility();
            });

            // Call this function after scrolling
            $glossaryTable.on('scroll', function() {
                LmatGlossary.updateScrollButtonVisibility();
            });

            // Scroll table to the right when actions header button is clicked
            $glossaryTableWrapper.off('click', '#lmat-actions-header-btn-right').on('click', '#lmat-actions-header-btn-right', function(e) {
                e.preventDefault();
                const $wrapper = $(this).closest('.lmat-glossary-table-wrapper');
                const scrollAmount = 300;
                $wrapper.animate({
                    scrollLeft: $wrapper.scrollLeft() + scrollAmount
                }, 400, LmatGlossary.updateScrollButtonVisibility);
            });

            // Scroll table to the left when actions header button is clicked
            $glossaryTableWrapper.off('click', '#lmat-actions-header-btn-left').on('click', '#lmat-actions-header-btn-left', function(e) {
                e.preventDefault();
                const $wrapper = $(this).closest('.lmat-glossary-table-wrapper');
                const scrollAmount = 300;
                    $wrapper.animate({
                    scrollLeft: $wrapper.scrollLeft() - scrollAmount
                }, 400, LmatGlossary.updateScrollButtonVisibility);
            });
        }
    };

    LmatGlossary.updateActionsHeaderVisibility = function() {
        const $actionsHeader = $('.lmat-actions-header-btn').closest('th');

        // Only proceed if the table exists
        if (LmatGlossary.$glossaryTable.length === 0 || !LmatGlossary.$glossaryTable[0]) {
            $actionsHeader.hide();
            return;
        }

        // Check if table has horizontal scroll
        const hasHorizontalScroll = LmatGlossary.$glossaryTable[0].scrollWidth > LmatGlossary.$glossaryTableWrapper[0].clientWidth;

        // Show/hide actions header based on scroll
        $actionsHeader.toggle(hasHorizontalScroll);
    };

    LmatGlossary.updateScrollButtonVisibility = function() {
        // Check if wrapper exists
        if (!LmatGlossary.$glossaryTableWrapper.length) {
            return;
        }

        // Check if wrapper has content
        if (!LmatGlossary.$glossaryTableWrapper[0]) {
            return;
        }

        const scrollLeft = LmatGlossary.$glossaryTableWrapper.scrollLeft();
        const scrollWidth = LmatGlossary.$glossaryTableWrapper[0].scrollWidth;
        const clientWidth = LmatGlossary.$glossaryTableWrapper[0].clientWidth;

        // Hide left button if at the leftmost position
        if (scrollLeft === 0) {
            $('#lmat-actions-header-btn-left').css('visibility', 'hidden');
        } else {
            $('#lmat-actions-header-btn-left').css('visibility', 'visible');
        }

        if (scrollLeft + clientWidth >= scrollWidth) {
            $('#lmat-actions-header-btn-right').css('visibility', 'hidden'); // Use hide() to remove from layout
        } else {
            $('#lmat-actions-header-btn-right').css('visibility', 'visible'); // Use show() to display
        }

    };

})(jQuery, window.LmatGlossary = window.LmatGlossary || {});
