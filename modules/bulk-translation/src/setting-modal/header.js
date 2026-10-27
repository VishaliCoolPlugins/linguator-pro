import { __ } from "@wordpress/i18n";

const SettingModalHeader = ({ prefix, onClose }) => {
    return (
        <div className={`${prefix}-header`}>
            <div className={`${prefix}-modal-header-inner`}>
                <span className={`${prefix}-step-label`}>
                    {__('STEP 1 OF 2', 'linguator-pro')}
                </span>
                <h2>{__('AI Translation Settings', 'linguator-pro')}</h2>
                <p className={`${prefix}-modal-desc`}>
                    {__('Select your target languages and translation provider.', 'linguator-pro')}
                </p>
            </div>
            <button
                type="button"
                className={`${prefix}-modal-close`}
                onClick={onClose}
                title={__('Close', 'linguator-pro')}
                aria-label={__('Close', 'linguator-pro')}
            >
                &times;
            </button>
        </div>
    );
}

export default SettingModalHeader;
