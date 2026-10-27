import { useEffect, useState, useRef, useCallback } from "@wordpress/element";
import { updateTranslateData } from "../helper/index.js";
import { select } from "@wordpress/data";
import StringPopUpHeader from "./header.js";
import StringPopUpBody from "./body.js";
import StringPopUpFooter from "./footer.js";

const popStringModal = (props) => {

    let selectedService = props.service;

    const translateData = select('block-lmatPageTranslation/translate').getTranslationInfo().translateData[selectedService] || false;
    const translateStatus=translateData?.translateStatus || false;

    const [popupVisibility, setPopupVisibility] = useState(true);
    const [refPostData, setRefPostData] = useState('');
    const [translatePending, setTranslatePending] = useState(true);
    const [hasTranslateError, setHasTranslateError] = useState(false);
    /** While Gemini recoverable error is open (or until resolved), block Update Content — cleared on Continue or terminal AI outcome. */
    const [recoverableAiErrorBlocksUpdate, setRecoverableAiErrorBlocksUpdate] = useState(false);
    const [characterCount, setCharacterCount] = useState(translateData?.targetCharacterCount || 0);
    const destroyHandlersRef = useRef([]);
    const sessionAbortRef = useRef(null);
    const [translationAbortSignal, setTranslationAbortSignal] = useState(null);
    const [translateButtonStatus, setTranslateButtonStatus] = useState(false);

    const runModalCleanup = useCallback(() => {
        sessionAbortRef.current?.abort();
        destroyHandlersRef.current.forEach((callback) => {
            if (typeof callback === 'function') {
                try {
                    callback();
                } catch (e) {
                    /* noop */
                }
            }
        });
    }, []);

    const updateDestroyHandler = useCallback((callback) => {
        destroyHandlersRef.current.push(callback);
    }, []);

    useEffect(() => {
        if (!popupVisibility) {
            runModalCleanup();
        }
    }, [popupVisibility, runModalCleanup]);
    useEffect(() => {
        const onTranslationError = (e) => {
            const d = e?.detail;
            if (d?.recoverable) {
                setRecoverableAiErrorBlocksUpdate(true);
            }
            const msg = d?.message;
            if (typeof msg === "string" && msg.trim() !== "") {
                setHasTranslateError(true);
            }
        };
        const onTranslationErrorClear = () => setHasTranslateError(false);

        const onRecoverableAiResolved = () => setRecoverableAiErrorBlocksUpdate(false);

        document.addEventListener('lmat-page-translation:translation-error', onTranslationError);
        document.addEventListener('lmat-page-translation:translation-error-clear', onTranslationErrorClear);
        document.addEventListener('lmat-page-translation:recoverable-ai-error-resolved', onRecoverableAiResolved);

        return () => {
            document.removeEventListener('lmat-page-translation:translation-error', onTranslationError);
            document.removeEventListener('lmat-page-translation:translation-error-clear', onTranslationErrorClear);
            document.removeEventListener('lmat-page-translation:recoverable-ai-error-resolved', onRecoverableAiResolved);
        };
    }, []);

    /**
     * Returns the label for the service provider.
     * @returns {string} The label for the service provider.
     */ 
    const serviceLabel = () => {
        const serviceProvider = props.service;

        if (serviceProvider === 'localAiTranslator') {
            return 'Chrome AI Translator';
        } else if (serviceProvider === 'edgeLocalAiTranslator') {
            return 'Edge AI Translator';
        } else {
            return serviceProvider.replace(/^\w/, c => c.toUpperCase()) + ' Translate';
        }
    }

    useEffect(() => {
        const abortController = new AbortController();
    
        sessionAbortRef.current = abortController;
        setTranslationAbortSignal(abortController.signal);
    
        destroyHandlersRef.current = [];
    
        if (!props.postDataFetchStatus) {
            props.fetchPostData({
                postId: props.postId,
                sourceLang: props.sourceLang,
                targetLang: props.targetLang,
                updatePostDataFetch: props.updatePostDataFetch,
                refPostData: (data) => setRefPostData((prev) => ({ ...prev, ...data })),
                updateDestroyHandler,
                signal: abortController.signal,
            });
        }
    
        return () => {
            try {
                abortController.abort();
            } catch (e) {
                /* noop */
            }
    
            destroyHandlersRef.current.forEach((callback) => {
                if (typeof callback === 'function') {
                    try {
                        callback();
                    } catch (err) {
                        /* noop */
                    }
                }
            });
        };
    }, [props.modalRender]);

    /**
     * Updates the post content data.
     * @param {string} data - The data to set as the post content.
     */
    const updatePostContentHandler = (data) => {
        setRefPostData(data);
    }

    /**
     * Updates the fetch state.
     * @param {boolean} state - The state to update the fetch with.
     */
    const setPopupVisibilityHandler = () => {
        runModalCleanup();

        if(props.service === 'google'){
            const iframe = document.querySelector('.skiptranslate iframe[id=":1.container"]');
            if (iframe) {
                const closeButton = iframe.contentDocument.querySelector('a[id=":1.close"][title="Close"] img');
                if (closeButton) {
                    closeButton.click();
                }
            }
        }

        setTranslatePending(true);
        setHasTranslateError(false);
        setRecoverableAiErrorBlocksUpdate(false);
        setPopupVisibility(false);
    }

    const translateStatusHandler = (status) => {
        let service = props.service;

        const characterCount = select('block-lmatPageTranslation/translate').getTranslationInfo().translateData[service]?.targetCharacterCount || 0;
        setCharacterCount(characterCount);
        setTranslatePending(status);
    }

    const updatePostDataHandler = () => {

        if(translateButtonStatus){
            return;
        }

        const postContent = refPostData;
        const modalClose = () => {
            setPopupVisibilityHandler();
        };
        let service=props.service;
        
        setTranslateButtonStatus(true);
        setTranslatePending(true);
        setHasTranslateError(false);
        setRecoverableAiErrorBlocksUpdate(false);

        props.translatePost({ postContent: postContent, modalClose: modalClose, service: service });
        props.pageTranslate(true);
        updateTranslateData({ provider: service, sourceLang: props.sourceLang, targetLang: props.targetLang, postId: props.currentPostId });
    }

    useEffect(() => {
        setPopupVisibility(true);
        setRecoverableAiErrorBlocksUpdate(false);

        if (translateStatus) {
            setCharacterCount(translateData?.targetCharacterCount || 0);
            setTranslatePending(false);
        }

        setTimeout(() => {
            const stringModal = document.querySelector('.lmat_page_translation_string_container');
            if (stringModal) {
                stringModal.scrollTop = 0
            };
        })
    }, [props.modalRender])

    const updateContentDisabled =
        translatePending || hasTranslateError || recoverableAiErrorBlocksUpdate;

    return (
        <> {popupVisibility &&
            <div id={`lmat-page-translation-${props.service}-strings-modal`} className="modal-container" style={{ display: popupVisibility ? 'flex' : 'none' }} data-render-id={props.modalRender}>
                <div className="modal-content">
                    <StringPopUpHeader
                        modalRender={props.modalRender}
                        setPopupVisibility={setPopupVisibilityHandler}
                        postContent={refPostData}
                        translatePendingStatus={translatePending}
                        hasTranslateError={hasTranslateError}
                        updateContentDisabled={updateContentDisabled}
                        pageTranslate={props.pageTranslate}
                        service={props.service}
                        serviceLabel={serviceLabel()}
                        updatePostData={updatePostDataHandler}
                        characterCount={characterCount}
                        translateButtonStatus={translateButtonStatus}
                    />
                    <StringPopUpBody {...props}
                        updatePostContent={updatePostContentHandler}
                        contentLoading={props.contentLoading}
                        postDataFetchStatus={props.postDataFetchStatus}
                        translatePendingStatus={translatePending}
                        service={props.service}
                        sourceLang={props.sourceLang}
                        targetLang={props.targetLang}
                        translateStatusHandler={translateStatusHandler}
                        modalRender={props.modalRender}
                        translateStatus={translateStatus}
                        stringModalBodyNotice={props.stringModalBodyNotice}
                        updateDestroyHandler={updateDestroyHandler}
                        translationAbortSignal={translationAbortSignal}
                    />
                    <StringPopUpFooter
                        modalRender={props.modalRender}
                        setPopupVisibility={setPopupVisibilityHandler}
                        postContent={refPostData}
                        translatePendingStatus={translatePending}
                        hasTranslateError={hasTranslateError}
                        updateContentDisabled={updateContentDisabled}
                        pageTranslate={props.pageTranslate}
                        service={props.service}
                        serviceLabel={serviceLabel()}
                        updatePostData={updatePostDataHandler}
                        characterCount={characterCount}
                        translateButtonStatus={translateButtonStatus}
                    />
                </div>
            </div>
        }
        </>
    );
}

export default popStringModal;