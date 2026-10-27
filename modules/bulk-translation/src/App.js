import React, { useState, useEffect } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import StatusModal from './status-modal/index.js';
import { useDispatch, useSelector } from 'react-redux';
import { resetStore, updateServiceProvider } from './redux-store/features/actions.js';
import { selectCountInfo } from './redux-store/features/selectors.js';
import ChromeAiTranslator from './components/translate-provider/local-ai/local-ai-translate.js';
import SettingModal from './setting-modal/index.js';
import TranslateService from './components/translate-provider/index.js';
import Notice from './components/notice/index.js';

const App = ({ onDestory, prefix, postIds }) => {
    const dispatch = useDispatch();
    const validProviders = Object.keys(TranslateService());
    const hasValidProviders = validProviders.length > 0;
    const { languageObject = {} } = lmatBulkTranslationGlobal || {};
    const targetLanguages = JSON.parse(JSON.stringify(languageObject));
    if (lmatBulkTranslationGlobal.default_language_slug) {
        delete targetLanguages[lmatBulkTranslationGlobal.default_language_slug];
    }
    const postLabelSingular = lmatBulkTranslationGlobal.post_label_singular || lmatBulkTranslationGlobal.post_label;
    const emptyPostIdsErrorMessage = sprintf(__('Please select at least one %s for translation.', 'translate-words'), postLabelSingular);
    const hasPosts = postIds.length > 0;
    const [selectedLanguages, setSelectedLanguages] = useState(Object.keys(targetLanguages));
    const [statusModalVisibility, setStatusModalVisibility] = useState(false);
    const translatePostsCount = useSelector(selectCountInfo).totalPosts;
    const [isLoading, setIsLoading] = useState(true);
    const [localAiModalError, setLocalAiModalError] = useState(false);
    const [edgeLocalAiModalError, setEdgeLocalAiModalError] = useState(false);

    const destroyApp = (e) => {
        setStatusModalVisibility(false);
        if (e && typeof e.preventDefault === 'function') {
            onDestory(e);
            return;
        }
        onDestory({ preventDefault: () => {} });
    }


    useEffect(() => {
        const checkStatus = async () => {
            const chromeStatus = await ChromeAiTranslator.languageSupportedStatus('en', 'hi', 'English', 'Hindi', false);
            if (chromeStatus && (chromeStatus.type === 'browser-not-supported' || chromeStatus.type === 'translation-api-not-available')) {
                setLocalAiModalError(chromeStatus.html[0].outerHTML);
            }

            const edgeStatus = await ChromeAiTranslator.languageSupportedStatus('en', 'hi', 'English', 'Hindi', true);
            if (edgeStatus && (edgeStatus.type === 'browser-not-supported' || edgeStatus.type === 'translation-api-not-available')) {
                setEdgeLocalAiModalError(edgeStatus.html[0].outerHTML);
            }

            setIsLoading(false);
        }

        checkStatus();
    }, [statusModalVisibility]);

    useEffect(() => {
        if (!statusModalVisibility) {
            dispatch(resetStore());
        }
    }, [statusModalVisibility, dispatch]);

    const handleLanguageChange = (e) => {
        const { value } = e.target;
        const checked = e.target.checked;
        if (checked) {
            setSelectedLanguages([...selectedLanguages, value]);
        } else {
            setSelectedLanguages(selectedLanguages.filter(language => language !== value));
        }
    }

    const handleSelectAllLanguages = (e) => {
        const checked = e.target.checked;
        if (checked) {
            setSelectedLanguages(Object.keys(targetLanguages));
        } else {
            setSelectedLanguages([]);
        }
    }

    const updateProviderHandler = (services) => {
        if (!hasPosts || selectedLanguages.length === 0 || !services) {
            return;
        }

        dispatch(updateServiceProvider(services));
        setStatusModalVisibility(true);
        setIsLoading(true);
    }

    const activeScreen = () => {
        if (statusModalVisibility) {
            return 'status';
        }

        // No compatible provider: show the message immediately, skip language selection.
        if (!hasValidProviders) {
            return 'no-provider';
        }

        if (!hasPosts) {
            return 'error';
        }

        return 'setup';
    }

    const containerCls = (screen) => {
        const cls = [];

        if ('status' === screen) {
            cls.push(`${prefix}-status-modal-active`);

            if (!translatePostsCount) {
                cls.push(`${prefix}-empty-posts`);
            }
        } else if ('setup' === screen) {
            cls.push(`${prefix}-setting-modal-active`);
            cls.push(`${prefix}-setup-modal-active`);
        } else {
            cls.push(`${prefix}-setting-modal-active`);
            cls.push(`${prefix}-empty-modal-active`);
        }

        return cls.join(' ');
    }

    const SelectLanguageNotice = () => {

        const notices = [];
      
        const postMetaSync = lmatBulkTranslationGlobal.postMetaSync === 'true' && lmatBulkTranslationGlobal.taxonomy_page !== 'taxonomy';

          if (postMetaSync) {
            notices.push({
              className: `${prefix}-notice ${prefix}-notice-error`, message: <p>
                {__('For accurate custom field translations, please disable the Custom Fields synchronization in ', 'translate-words')}
                <a
                  href={`${lmatBulkTranslationGlobal.admin_url}admin.php?page=lmat_settings`}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {__('Linguator settings', 'translate-words')}
                </a>
                {sprintf(__('. This may affect linked %s.', 'translate-words'), lmatBulkTranslationGlobal.post_label)}
              </p>
            });
          }
      
        const noticeLength = notices.length;
      
        if (notices.length > 0) {
          return notices.map((notice, index) => <Notice className={notice.className} key={index} lastNotice={index === noticeLength - 1}>{notice.message}</Notice>);
        }
      
        return;
      }

    const EmptyScreen = ({ heading, description, message, artTitle }) => (
        <div id={`${prefix}-setting-modal-container`}>
            <div className={`${prefix}-setting-modal-content`}>
                <div className={`${prefix}-header`}>
                    <div className={`${prefix}-modal-header-inner`}>
                        <h2>{heading}</h2>
                        <p className={`${prefix}-modal-desc`}>{description}</p>
                    </div>
                    <button
                        type="button"
                        className={`${prefix}-modal-close`}
                        onClick={destroyApp}
                        title={__('Close', 'translate-words')}
                        aria-label={__('Close', 'translate-words')}
                    >
                        &times;
                    </button>
                </div>

                <div className={`${prefix}-setting-modal-body`}>
                    <div className={`${prefix}-empty-state`}>
                        <span className={`${prefix}-empty-state-art`} aria-hidden="true">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="14" y="10" width="34" height="42" rx="4" stroke="currentColor" strokeWidth="2.5" />
                                <rect x="20" y="16" width="34" height="42" rx="4" fill="#fff" stroke="currentColor" strokeWidth="2.5" />
                                <path d="M27 27h20M27 35h20M27 43h12" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
                                <circle cx="55" cy="52" r="11" fill="#fff" stroke="currentColor" strokeWidth="2.5" />
                                <path d="M44 52h22M55 41c3 3.5 3 18 0 22-3-4-3-18.5 0-22z" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
                                <path d="M12 62L64 8" stroke="#d63638" strokeWidth="3" strokeLinecap="round" strokeDasharray="5 6" />
                            </svg>
                        </span>
                        <h3>{artTitle}</h3>
                        <p>{message}</p>
                    </div>
                </div>
            </div>
        </div>
    );

    const screen = activeScreen();
    const settingsUrl = (lmatBulkTranslationGlobal.admin_url || '') + 'admin.php?page=lmat_settings&tab=translation';

    return <div
        id={`${prefix}-container`}
        className={containerCls(screen)}>
        {'no-provider' === screen && (
            <EmptyScreen
                heading={__('AI Translation', 'translate-words')}
                description={__('Nothing is ready to translate yet.', 'translate-words')}
                artTitle={__('No Translation Provider', 'translate-words')}
                message={<>
                    {__('You have not enabled any translation provider. Please enable at least one service provider to use bulk translation. Go to the', 'translate-words')}{' '}
                    <a href={settingsUrl} target="_blank" rel="noopener noreferrer">
                        {__('Translation Settings', 'translate-words')}
                    </a>
                    {' '}{__('to configure a translation provider.', 'translate-words')}
                </>}
            />
        )}

        {'error' === screen && (
            <EmptyScreen
                heading={__('AI Translation', 'translate-words')}
                description={__('Nothing is ready to translate yet.', 'translate-words')}
                artTitle={__('No Items Selected', 'translate-words')}
                message={emptyPostIdsErrorMessage}
            />
        )}

        {'status' === screen && (isLoading ?
            <div
                className={`${prefix}-skeleton-loader`}></div> :
            <StatusModal
                postIds={postIds}
                selectedLanguages={selectedLanguages}
                prefix={prefix}
                onDestory={destroyApp}
            />)}

        {'setup' === screen &&
            <SettingModal
                prefix={prefix}
                onDestory={destroyApp}
                updateProviderHandler={updateProviderHandler}
                localAiModalError={localAiModalError}
                edgeLocalAiModalError={edgeLocalAiModalError}
                targetLanguages={targetLanguages}
                selectedLanguages={selectedLanguages}
                onLanguageChange={handleLanguageChange}
                onSelectAllLanguages={handleSelectAllLanguages}
                languageNotice={SelectLanguageNotice()}
                languagesDisabled={!hasPosts}
                languagesDisabledReason={emptyPostIdsErrorMessage}
            />
        }
    </div>
}

export default App;
