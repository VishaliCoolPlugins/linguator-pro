/**
 * Adds a progress bar to the container.
 * 
 * @param {HTMLElement} container - The container element for translation.
 */
const AddProgressBar = (provider) => {
    const providerName = {
        gemini: "Google Gemini",
        google: "Google Translate",
    }

    const progressBarSelector = "#lmat_page_translation_strings_model .lmat_page_translation_translate_progress";

    if (!document.querySelector(`#lmat-page-translation-${provider}-progress-bar`)) {
        const progressBar = jQuery(`
            <div id="lmat-page-translation-${provider}-progress-bar" class="lmat-page-translation-translate-progress-bar">
                <div class="${provider}-translator_progress_bar" style="margin: 1.5rem auto; width: 50%;">
                <div class="${provider}-translator_progress"></div>
                </div>
                <div style="display:none; color: white;" class="${provider}-translator-strings-count hidden">
                    Wahooo! You have saved your valuable time via auto translating 
                    <strong class="totalChars"></strong> characters using 
                    <strong>
                        ${providerName[provider]}
                    </strong>
                </div>
            </div>
        `);
        jQuery(progressBarSelector).append(progressBar); // Append the progress bar to the specified selector
    }else{
        jQuery(`.${provider}-translator_progress`).css('width', '0%');
        jQuery(`.${provider}-translator-strings-count`).hide();
    }
}

export default AddProgressBar;