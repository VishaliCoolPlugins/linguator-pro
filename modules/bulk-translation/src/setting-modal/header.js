import { __ } from "@wordpress/i18n";

const SettingModalHeader = ({ prefix, onClose }) => {
    return (
        <div className={`${prefix}-header`}>
            <div className={`${prefix}-modal-header-inner`}>
                <span className={`${prefix}-step-label`}>
                    {__('STEP 1 OF 2', 'translate-words')}
                </span>
                <h2>{__('AI Translation Settings', 'translate-words')}</h2>
                <p className={`${prefix}-modal-desc`}>
                    {__('Select your target languages and translation provider.', 'translate-words')}
                </p>
            </div>
            <button
                type="button"
                className={`${prefix}-modal-close`}
                onClick={onClose}
                title={__('Close', 'translate-words')}
                aria-label={__('Close', 'translate-words')}
            >
                &times;
            </button>
        </div>
    );
}

export default SettingModalHeader;
