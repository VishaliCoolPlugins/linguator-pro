import React, { useEffect, useState, useRef, useCallback } from 'react';
import { bulkTranslateEntries, initBulkTranslate, translateContent } from '../bulk-translate.js';
import { useSelector, useDispatch } from 'react-redux';
import { selectTranslatePostInfo, selectProgressStatus, selectCountInfo, selectPendingPosts, selectServiceProvider, selectErrorPostsInfo, selectTargetLanguages } from '../redux-store/features/selectors.js';
import { __, sprintf } from '@wordpress/i18n';
import ErrorModalBox from '../components/error-modal-box/index.js';
import { store } from '../redux-store/store.js';
import DOMPurify from 'dompurify';
import { geminiTranslateAgain, geminiTranslateComplete } from '../gemini-bulk-recovery.js';
import { updatePendingPosts, updateTranslatePostInfo } from '../redux-store/features/actions.js';
import { installLanguagePackFromStatus } from '../components/translate-provider/local-ai/index.js';

/** Default DOMPurify removes `target` from anchors, so “open in new tab” never works. */
const PURIFY_ERROR_HTML = { ADD_ATTR: ['target', 'rel'] };

const StatusModal = ({ postIds, selectedLanguages, prefix, onDestory }) => {

    const storeDispatch = useDispatch();
    const [isLoading, setIsLoading] = useState(true);
    const [errorModal, setErrorModal] = useState(false);
    const [errorModalData, setErrorModalData] = useState(false);
    const [progressBarVisibility, setProgressBarVisibility] = useState(true);
    const [charactersCountVisibility, setCharactersCountVisibility] = useState(false);
    const [bulkStatus, setBulkStatus] = useState('status');
    const [installingPackKey, setInstallingPackKey] = useState(null);
    const [emptyPostMessage, setEmptyPostMessage] = useState(
        sprintf(
            __(
                'Translations already exist for all selected %s in the chosen languages. There are no new %s to translate.',
                'linguator-pro'
            ),
            lmatBulkTranslationGlobal.post_label,
            lmatBulkTranslationGlobal.post_label
        )
    );
    const translatePostInfo = useSelector(selectTranslatePostInfo);
    const pendingPosts=useSelector(selectPendingPosts);
    const serviceProvider = useSelector(selectServiceProvider);
    const countInfo = useSelector(selectCountInfo);
    const errorPostsInfo = useSelector(selectErrorPostsInfo);
    let progressStatus = useSelector(selectProgressStatus);
    progressStatus=progressStatus.toFixed(1);
    progressStatus=Math.min(progressStatus, 100);

    const destroyHandlersRef = useRef([]);
    const abortControllerRef = useRef(null);
    const createNonceRef = useRef('');
    const retryingRef = useRef(false);

    const runTranslationCleanup = useCallback(() => {
        abortControllerRef.current?.abort();
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

    const updateDestoryHandler = useCallback((callback) => {
        destroyHandlersRef.current.push(callback);
    }, []);

    useEffect(() => {
        abortControllerRef.current = new AbortController();
        destroyHandlersRef.current = [];
        const { signal } = abortControllerRef.current;

        const translatePosts = async () => {
            try {
                const response = await bulkTranslateEntries({ ids: postIds, langs: selectedLanguages, storeDispatch, signal });
                if (signal.aborted) {
                    return;
                }
                setIsLoading(false);

                if (!response.success && response.success === false && response.message) {
                    setEmptyPostMessage(response.message);
                    return;
                }

                createNonceRef.current = response.nonce;
                initBulkTranslate(response.postKeys, response.nonce, storeDispatch, prefix, updateDestoryHandler, signal);
            } catch (err) {
                if (signal.aborted) {
                    return;
                }
                setIsLoading(false);
                setEmptyPostMessage(
                    __('Could not load posts to translate. Check your connection and try again.', 'linguator-pro')
                );
            }
        }
        translatePosts();

        return () => {
            runTranslationCleanup();
        };
        // Intentionally once per status modal mount; cleanup aborts in-flight work when the modal closes.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- postIds/selectedLanguages are fixed for this modal session
    }, []);

    const handleInstallLanguagePack = async (info) => {
        if (!info || !info.parentPostId || !info.targetLanguage || installingPackKey) {
            return;
        }
        const key = `${info.parentPostId}_${info.targetLanguage}`;
        setInstallingPackKey(key);
        try {
            await installLanguagePackFromStatus(info.parentPostId, info.targetLanguage, storeDispatch);
        } finally {
            setInstallingPackKey(null);
        }
    };

    const handleErrorModal = (data) => {
        setErrorModalData(data);
        setErrorModal(true);
    }

    const closeErrorModal = (e) => {
        setErrorModal(false);
        setErrorModalData(false);
    }

    const AIErrorBtnHandler = (e) => {
        const btnType = e.target.dataset.status;
        const d = errorModalData;
        if (!d || !d.aiError) {
            return;
        }
        const payload = {
            postId: d.parentPostId,
            targetLang: d.targetLanguage,
            storeDispatch,
            prefix,
            updateDestoryHandler,
            nonce: d.nonce,
            closeErrorModal,
            completedStrings: d.completedStrings,
            totalPosts: d.totalPosts,
        };
        if (btnType === 'translateAgain') {
            geminiTranslateAgain(payload);
        } else if (btnType === 'continue') {
            geminiTranslateComplete(payload);
        }
    };

    const onModalClose= (e) => {
        runTranslationCleanup();
        onDestory(e);
    }

    useEffect(() => {

        if(countInfo.totalPosts < 1 && !isLoading && bulkStatus !== 'status'){
            updateBulkStatus('status');
            return;
        }

        if(translatePostInfo && Object.keys(translatePostInfo).length > 0){
            if(pendingPosts.length < 1){
                updateBulkStatus('completed');
                return;
            }

            let error=false;
            let running=false;

            const runLoop=(items, index)=>{
                const status=translatePostInfo[items[index]].status;

                if(status === 'running' || status === 'in-progress' || status === 'pending' || status === 'needs-pack'){
                    running=true;
                    bulkStatus !== 'running' && updateBulkStatus('running');
                    return;
                }

                if(status === 'error'){
                    error=true;
                }

                index++;
                if(index < items.length){
                    runLoop(items, index);                    
                }
            }

            runLoop(Object.keys(translatePostInfo), 0);
            
            if(running) return;

            updateBulkStatus(error ? 'error' : 'pending');
        }
    }, [translatePostInfo]);

    const updateBulkStatus=(status)=>{
        setBulkStatus(status);
    }

    const retryFailedTranslation = async () => {
        const failed = errorModalData;
        if (retryingRef.current || !failed || !failed.allowTranslate || !failed.parentPostId || !failed.targetLanguage) {
            return;
        }

        const postId = failed.parentPostId;
        const targetLang = failed.targetLanguage;
        const postContent = store.getState().parentPostsInfo[postId];
        if (!postContent) {
            return;
        }

        const { originalContent, editorType, sourceLanguage } = postContent;
        const source = JSON.parse(JSON.stringify(originalContent || {}));
        source.content = source.content || {};
        source.metaFields = source.metaFields || {};

        const key = `${postId}_${targetLang}`;
        retryingRef.current = true;
        storeDispatch(updatePendingPosts([key]));
        storeDispatch(updateTranslatePostInfo({
            [key]: {
                status: 'running',
                messageClass: '',
                errorMessage: '',
                errorHtml: false,
                allowTranslate: false,
            },
        }));
        setProgressBarVisibility(true);
        setCharactersCountVisibility(false);
        updateBulkStatus('running');
        closeErrorModal();

        try {
            await translateContent({
                sourceLang: sourceLanguage,
                targetLangs: [targetLang],
                totalPosts: Math.max(1, store.getState().countInfo.totalPosts || 1),
                storeDispatch,
                postId,
                prefix,
                source,
                editorType,
                createTranslatePostNonce: createNonceRef.current,
                updateDestoryHandler,
                signal: abortControllerRef.current?.signal,
            });
        } finally {
            retryingRef.current = false;
        }
    };

    const getBulkStatus=()=>{
        switch(bulkStatus){
            case 'running':
                return __('In Progress', 'linguator-pro');
            case 'pending':
                return __('Pending', 'linguator-pro');
            case 'error':
                return __('Includes errors', 'linguator-pro');
            case 'completed':
                return __('Completed', 'linguator-pro');
            default:
                return __('Status', 'linguator-pro');
        }
    }

    useEffect(() => {
        if (isLoading || pendingPosts.length > 0) {
            return;
        }

        if (countInfo.postsTranslated < 1) {
            setProgressBarVisibility(false);
            setCharactersCountVisibility(false);
            return;
        }

        let charsTimer;
        if (countInfo.stringsTranslated > 0) {
            charsTimer = setTimeout(() => {
                setCharactersCountVisibility(true);
            }, 1000);
        }

        // When pending clears before Redux progress hits 100%, still reveal the footer CTA.
        const hideDelay = progressStatus >= 100 ? 7500 : 500;
        const hideTimer = setTimeout(() => {
            setProgressBarVisibility(false);
            setCharactersCountVisibility(false);
        }, hideDelay);

        return () => {
            clearTimeout(charsTimer);
            clearTimeout(hideTimer);
        };
    }, [pendingPosts, progressStatus, countInfo.postsTranslated, countInfo.stringsTranslated, isLoading]);

    const getTranslatedPostLink = () => {
        const translatedLanguagesArr=Object.values(translatePostInfo).filter(post=>post.status==='completed' && post.targetLanguage);
        const translatedLangs=translatedLanguagesArr.map(post=>post.targetLanguage).filter((lang, index, self) => self.indexOf(lang) === index);
      
        if(translatedLangs.length === 1){
            const translatedLang=translatedLangs[0];
            // Get current query params
            const url = new URL(window.location.href);
            const params = new URLSearchParams(url.search);

            // Set or update the required params
            params.set('lang', translatedLang);
            params.set('orderby', 'date');
            params.set('order', 'desc');

            const newQuery = Object.fromEntries(params.entries());

            return window.location.href.split('?')[0]+'?'+new URLSearchParams(newQuery).toString();
        }else{
            return window.location.href;
        }

    }
    
    const getServiceProviderLabel = () => {
        switch(serviceProvider){
            case 'google':
                return 'Google Translate';
            case 'localAiTranslator':
                return 'Chrome AI Translator';
            case 'edgeLocalAiTranslator':
                return 'Edge AI Translator';
            case 'gemini':
                return 'Google Gemini';
            default:
                return 'Google Translate';
        }
    }

    const allPostStatus = (postId) => {
        const targetLangsArr=selectTargetLanguages(store.getState(), postId);
        let allPostStatus=true;
        
        if(!targetLangsArr || !targetLangsArr.length){
            return true;
        }

        for(let i=0; i<targetLangsArr.length; i++){
            if(!translatePostInfo[postId + '_' + targetLangsArr[i]] || ['pending', 'in-progress', 'running', 'needs-pack'].includes(translatePostInfo[postId + '_' + targetLangsArr[i]].status)){
                allPostStatus=false;
                break;
            }
        }
        
        return allPostStatus;
    }

    return (
        errorModal && errorModalData ? <ErrorModalBox message={errorModalData.errorHtml || errorModalData.errorMessage || __('An unexpected error occurred.', 'linguator-pro')} onClose={closeErrorModal} Title={__('Bulk Translation Error', 'linguator-pro')} prefix={prefix} beforeClose={errorModalData.allowTranslate ? <button type="button" className={`${prefix}-ai-error-button button`} data-status="translateAgain" onClick={retryFailedTranslation}>{__('Translate', 'linguator-pro')}</button> : null} >
            {errorModalData.aiError && serviceProvider === 'gemini' && (
                <div className={`${prefix}-ai-error-buttons`}>
                    <button type="button" className={`${prefix}-ai-error-button button`} data-status="translateAgain" onClick={AIErrorBtnHandler}>{__('Translate', 'linguator-pro')}</button>
                    <button type="button" className={`${prefix}-ai-error-button button`} data-status="continue" onClick={AIErrorBtnHandler}>{__('Continue', 'linguator-pro')}</button>
                </div>
            )}
        </ErrorModalBox> :
        <div id={`${prefix}-status-modal-container`}>
            <div className={`${prefix}-header`}>
                <div className={`${prefix}-modal-header-inner`}>
                    <span className={`${prefix}-step-label`}>
                        {__('STEP 2 OF 2', 'linguator-pro')}
                    </span>
                    <h2 className={`${prefix}-bulk-status-heading ${bulkStatus}`}>
                        {sprintf(__('AI Translation %s', 'linguator-pro'), getBulkStatus())}
                        {bulkStatus === 'running' && <span className={`${prefix}-bulk-status-running`}></span>}
                    </h2>
                    {bulkStatus === 'running' && (
                        <p className={`${prefix}-modal-desc`}>
                            {__('Please keep this window open while your content is being translated.', 'linguator-pro')}
                        </p>
                    )}
                    {bulkStatus === 'completed' &&
                        countInfo.errorPosts < 1 &&
                        !(translatePostInfo && Object.values(translatePostInfo).some((info) => info?.status === 'error')) &&
                        countInfo.stringsTranslated > 0 && (
                            <p className={`${prefix}-modal-desc`}>{__('Your content has been translated successfully.', 'linguator-pro')}</p>
                        )}
                </div>
                <button type="button" aria-label={__('Close', 'linguator-pro')} className={`${prefix}-modal-close ${prefix}-status-modal-close`} onClick={onModalClose}>&times;</button>
            </div>
            {(countInfo.totalPosts < 1 && countInfo.errorPosts < 1) && !isLoading ?
                    <p>{emptyPostMessage}</p> :
                    <>
                        {isLoading && <div className={`${prefix}-progress-skeleton`}></div>}
                        {(countInfo.totalPosts >= 1) && progressBarVisibility && !isLoading ?
                        <>
                            <div className={`${prefix}-overall-progress`}>
                                <div className={`${prefix}-progress-bar`}>
                                    <div className={`${prefix}-progress`} style={{width: progressStatus + '%'}}>{progressStatus + '%'}</div>
                                </div>
                            </div>
                            {charactersCountVisibility &&
                                <div className={`${prefix}-translator-strings-count`}>
                                    {__('Wahooo! You have saved your valuable time via auto translating', 'linguator-pro')}
                                    <strong className="totalChars"> {countInfo.charactersTranslated} </strong>{__('characters using', 'linguator-pro')}
                                <strong> {getServiceProviderLabel()}</strong>
                                </div>
                            }
                        </> : (countInfo.postsTranslated > 0 &&
                            <div className={`${prefix}-count-container`}>
                                <div className={`${prefix}-post-count`}>
                                    <span className={`${prefix}-count-text-heading`}>{__('Posts', 'linguator-pro')} </span><br />
                                    <span className={`${prefix}-post-translated-post`}>{countInfo.postsTranslated}/{countInfo.totalPosts}</span>
                                </div>
                                <div className={`${prefix}-string-count`}>
                                    <span className={`${prefix}-count-text-heading`}>{__('Strings', 'linguator-pro')} </span><br />
                                    <span className={`${prefix}-string-number`}>{countInfo.stringsTranslated}</span>
                                </div>
                                <div className={`${prefix}-char-count`}>
                                    <span className={`${prefix}-count-text-heading`}>{__('Characters', 'linguator-pro')} </span><br />
                                    <span className={`${prefix}-char-number`}>{countInfo.charactersTranslated}</span>
                                </div>
                            </div>
                        )
                        }

                        <div className={`${prefix}-status-table-container`}>
                            <div className={`${prefix}-status-inner`}>
                                {isLoading &&
                                    <>
                                        <div className={`${prefix}-status-header-container`}>
                                            <div className={`${prefix}-status-flag-th`}>
                                                <div className={`${prefix}-progress-skeleton`} style={{ maxWidth: '80px', marginBottom: '0px' }}></div>
                                            </div>
                                            <div className={`${prefix}-status-status-th`}>
                                                <div className={`${prefix}-progress-skeleton`} style={{ maxWidth: '80px', marginBottom: '0px' }}></div>
                                            </div>
                                            <div className={`${prefix}-status-title-th`}>
                                                <div className={`${prefix}-progress-skeleton`} style={{ maxWidth: '80px', marginBottom: '0px' }}></div>
                                            </div>
                                            <div className={`${prefix}-status-actions-th`}>
                                                <div className={`${prefix}-progress-skeleton`} style={{ maxWidth: '80px', marginBottom: '0px' }}></div>
                                            </div>
                                        </div>
                                        {[0, 1].map((row) => (
                                            <div className={`${prefix}-status-inner-item`} key={row}>
                                                <div className={`${prefix}-status-target-post`}>
                                                    <div className={`${prefix}-status-target-post-flag`}>
                                                        <div className={`${prefix}-progress-skeleton`} style={{ width: '80px', marginBottom: '0px' }}></div>
                                                    </div>
                                                    <div className={`${prefix}-status-target-post-status`}>
                                                        <div className={`${prefix}-progress-skeleton`} style={{ width: '80px', marginBottom: '0px' }}></div>
                                                    </div>
                                                    <div className={`${prefix}-status-target-post-title`} style={{ gridColumn: 'span 2' }}>
                                                        <div className={`${prefix}-progress-skeleton`} style={{ width: '80px', marginInline: 'auto', marginBottom: '0px' }}></div>
                                                    </div>
                                                    <div className={`${prefix}-status-target-post-actions`}>
                                                        <div className={`${prefix}-progress-skeleton`} style={{ width: '80px', marginBottom: '0px' }}></div>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </>
                                }
                                {
                                    !isLoading && Object.keys(translatePostInfo).length > 0 &&
                                    <div className={`${prefix}-status-header-container`}>
                                        <div className={`${prefix}-status-flag-th`}>
                                            <span className={`${prefix}-status-header-label`}>{__('Language', 'linguator-pro')}</span>
                                        </div>
                                        <div className={`${prefix}-status-status-th`}>
                                            <span className={`${prefix}-status-header-label`}>{__('Status', 'linguator-pro')}</span>
                                        </div>
                                        <div className={`${prefix}-status-title-th`}>
                                            <span className={`${prefix}-status-header-label`}>{__('Title', 'linguator-pro')}</span>
                                        </div>
                                        <div className={`${prefix}-status-actions-th`}>
                                            <span className={`${prefix}-status-header-label`}>{__('Actions', 'linguator-pro')}</span>
                                        </div>
                                    </div>
                                }
                                {!isLoading && Object.keys(errorPostsInfo).length > 0 &&
                                    Object.keys(errorPostsInfo).map((key) => {
                                        return (
                                            <div className={`${prefix}-status-inner-item`} key={key}>
                                                <div className={`${prefix}-group-title`}>
                                                    {errorPostsInfo[key]?.title || __('Untitled', 'linguator-pro')}
                                                </div>
                                                <div className={`${prefix}-status-inner-item ${prefix}-error-message`}>
                                                    <div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(errorPostsInfo[key].errorMessage, PURIFY_ERROR_HTML) }}></div>
                                                </div>
                                            </div>
                                        );
                                    })
                                }

                                {!isLoading && Object.keys(translatePostInfo).map((key) => {
                                    const info = translatePostInfo[key];
                                    const workingStatus = info.status === 'running' || info.status === 'in-progress' ? true : false;
                                    const needsPack = info.status === 'needs-pack' || info.needsLanguagePack;
                                    const packRowKey = `${info.parentPostId}_${info.targetLanguage}`;
                                    const isInstallingPack = installingPackKey === packRowKey;

                                    return (
                                        <div className={`${prefix}-status-inner-item ${prefix}-td-${info.status}`} key={`group-title-${info.parentPostId || key}-${key}`}>
                                            {info.firstPostLanguage &&
                                                <div className={`${prefix}-status-parent-post-title`}>{info.parentPostTitle || __('Untitled', 'linguator-pro')}</div>
                                            }
                                            <div className={`${prefix}-status-target-post`}>
                                                <div className={`${prefix}-status-target-post-flag`}>
                                                    {info.flagUrl && <img src={info.flagUrl} width="20" alt={info.targetLanguage} />}
                                                    {info.languageName || info.targetLanguage}
                                                </div>
                                                {info.status === 'error' ?
                                                    <>
                                                        {info.errorHtml ?
                                                            <div className={`${prefix}-status-target-post-error-button`} style={{ gridColumn: 'span 4' }}>
                                                                <button
                                                                    type="button"
                                                                    className={`${prefix}-status-error-button`}
                                                                    onClick={() => { handleErrorModal(info); }}
                                                                >
                                                                    {__('Error Details', 'linguator-pro')}
                                                                </button>
                                                            </div> :
                                                            <div className={`${prefix}-status-target-post-error ${prefix}-error-message`} style={{ gridColumn: 'span 4' }}>
                                                                {info.errorAllowHtml ? (
                                                                    <span dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(info.errorMessage, PURIFY_ERROR_HTML) }} />
                                                                ) : (
                                                                    info.errorMessage
                                                                )}
                                                            </div>
                                                        }
                                                    </> :
                                                    needsPack ?
                                                    <div className={`${prefix}-status-pack-actions`} style={{ gridColumn: 'span 4' }}>
                                                        <button
                                                            type="button"
                                                            className={`${prefix}-status-install-pack-button${isInstallingPack ? ` ${prefix}-status-install-pack-button-loading` : ''}`}
                                                            disabled={!!installingPackKey}
                                                            onClick={() => { handleInstallLanguagePack(info); }}
                                                        >
                                                            {isInstallingPack
                                                                ? __('Loading…', 'linguator-pro')
                                                                : __('Install language pack', 'linguator-pro')}
                                                        </button>
                                                    </div> :
                                                    <>
                                                        <div className={`${prefix}-status-target-post-status`}>
                                                            <span className={`${prefix}-status ${info.messageClass} ${info.status}`}>
                                                                {info.status === 'pending' && __('Pending', 'linguator-pro')}
                                                                {info.status === 'completed' && __('Completed', 'linguator-pro')}
                                                                {workingStatus && info.errorMessage && (
                                                                    <span className={`${prefix}-language-loading`}>{info.errorMessage}</span>
                                                                )}
                                                            </span>
                                                            {workingStatus && !info.errorMessage && <div className={`${prefix}-progress-bar-circular`} data-id={info.parentPostId + '_' + info.targetLanguage}>
                                                                <svg className={`${prefix}-circle`} viewBox="0 0 36 36">
                                                                    <path className={`${prefix}-bg`} d="M18 2.0845
                                                                    a 15.9155 15.9155 0 0 1 0 31.831
                                                                    a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                                    <path className={`${prefix}-progress`}
                                                                    strokeDasharray="0, 100"
                                                                    d="M18 2.0845
                                                                    a 15.9155 15.9155 0 0 1 0 31.831
                                                                    a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                                </svg>
                                                                <div className={`${prefix}-percentage`}>0%</div>
                                                            </div>}
                                                        </div>
                                                        <div className={`${prefix}-status-target-post-title`} style={{ gridColumn: 'span 2' }}>
                                                            {info.status === 'completed' ?
                                                                <a href={info.postLink} target="_blank" rel="noopener noreferrer">{info.targetPostTitle}</a> :
                                                                (info.status === 'in-progress' ?
                                                                    <div className={`${prefix}-${info.messageClass}-text`}>{__('In Progress', 'linguator-pro')}<span></span></div> :
                                                                    <div className={`${prefix}-progress-skeleton short`} style={{ marginInline: 'auto' }}></div>)
                                                            }
                                                        </div>
                                                        <div className={`${prefix}-status-target-post-actions`}>
                                                            {info.status === 'completed' && info.targetPostId ?
                                                                <span className={`${prefix}-view-link`}>
                                                                    {allPostStatus(info.parentPostId) ? (
                                                                        <a
                                                                            href={info.elementorEditLink || info.postEditLink}
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            className={`${prefix}-review-btn button button-primary`}
                                                                            title={sprintf(__('Open the translated %s for review', 'linguator-pro'), lmatBulkTranslationGlobal.post_label_singular || lmatBulkTranslationGlobal.post_label)}
                                                                        >
                                                                            {__('Review', 'linguator-pro')}
                                                                        </a>
                                                                    ) : (
                                                                        <button
                                                                            className={`${prefix}-review-btn button disabled`}
                                                                            disabled
                                                                            title={sprintf(__('Please wait until all translations for this %s are complete before reviewing.', 'linguator-pro'), lmatBulkTranslationGlobal.post_label_singular || lmatBulkTranslationGlobal.post_label)}
                                                                        >
                                                                            {__('Review', 'linguator-pro')}
                                                                        </button>
                                                                    )}
                                                                </span>
                                                                :
                                                                (info.status === 'in-progress' ?
                                                                    <div className={`${prefix}-${info.messageClass}-text`}>{__('In Progress', 'linguator-pro')}<span></span></div> :
                                                                    <div className={`${prefix}-progress-skeleton short`}></div>)
                                                            }
                                                        </div>
                                                    </>}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                        {(countInfo.postsTranslated > 0 && !pendingPosts.length && !progressBarVisibility) &&
                            <div className={`${prefix}-progress-footer`}>
                                <a className={`${prefix}-progress-button button button-primary`} href={getTranslatedPostLink()}>{sprintf(__('Check Translated %s', 'linguator-pro'), lmatBulkTranslationGlobal.post_label)}</a>
                            </div>
                        }
                    </>
            }
        </div>
    );
};

export default StatusModal;
