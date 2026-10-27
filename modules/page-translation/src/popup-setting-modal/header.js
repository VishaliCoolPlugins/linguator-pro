import { __ } from "@wordpress/i18n";

const SettingModalHeader = ({ setSettingVisibility, hasProviders = true }) => {
    const title = hasProviders
        ? __("Select Translation Engine", 'linguator-pro')
        : __("Translation Provider Not Configured", 'linguator-pro');
    const description = hasProviders
        ? __("Select an AI provider to automatically translate your content.", 'linguator-pro')
        : __("Enable at least one provider to translate your content.", 'linguator-pro');

    return (
        <div className="modal-header">
            <div className="modal-header-inner">
                {hasProviders && (
                    <span className="step-label">
                        {__("STEP 1 OF 2", 'linguator-pro')}
                    </span>
                )}
                <h2>{title}</h2>
                <p className="modal-desc">{description}</p>
            </div>
            <button
                type="button"
                className="modal-close"
                title={__('Close', 'linguator-pro')}
                aria-label={__('Close', 'linguator-pro')}
                onClick={() => setSettingVisibility(false)}
            >
                &times;
            </button>
        </div>
    );
}

export default SettingModalHeader;
