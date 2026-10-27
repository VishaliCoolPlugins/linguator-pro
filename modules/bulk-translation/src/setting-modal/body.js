import SettingModalLanguages from "./languages.js";
import SettingModalEngines from "./engines.js";

const SettingModalBody = (props) => {
    const { prefix } = props;

    return (
        <div className={`${prefix}-setting-modal-body`}>
            <div className={`${prefix}-setup-layout`}>
                <SettingModalLanguages
                    prefix={prefix}
                    targetLanguages={props.targetLanguages}
                    selectedLanguages={props.selectedLanguages}
                    onLanguageChange={props.onLanguageChange}
                    onSelectAllLanguages={props.onSelectAllLanguages}
                    notice={props.languageNotice}
                    disabled={props.languagesDisabled}
                    disabledReason={props.languagesDisabledReason}
                />

                <div className={`${prefix}-setup-divider`} aria-hidden="true">
                    <span className={`${prefix}-setup-divider-arrow`}>&#8594;</span>
                </div>

                <SettingModalEngines
                    prefix={prefix}
                    activeProvider={props.activeProvider}
                    onSelectProvider={props.onSelectProvider}
                    errorModalHandler={props.errorModalHandler}
                    localAiModalError={props.localAiModalError}
                    edgeLocalAiModalError={props.edgeLocalAiModalError}
                />
            </div>
        </div>
    );
}

export default SettingModalBody;
