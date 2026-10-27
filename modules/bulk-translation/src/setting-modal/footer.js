import { __ } from "@wordpress/i18n";

const SettingModalFooter = ({
    prefix,
    selectedProvider,
    selectedLanguages = [],
    onStartTranslation,
}) => {
    const canStart = Boolean(selectedProvider) && selectedLanguages.length > 0;

    let startTitle = '';
    if (selectedLanguages.length === 0) {
        startTitle = __('Please select at least one language.', 'linguator-pro');
    } else if (!selectedProvider) {
        startTitle = __('Please select a translation provider.', 'linguator-pro');
    }

    return (
        <div className={`${prefix}-footer`}>
            <button
                type="button"
                className={`${prefix}-footer-button button button-primary`}
                disabled={!canStart}
                title={startTitle}
                onClick={() => {
                    if (canStart && onStartTranslation) {
                        onStartTranslation();
                    }
                }}
            >
                {__('Start Translation', 'linguator-pro')} <span className={`${prefix}-next-arrow`}>&#8594;</span>
            </button>
        </div>
    );
}

export default SettingModalFooter;
