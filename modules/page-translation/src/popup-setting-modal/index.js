import ReactDOM from "react-dom/client";
import { useEffect, useState } from "@wordpress/element";
import PopStringModal from "../popup-string-modal/index.js";
import googleLanguage, { mapToGoogleLanguageCode } from "../component/translate-provider/google/google-language.js";
import ChromeLocalAiTranslator from "../component/translate-provider/local-ai-translator/local-ai-translator.js";
import SettingModalHeader from "./header.js";
import SettingModalBody from "./body.js";
import SettingModalFooter from "./footer.js";
import { __, sprintf } from "@wordpress/i18n";   
import ErrorModalBox from "../component/error-modal-box/index.js";
import TranslateService, { resolveDefaultService } from "../component/translate-provider/index.js";

const SettingModal = (props) => {
    const [activeService, setActiveService] = useState({});
    // Provider card ticked in the popup; starts on the default saved in the translation settings.
    const [selectedProvider, setSelectedProvider] = useState(resolveDefaultService);
    const [modalRender, setModalRender] = useState(0);
    const [settingVisibility, setSettingVisibility] = useState(false);
    const [noValidProvider, setNoValidProvider] = useState(false);
    const sourceLang = lmatPageTranslationGlobal.source_lang;
    const targetLang = props.targetLang;
    const sourceLangName = lmatPageTranslationGlobal.languageObject[sourceLang]['name'];
    const targetLangName = lmatPageTranslationGlobal.languageObject[targetLang]['name'];
    const imgFolder = lmatPageTranslationGlobal.lmat_url + 'admin/assets/images/';
    const googleSupport = googleLanguage().includes(mapToGoogleLanguageCode(targetLang, lmatPageTranslationGlobal.languageObject || {}));
    const [serviceModalErrors, setServiceModalErrors] = useState({});
    const [errorModalVisibility, setErrorModalVisibility] = useState(false);
    const [chromeAiBtnDisabled, setChromeAiBtnDisabled] = useState(false);
    const [edgeAiBtnDisabled, setEdgeAiBtnDisabled] = useState(false);
    const [chromeAiDownload, setChromeAiDownload] = useState(null);
    const [edgeAiDownload, setEdgeAiDownload] = useState(null);
    const providers = lmatPageTranslationGlobal.providers;
    const validProviders = Object.keys(TranslateService());

    const showNoCompatibleProviderModal = () => {
        if (providers.length < 1) {
            openErrorModalHandler('providerNotConfigured');
            return;
        }
        setNoValidProvider(true);
    };

    const openModalOnLoadHandler = (e) => {
        e.preventDefault();
        const btnElement = e.currentTarget;
        const visibility = btnElement.dataset.value;

        if (visibility === 'yes') {
            setSettingVisibility(true);
        }

        btnElement.closest('#lmat-page-translation-modal-open-warning-wrapper').remove();
    }

    const closeErrorModal = () => {
        setErrorModalVisibility(false);
        // The provider list was hidden while the error was open; bring it back.
        setSettingVisibility(true);
    }

    const openErrorModalHandler = (service) => {
        setSettingVisibility(false);
        setErrorModalVisibility(service);
    }

    const handleMetaFieldBtnClick = async (e) => {
        e.preventDefault();

        // No enabled provider, or none compatible with this browser (e.g. Chrome AI on Edge).
        if (validProviders.length < 1) {
            showNoCompatibleProviderModal();
            return;
        }

        // Check the target language per provider first, so the cards open with their real state.
        await providerErrors();
        setSettingVisibility(true);
    }

    /**
     * useEffect hook to set settingVisibility.
     * Triggers the setSettingVisibility only when user click on meta field Button.
    */
    useEffect(() => {
        const firstRenderBtns = document.querySelectorAll('#lmat-page-translation-modal-open-warning-wrapper .modal-content [data-value]');
        const metaFieldBtn = document.querySelector(props.translateWrpSelector);

        if (metaFieldBtn) {
            metaFieldBtn.removeEventListener('click', handleMetaFieldBtnClick);
            metaFieldBtn.addEventListener('click', handleMetaFieldBtnClick);
        }

        firstRenderBtns.forEach(ele => {
            if (ele) {
                ele.addEventListener('click', openModalOnLoadHandler);
            }
        })
        return () => {
            if (metaFieldBtn) {
                metaFieldBtn.removeEventListener('click', handleMetaFieldBtnClick);
            }
        }
    }, [serviceModalErrors])

    const providerErrors = async () => {
        let errors = {};

        if (validProviders.includes('localAiTranslator')) {
            setChromeAiDownload(prev => prev && (prev.status === 'downloadable' || prev.status === 'downloading' || prev.status === 'failed')
                ? prev
                : { status: 'checking', progress: 0 });
        }
        if (validProviders.includes('edgeLocalAiTranslator')) {
            setEdgeAiDownload(prev => prev && (prev.status === 'downloadable' || prev.status === 'downloading' || prev.status === 'failed')
                ? prev
                : { status: 'checking', progress: 0 });
        }

        const localAiSupportStatus = async () => {
            const localAiTranslatorSupport = await ChromeLocalAiTranslator.languageSupportedStatus(sourceLang, targetLang, targetLangName, sourceLangName, false);

            if (localAiTranslatorSupport !== true && typeof localAiTranslatorSupport === 'object') {
                if (localAiTranslatorSupport.hasClass && localAiTranslatorSupport.hasClass('atlt-chromeai-download-pending')) {
                    const isDownloading = localAiTranslatorSupport.hasClass('atlt-chromeai-downloading');
                    setChromeAiDownload({
                        status: isDownloading ? 'downloading' : 'downloadable',
                        progress: 0,
                        downloadStarted: false,
                        source: localAiTranslatorSupport.attr('data-source'),
                        target: localAiTranslatorSupport.attr('data-target'),
                        sourceLabel: localAiTranslatorSupport.attr('data-source-label'),
                        targetLabel: localAiTranslatorSupport.attr('data-target-label')
                    });
                    errors.chromeAiDownloadPending = true;
                } else {
                    setChromeAiBtnDisabled(true);
                    setChromeAiDownload(null);
                    errors.localAiTranslator = { message: localAiTranslatorSupport, Title: __("Chrome AI Translator", 'translate-words') };
                    setServiceModalErrors(prev => ({ ...prev, localAiTranslator: errors.localAiTranslator }));
                }
            } else {
                setChromeAiBtnDisabled(false);
                setChromeAiDownload(null);
                setServiceModalErrors(prev => {
                    const next = { ...prev };
                    delete next.localAiTranslator;
                    return next;
                });
            }
        };

        const edgeLocalAiSupportStatus = async () => {
            const edgeLocalAiTranslatorSupport = await ChromeLocalAiTranslator.languageSupportedStatus(sourceLang, targetLang, targetLangName, sourceLangName, true);

            if (edgeLocalAiTranslatorSupport !== true && typeof edgeLocalAiTranslatorSupport === 'object') {
                if (edgeLocalAiTranslatorSupport.hasClass && edgeLocalAiTranslatorSupport.hasClass('atlt-chromeai-download-pending')) {
                    const isDownloading = edgeLocalAiTranslatorSupport.hasClass('atlt-chromeai-downloading');
                    setEdgeAiDownload({
                        status: isDownloading ? 'downloading' : 'downloadable',
                        progress: 0,
                        downloadStarted: false,
                        source: edgeLocalAiTranslatorSupport.attr('data-source'),
                        target: edgeLocalAiTranslatorSupport.attr('data-target'),
                        sourceLabel: edgeLocalAiTranslatorSupport.attr('data-source-label'),
                        targetLabel: edgeLocalAiTranslatorSupport.attr('data-target-label')
                    });
                    errors.edgeAiDownloadPending = true;
                } else {
                    setEdgeAiBtnDisabled(true);
                    setEdgeAiDownload(null);
                    errors.edgeLocalAiTranslator = { message: edgeLocalAiTranslatorSupport, Title: __("Edge AI Translator", 'translate-words') };
                    setServiceModalErrors(prev => ({ ...prev, edgeLocalAiTranslator: errors.edgeLocalAiTranslator }));
                }
            } else {
                setEdgeAiBtnDisabled(false);
                setEdgeAiDownload(null);
                setServiceModalErrors(prev => {
                    const next = { ...prev };
                    delete next.edgeLocalAiTranslator;
                    return next;
                });
            }
        }

        const googleSupportStatus = async () => {
            if (!googleSupport) {
                errors.google = {


                    message: "<p style={{ fontSize: '1rem', color: '#ff4646' }}>" + sprintf(
                        __("Google Translate does not support the target language: %s.", 'translate-words'),
                        "<strong>" + targetLangName + "</strong>"
                    ) + "</p>",
                    Title: __("Google Translate", 'translate-words')
                };

                setServiceModalErrors(prev => ({
                    ...prev,
                    google: errors.google
                }));
            }

        }

        if (validProviders.includes('localAiTranslator')) {
            await localAiSupportStatus();
        }
        if (validProviders.includes('edgeLocalAiTranslator')) {
            await edgeLocalAiSupportStatus();
        }
        if (validProviders.includes('google')) {
            await googleSupportStatus();
        }

        return errors;
    }

    /**
     * useEffect hook to check if the local AI translator is supported.
     */
    useEffect(() => {
        if (providers.length < 1) {
            let providerConfigMsg = sprintf(__(
                '%sYou have not enabled any translation provider. Please enable at least one service provider to use automatic translation. Go to the %sTranslation Settings%s to configure a translation provider.%s',
                'translate-words'
            ),
                '<p>',
                `<strong><a href='${lmatPageTranslationGlobal.admin_url}admin.php?page=lmat_settings&tab=translation' target='_blank' rel='noopener noreferrer'>`,
                '</a></strong>',
                '</p>');
            setServiceModalErrors((prev) => ({ ...prev, providerNotConfigured: { message: providerConfigMsg, Title: __("Translation Provider Not Configured", 'translate-words') } }));
            return;
        }
        if (settingVisibility) {
            providerErrors();
        }
    }, [settingVisibility]);

    /**
     * A provider that cannot run right now (language not supported, built-in AI error or
     * language pack pending, missing API key) must not stay selected.
     */
    useEffect(() => {
        const unavailableProviders = {
            google: !googleSupport,
            localAiTranslator: chromeAiBtnDisabled || !!chromeAiDownload,
            edgeLocalAiTranslator: edgeAiBtnDisabled || !!edgeAiDownload,
            gemini: !lmatPageTranslationGlobal?.api_keys_status?.gemini,
        };

        if (selectedProvider && unavailableProviders[selectedProvider]) {
            setSelectedProvider(null);
        }
    }, [selectedProvider, googleSupport, chromeAiBtnDisabled, edgeAiBtnDisabled, chromeAiDownload, edgeAiDownload]);

    /**
     * useEffect hook to handle displaying the modal and rendering the PopStringModal component.
     */
    useEffect(() => {
        const activeServiceObject = TranslateService({ Service: activeService, [activeService + "ButtonDisabled"]: false });

        const service = activeService;
        const serviceLabel = activeServiceObject && activeServiceObject.serviceLabel;

        const postId = props.postId;

        const parentWrp = document.getElementById("lmat_page_translation_strings_model");

        if (parentWrp) {
            // Store root instance in a ref to avoid recreating it
            if (!parentWrp._reactRoot) {
                parentWrp._reactRoot = ReactDOM.createRoot(parentWrp);
            }

            if (modalRender) {
                parentWrp._reactRoot.render(<PopStringModal
                    currentPostId={props.currentPostId}
                    postId={postId}
                    service={service}
                    serviceLabel={serviceLabel}
                    sourceLang={sourceLang}
                    targetLang={targetLang}
                    modalRender={modalRender}
                    pageTranslate={props.pageTranslate}
                    postDataFetchStatus={props.postDataFetchStatus}
                    fetchPostData={props.fetchPostData}
                    translatePost={props.translatePost}
                    contentLoading={props.contentLoading}
                    updatePostDataFetch={props.updatePostDataFetch}
                    stringModalBodyNotice={props.stringModalBodyNotice}
                />);
            }
        }
    }, [props.postDataFetchStatus, modalRender]);

    /**
     * Starts the translation with the provider selected in the popup.
     */
    const fetchContent = async () => {
        const dataService = selectedProvider;

        if (!dataService) {
            return;
        }

        if (dataService === 'localAiTranslator') {
            const localAiTranslatorSupport = await ChromeLocalAiTranslator.languageSupportedStatus(sourceLang, targetLang, targetLangName, sourceLangName, false);
            if (localAiTranslatorSupport !== true && typeof localAiTranslatorSupport === 'object') {
                if (localAiTranslatorSupport.hasClass && localAiTranslatorSupport.hasClass('atlt-chromeai-download-pending')) {
                    const isDownloading = localAiTranslatorSupport.hasClass('atlt-chromeai-downloading');
                    setChromeAiDownload({
                        status: isDownloading ? 'downloading' : 'downloadable',
                        progress: 0,
                        downloadStarted: false,
                        source: localAiTranslatorSupport.attr('data-source'),
                        target: localAiTranslatorSupport.attr('data-target'),
                        sourceLabel: localAiTranslatorSupport.attr('data-source-label'),
                        targetLabel: localAiTranslatorSupport.attr('data-target-label')
                    });
                } else {
                    setErrorModalVisibility('localAiTranslator');
                    setServiceModalErrors(prev => ({ ...prev, localAiTranslator: { message: localAiTranslatorSupport[0]?.outerHTML || localAiTranslatorSupport, Title: __("Chrome AI Translator", 'translate-words') } }));
                }
                return;
            }
        }

        if (dataService === 'edgeLocalAiTranslator') {
            const edgeLocalAiTranslatorSupport = await ChromeLocalAiTranslator.languageSupportedStatus(sourceLang, targetLang, targetLangName, sourceLangName, true);
            if (edgeLocalAiTranslatorSupport !== true && typeof edgeLocalAiTranslatorSupport === 'object') {
                if (edgeLocalAiTranslatorSupport.hasClass && edgeLocalAiTranslatorSupport.hasClass('atlt-chromeai-download-pending')) {
                    const isDownloading = edgeLocalAiTranslatorSupport.hasClass('atlt-chromeai-downloading');
                    setEdgeAiDownload({
                        status: isDownloading ? 'downloading' : 'downloadable',
                        progress: 0,
                        downloadStarted: false,
                        source: edgeLocalAiTranslatorSupport.attr('data-source'),
                        target: edgeLocalAiTranslatorSupport.attr('data-target'),
                        sourceLabel: edgeLocalAiTranslatorSupport.attr('data-source-label'),
                        targetLabel: edgeLocalAiTranslatorSupport.attr('data-target-label')
                    });
                } else {
                    setErrorModalVisibility('edgeLocalAiTranslator');
                    setServiceModalErrors(prev => ({ ...prev, edgeLocalAiTranslator: { message: edgeLocalAiTranslatorSupport[0]?.outerHTML || edgeLocalAiTranslatorSupport, Title: __("Edge AI Translator", 'translate-words') } }));
                }
                return;
            }
        }

        setSettingVisibility(false);
        setModalRender(prev => prev + 1);
        setActiveService(dataService);
    };

    const handleSettingVisibility = (visibility) => {
        setSettingVisibility(visibility);
        if (!visibility) {
            setNoValidProvider(false);
        }
    }

    const hasProviders = validProviders.length > 0;
    const isSettingModalOpen = settingVisibility || noValidProvider;

    return (
        <>
            {errorModalVisibility && serviceModalErrors[errorModalVisibility] &&
                <ErrorModalBox onClose={closeErrorModal} {...serviceModalErrors[errorModalVisibility]} />
            }
            {isSettingModalOpen &&
                <div className="modal-container" style={{ display: 'flex' }}>
                    <div className="lmat-page-translation-settings modal-content">
                        <SettingModalHeader
                            setSettingVisibility={handleSettingVisibility}
                            hasProviders={hasProviders}
                        />
                        <SettingModalBody
                            hasProviders={hasProviders}
                            googleDisabled={!googleSupport}
                            imgFolder={imgFolder}
                            targetLangName={targetLangName}
                            postType={props.postType}
                            sourceLangName={sourceLangName}
                            localAiTranslatorDisabled={chromeAiBtnDisabled}
                            edgeLocalAiTranslatorDisabled={edgeAiBtnDisabled}
                            openErrorModalHandler={openErrorModalHandler}
                            serviceModalErrors={serviceModalErrors}
                            chromeAiDownload={chromeAiDownload}
                            setChromeAiDownload={setChromeAiDownload}
                            edgeAiDownload={edgeAiDownload}
                            setEdgeAiDownload={setEdgeAiDownload}
                            activeProvider={selectedProvider}
                            onSelectProvider={setSelectedProvider}
                        />
                        <SettingModalFooter
                            hasProviders={hasProviders}
                            selectedProvider={selectedProvider}
                            onStartTranslation={fetchContent}
                            setSettingVisibility={handleSettingVisibility}
                        />
                    </div>
                </div>
            }
        </>
    );
};

export default SettingModal;
