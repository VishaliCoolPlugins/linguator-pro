jQuery(function($) {
    $(document).on('click', '.cpfm-see-terms, .lmat-see-terms', function(e) {
        e.preventDefault();
        const $termsBox = $(this).siblings('#termsBox, .lmat-terms-box');
        const $targetBox = $termsBox.length ? $termsBox : $('#termsBox, .lmat-terms-box');
        const isVisible = $targetBox.toggle().is(':visible');
        $(this).html(isVisible ? 'Hide Terms' : 'See terms');
    });
});