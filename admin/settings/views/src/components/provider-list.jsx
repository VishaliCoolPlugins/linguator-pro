import React, { useEffect, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ChromeIcon } from '../../../../../assets/js/src/icons/chrome';
import { EdgeIcon } from '../../../../../assets/js/src/icons/edge';
import { GoogleIcon } from '../../../../../assets/js/src/icons/google';
import { GeminiIcon } from '../../../../../assets/js/src/icons/gemini';

const DOCS_UTM = '?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=docs&utm_content=settings_provider';

const getChromeAiSetupData = () => window?.lmat_settings?.chrome_ai_setup || {};

const getAvailableLanguages = () => Object.entries(getChromeAiSetupData().all_languages || {}).map(([code, language]) => ({
    code,
    name: language?.name || code,
}));

/**
 * Whether the browser can run this built-in AI provider and, if so, whether it is set up.
 *
 * @param {'chrome'|'edge'} type        Built-in AI provider.
 * @param {string}          browserType Chrome | Edge | Other.
 * @return {'unsupported'|'checking'|'ready'|'setup'}
 */
const useBuiltInAiStatus = (type, browserType) => {
    const requiredBrowser = 'edge' === type ? 'Edge' : 'Chrome';
    const unsupported = requiredBrowser !== browserType;
    const [status, setStatus] = useState(unsupported ? 'unsupported' : 'checking');

    useEffect(() => {
        if (unsupported) {
            setStatus('unsupported');
            return undefined;
        }

        let cancelled = false;
        const Framework = window.ChromeAISetupFramework;

        if (!Framework) {
            setStatus('setup');
            return undefined;
        }

        setStatus('checking');
        Framework.checkSystemAvailability({
            sourceLanguage: getChromeAiSetupData().source_language || 'en',
            availableLanguages: getAvailableLanguages(),
            // A phone-sized device toolbar still runs desktop Chrome, so follow the real API check.
            bypassDesktopCheck: true,
        })
            .then((result) => {
                if (!cancelled) {
                    setStatus(result.hasError ? 'setup' : 'ready');
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setStatus('setup');
                }
            });

        return () => {
            cancelled = true;
        };
    }, [unsupported]);

    return status;
};

/**
 * Chrome / Edge AI setup card (detects what the browser needs and offers a live preview).
 */
export const ChromeAiSetupCard = ({ browserType, chromeEnabled, edgeEnabled }) => {
    const containerRef = useRef(null);
    let forceProvider = 'chrome';

    if ('Edge' === browserType) {
        forceProvider = 'edge';
    } else if ('Other' === browserType && !chromeEnabled && edgeEnabled) {
        forceProvider = 'edge';
    }

    useEffect(() => {
        const Framework = window.ChromeAISetupFramework;
        const container = containerRef.current;

        if (!Framework || !container) {
            return undefined;
        }

        const data = getChromeAiSetupData();

        new Framework(container, {
            sourceLanguage: data.source_language || 'en',
            sourceLanguageLabel: data.source_language_label || 'English',
            availableLanguages: getAvailableLanguages(),
            chromeIconUrl: data.chrome_icon_url || '',
            edgeIconUrl: data.edge_icon_url || '',
            texts: data.texts || {},
            alternativeUrl: data.alternative_url || '',
            defaultProvider: forceProvider,
            forceProvider,
            primaryBtnClass: 'lmat-engine-btn primary',
            secondaryBtnClass: 'lmat-engine-btn',
        });

        return () => {
            container.innerHTML = '';
        };
    }, [forceProvider]);

    return <div className="lmat-chrome-ai-setup" ref={containerRef} />;
};

