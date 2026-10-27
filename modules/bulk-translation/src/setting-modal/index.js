import React, { useState, useEffect } from "react";
import SettingModalHeader from "./header.js";
import SettingModalBody from "./body.js";
import SettingModalFooter from "./footer.js";
import ErrorModalBox from "../components/error-modal-box/index.js";
import { resolveDefaultService } from "../components/translate-provider/index.js";

const SettingModal = (props) => {
    const prefix = props.prefix || 'lmat-bulk-translate';
    const [errorModal, setErrorModal] = useState(false);
    // Starts on the default provider saved in the translation settings.
    const [activeProvider, setActiveProvider] = useState(resolveDefaultService);
    const { localAiModalError, edgeLocalAiModalError } = props;

    useEffect(() => {
        // A provider that cannot run right now (built-in AI error, missing API key) must not stay selected.
        const disabledProviders = {
            localAiTranslator: !!localAiModalError,
            edgeLocalAiTranslator: !!edgeLocalAiModalError,
            gemini: !lmatBulkTranslationGlobal?.api_keys_status?.gemini,
        };

        if (activeProvider && disabledProviders[activeProvider]) {
            setActiveProvider(null);
        }
    }, [activeProvider, localAiModalError, edgeLocalAiModalError]);

    const startTranslationHandler = () => {
        props.updateProviderHandler(activeProvider);
    };

    const errorModalHandler = (msg) => {
        setErrorModal(msg);
    };

    const closeErrorModal = () => {
        setErrorModal(false);
    };

    if (errorModal) {
        return (
            <ErrorModalBox
                message={errorModal}
                onDestroy={props.onDestory}
                onClose={closeErrorModal}
                Title='Linguator Multilingual AI Translation'
                prefix={prefix}
            />
        );
    }

    return (
        <div id={`${prefix}-setting-modal-container`}>
            <div className={`${prefix}-setting-modal-content`}>
                <SettingModalHeader
                    prefix={prefix}
                    onClose={props.onDestory}
                />
                <SettingModalBody
                    prefix={prefix}
                    activeProvider={activeProvider}
                    onSelectProvider={setActiveProvider}
                    errorModalHandler={errorModalHandler}
                    localAiModalError={localAiModalError}
                    edgeLocalAiModalError={edgeLocalAiModalError}
                    targetLanguages={props.targetLanguages}
                    selectedLanguages={props.selectedLanguages}
                    onLanguageChange={props.onLanguageChange}
                    onSelectAllLanguages={props.onSelectAllLanguages}
                    languageNotice={props.languageNotice}
                    languagesDisabled={props.languagesDisabled}
                    languagesDisabledReason={props.languagesDisabledReason}
                />
                <SettingModalFooter
                    prefix={prefix}
                    selectedProvider={activeProvider}
                    selectedLanguages={props.selectedLanguages}
                    onStartTranslation={startTranslationHandler}
                />
            </div>
        </div>
    );
};

export default SettingModal;
