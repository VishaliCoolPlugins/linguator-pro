(function($, LmatGlossary) {
    'use strict';

    LmatGlossary.resetImportModalUI = function() {
        $('#lmat-import-success-ui').addClass('lmat-hidden');
        $('#lmat-import-glossary-ui').show();
        $('#file-name-display').text('Select a CSV file to upload');
        $('#importing-file-name').text('');
        $('#lmat-csv-upload').val('');
    };

    LmatGlossary.ImportExport = {
        init: function() {
            LmatGlossary.initCache();

            // File input change
            $('#lmat-csv-upload').on('change', function(e) {
                const file = e.target.files[0];

                if (!file || !file.name.toLowerCase().endsWith('.csv')) {
                    alert('Please upload a valid CSV file.');
                    $(this).val('');
                    return;
                }

                // If valid file:
                $('#file-name-display').text(file.name);
                $('#importing-file-name').text(file.name);

                // Automatically import as soon as file is selected
                const formData = new FormData();
                formData.append('action', 'lmat_import_glossary');
                formData.append('csv_file', file);
                formData.append('overwrite', false);
                formData.append('_wpnonce', lmat_glossary.import_glossary_validate);

                if (!$('.lmat-glossary-loader').length) {
                    $('.lmat-glossary-modal-content').append('<div class="lmat-glossary-loader"><div class="lmat-glossary-loader-spinner"></div></div>');
                }
                $.ajax({
                    url: lmat_glossary.ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            // Remove loader
                           $('.lmat-glossary-loader').remove();
                            $('#lmat-import-glossary-ui').hide();
                            $('#lmat-import-success-ui').removeClass('lmat-hidden');
                        } else {
                            alert('Import failed: ' + (resp.data || 'Unknown error'));
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert('An error occurred while importing the glossary. Please try again.');
                    }
                });
            });

            // Modify the download link click handler
            $('.lmat-download-link').off('click').on('click', function(e) {
                e.preventDefault();
                // CSV content matching your image
                const csvContent = [
                    [
                        'original_language_code',
                        'target_language_code',
                        'original_term',
                        'translated_term',
                        'description',
                        'kind'
                    ],
                    ['en', 'it', 'Page', 'Pagina', 'the page of a browser', 'general'],
                    ['en', 'it', 'page', 'pagina', 'the page of a browser', 'general'],
                    ['en', 'it', 'OnTheGoSystems', 'OnTheGoSystems', 'the name of my company', 'name']
                ].map(row => row.join(",")).join("\n");

                const blob = new Blob([csvContent], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);

                const a = document.createElement('a');
                a.href = url;
                a.download = 'sample-glossary.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });

            // Export Glossary Button - Fix double download issue
            $(document).off('click', '.lmat-export-btn').on('click', '.lmat-export-btn', function(e) {
                e.preventDefault();
                e.stopPropagation(); // Prevent event bubbling

                // Create a temporary link to trigger the download
                var url = lmat_glossary.ajaxurl + '?action=lmat_export_glossary';
                url += '&_wpnonce=' + lmat_glossary.export_glossary_validate;
                var link = document.createElement('a');
                link.href = url;
                link.download = 'glossary-export.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });

            // Close handler for import success message
            $(document).on('click', '.lmat-import-close-btn', function() {
                // Hide the import success UI
                $('#lmat-import-success-ui').addClass('lmat-hidden');
                // Optionally, also close the modal
                $('#lmat-glossary-modal-import').addClass('lmat-hidden');

                window.location.reload();
            });
        }
    };

})(jQuery, window.LmatGlossary = window.LmatGlossary || {});