const ProviderRow = ({
    icon,
    name,
    docsUrl,
    enabled,
    onToggle,
    isDefault,
    onDefault,
    status,
    statusLabel,
    unsupportedMessage,
    actionLabel,
    onAction,
    extra,
    defaultDisabledReason,
}) => {
    const unsupported = 'unsupported' === status;
    const defaultDisabled = !unsupported && Boolean(defaultDisabledReason);
    const rowClasses = ['lmat-engine-row'];

    if (isDefault) {
        rowClasses.push('is-default');
    }

    if (unsupported) {
        rowClasses.push('is-unsupported');
    }

    const statusClass = {
        setup: 'is-setup',
        'missing-key': 'is-setup',
        checking: 'is-checking',
        unsupported: 'is-unsupported',
    }[status] || '';

    return (
        <li className={rowClasses.join(' ')}>
            <div className="lmat-engine-meta">
                <span className="lmat-engine-logo" aria-hidden="true">{icon}</span>
                <span className="lmat-engine-name">{name}</span>
                <span className={`lmat-engine-status ${statusClass}`.trim()}>{statusLabel}</span>
            </div>

            {unsupported && unsupportedMessage && (
                <p className="lmat-engine-unsupported-msg">{unsupportedMessage}</p>
            )}

            <label
                className={`lmat-engine-default${defaultDisabled ? ' is-disabled' : ''}`}
                title={defaultDisabled ? defaultDisabledReason : ''}
            >
                <input
                    type="radio"
                    name="lmat-default-provider"
                    className="lmat-engine-default-input"
                    checked={isDefault}
                    disabled={defaultDisabled}
                    onChange={onDefault}
                />
                <span className="lmat-engine-default-mark" aria-hidden="true"></span>
                <span className="lmat-engine-default-text">{__('Set as default', 'linguator-pro')}</span>
                <span className="lmat-engine-default-active">{__('Default provider', 'linguator-pro')}</span>
            </label>

            <div className="lmat-engine-actions">
                <a className="lmat-engine-docs" href={docsUrl} target="_blank" rel="noopener noreferrer">
                    {__('Docs', 'linguator-pro')}
                </a>
                <span className="lmat-engine-action-slot">
                    {actionLabel && (
                        <button type="button" className="lmat-engine-btn primary" onClick={onAction}>
                            {actionLabel}
                        </button>
                    )}
                </span>
                <label className="lmat-provider-switch">
                    <input
                        type="checkbox"
                        className="lmat-input-toggle"
                        checked={enabled}
                        disabled={isDefault}
                        onChange={onToggle}
                        title={isDefault ? __('The default provider stays enabled. Pick another default to turn this off.', 'linguator-pro') : ''}
                    />
                    <span className="lmat-switch-slider"></span>
                    <span className="screen-reader-text">{sprintf(__('Enable %s', 'linguator-pro'), name)}</span>
                </label>
            </div>

            {extra && <div className="lmat-engine-extra">{extra}</div>}
        </li>
    );
};

/**
 * Provider list of the AI Translation tab.
 *
 * @param {Object} props
 * @param {string} props.browserType       Chrome | Edge | Other.
 * @param {string} props.defaultProvider   Saved key of the default provider ('' for none).
 * @param {Object} props.providers         Per provider key: { enabled, setEnabled }.
 * @param {boolean} props.geminiAvailable  Whether the WordPress AI client allows Gemini.
 * @param {boolean} props.geminiConfigured Whether a Gemini API key is saved.
 * @param {Function} props.onDefaultChange Receives the provider key picked as default.
 * @param {*} props.geminiExtra            Content rendered under the Gemini row (API key form).
 */
