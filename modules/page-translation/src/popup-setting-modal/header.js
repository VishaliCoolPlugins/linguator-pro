import { __ } from "@wordpress/i18n";

const SettingModalHeader = ({ setSettingVisibility, hasProviders = true }) => {
    const title = hasProviders
        ? __("Select Translation Engine", 'translate-words')
        : __("Translation Provider Not Configured", 'translate-words');
    const description = hasProviders
        ? __("Select an AI provider to automatically translate your content.", 'translate-words')
        : __("Enable at least one provider to translate your content.", 'translate-words');

    return (
        <div className="modal-header">
            <div className="modal-header-inner">
                {hasProviders && (
                    <span className="step-label">
                        {__("STEP 1 OF 2", 'translate-words')}
                    </span>
                )}
                <h2>{title}</h2>
                <p className="modal-desc">{description}</p>
            </div>
            <button
                type="button"
                className="modal-close"
                title={__('Close', 'translate-words')}
                aria-label={__('Close', 'translate-words')}
                onClick={() => setSettingVisibility(false)}
            >
                &times;
            </button>
        </div>
    );
}

export default SettingModalHeader;
