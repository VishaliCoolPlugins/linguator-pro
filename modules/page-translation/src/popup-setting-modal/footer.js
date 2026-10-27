import { __ } from "@wordpress/i18n";

const SettingModalFooter = (props) => {
    const { selectedProvider, onStartTranslation, setSettingVisibility, hasProviders = true } = props;

    if (!hasProviders) {
        return (
            <div className="setting-modal-footer">
                <button
                    type="button"
                    className="lmat-page-translation-setting-close button button-primary"
                    onClick={() => setSettingVisibility(false)}
                >
                    {__("Back", 'linguator-pro')}
                </button>
            </div>
        );
    }

    return (
        <div className="setting-modal-footer">
            <button
                type="button"
                className="setting-start-translation button button-primary"
                disabled={!selectedProvider}
                title={selectedProvider ? '' : __('Please select a translation provider.', 'linguator-pro')}
                onClick={() => {
                    if (selectedProvider && onStartTranslation) {
                        onStartTranslation();
                    }
                }}
            >
                {__("Start Translation", 'linguator-pro')} <span className="next-arrow">&#8594;</span>
            </button>
        </div>
    );
}

export default SettingModalFooter;