const ProviderList = ({
    browserType,
    defaultProvider,
    providers,
    geminiAvailable,
    geminiConfigured,
    onDefaultChange,
    geminiExtra,
}) => {
    const chromeStatus = useBuiltInAiStatus('chrome', browserType);
    const edgeStatus = useBuiltInAiStatus('edge', browserType);
    const showChromeRow = 'Edge' !== browserType;
    const showEdgeRow = 'Chrome' !== browserType;
    const chromeEnabled = Boolean(providers.chrome_local_ai.enabled);
    const edgeEnabled = Boolean(providers.edge_local_ai.enabled);
    const showSetupCard = (showChromeRow && 'Chrome' === browserType && chromeEnabled)
        || (showEdgeRow && 'Edge' === browserType && edgeEnabled)
        || ('Other' === browserType && (chromeEnabled || edgeEnabled));

    const scrollToSetupCard = () => {
        const card = document.querySelector('.lmat-chrome-ai-setup');

        if (card && card.scrollIntoView) {
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    };

    const builtInLabels = {
        ready: __('Ready', 'linguator-pro'),
        setup: __('Not Configured', 'linguator-pro'),
        checking: __('Checking...', 'linguator-pro'),
        unsupported: __('Not supported', 'linguator-pro'),
    };

    const unsupportedMessage = (providerName, browserName) => sprintf(
        /* translators: 1: translation provider name, 2: required browser name */
        __('%1$s Translation provider is not supported in your current browser. Please use %2$s browser to use this translation provider.', 'linguator-pro'),
        providerName,
        browserName
    );

    const builtInDefaultReason = (status) => {
        if ('ready' === status) {
            return '';
        }

        return __('Configure this provider before setting it as default.', 'linguator-pro');
    };

    const toggle = (key) => () => providers[key].setEnabled(!providers[key].enabled);

    return (
        <div className="lmat-engines">
            {showSetupCard && (
                <ChromeAiSetupCard browserType={browserType} chromeEnabled={chromeEnabled} edgeEnabled={edgeEnabled} />
            )}

            <div className="lmat-engine-note">
                <span className="lmat-engine-note-icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" fill="currentColor" />
                        <path d="M12 11v6" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
                        <circle cx="12" cy="7.8" r="1.2" fill="#fff" />
                    </svg>
                </span>
                <p>{__('Select your default translation provider. It is pre-selected when the translation popup opens, and you can switch providers anytime from the popup.', 'linguator-pro')}</p>
            </div>

            <ul className="lmat-engine-list">
                <ProviderRow
                    icon={<GoogleIcon className="icon-size" />}
                    name={__('Google Translate', 'linguator-pro')}
                    docsUrl={`https://linguator.com/docs/automatic-translation-via-google-translate-widget/${DOCS_UTM}_google`}
                    enabled={Boolean(providers.google.enabled)}
                    onToggle={toggle('google')}
                    isDefault={'google' === defaultProvider}
                    onDefault={() => onDefaultChange('google')}
                    status="ready"
                    statusLabel={builtInLabels.ready}
                />

                {showChromeRow && (
                    <ProviderRow
                        icon={<ChromeIcon className="icon-size" />}
                        name={__('Chrome Built-in AI', 'linguator-pro')}
                        docsUrl={`https://linguator.com/docs/automatic-translation-via-chrome-ai/${DOCS_UTM}_chrome`}
                        enabled={chromeEnabled}
                        onToggle={toggle('chrome_local_ai')}
                        isDefault={'chrome_local_ai' === defaultProvider}
                        onDefault={() => onDefaultChange('chrome_local_ai')}
                        status={chromeStatus}
                        statusLabel={builtInLabels[chromeStatus]}
                        unsupportedMessage={unsupportedMessage(__('Chrome Built-in AI', 'linguator-pro'), 'Chrome')}
                        defaultDisabledReason={builtInDefaultReason(chromeStatus)}
                        actionLabel={'setup' === chromeStatus && chromeEnabled ? __('Configure', 'linguator-pro') : ''}
                        onAction={scrollToSetupCard}
                    />
                )}

                {showEdgeRow && (
                    <ProviderRow
                        icon={<EdgeIcon className="icon-size" />}
                        name={__('Edge Built-in AI', 'linguator-pro')}
                        docsUrl="https://microsoftedge.github.io/Demos/built-in-ai/playgrounds/translator-api/"
                        enabled={edgeEnabled}
                        onToggle={toggle('edge_local_ai')}
                        isDefault={'edge_local_ai' === defaultProvider}
                        onDefault={() => onDefaultChange('edge_local_ai')}
                        status={edgeStatus}
                        statusLabel={builtInLabels[edgeStatus]}
                        unsupportedMessage={unsupportedMessage(__('Edge Built-in AI', 'linguator-pro'), 'Edge')}
                        defaultDisabledReason={builtInDefaultReason(edgeStatus)}
                        actionLabel={'setup' === edgeStatus && edgeEnabled ? __('Configure', 'linguator-pro') : ''}
                        onAction={scrollToSetupCard}
                    />
                )}

                {geminiAvailable && (
                    <ProviderRow
                        icon={<GeminiIcon className="icon-size" />}
                        name={__('Google Gemini', 'linguator-pro')}
                        docsUrl={`https://linguator.com/docs/automatic-translation-via-googe-gemini/${DOCS_UTM}_gemini`}
                        enabled={Boolean(providers.gemini.enabled)}
                        onToggle={toggle('gemini')}
                        isDefault={'gemini' === defaultProvider}
                        onDefault={() => onDefaultChange('gemini')}
                        status={geminiConfigured ? 'ready' : 'missing-key'}
                        statusLabel={geminiConfigured ? __('Ready', 'linguator-pro') : __('Missing API Key', 'linguator-pro')}
                        defaultDisabledReason={geminiConfigured ? '' : __('Add the API key before setting this provider as default.', 'linguator-pro')}
                        actionLabel={geminiConfigured ? '' : __('Add API Key', 'linguator-pro')}
                        onAction={() => {
                            if (!providers.gemini.enabled) {
                                providers.gemini.setEnabled(true);
                            }
                        }}
                        extra={providers.gemini.enabled ? geminiExtra : null}
                    />
                )}
            </ul>
        </div>
    );
};

export default ProviderList;
