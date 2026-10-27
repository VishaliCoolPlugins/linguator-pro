jQuery(document).ready(function($) {
    $('#lmat_feedback_data, #lmat-cpfm-data-sharing').on('change', function() {
        let isChecked = $(this).is(':checked') ? 'yes' : 'no';
        if (typeof ajaxurl !== 'undefined' && typeof cpfm_ajax_obj !== 'undefined') {
            $.post(ajaxurl, {
                action: 'cpfm_save_usage_data_sharing',
                opt_in: isChecked,
                nonce: cpfm_ajax_obj.nonce
            });
        }
    });
});
