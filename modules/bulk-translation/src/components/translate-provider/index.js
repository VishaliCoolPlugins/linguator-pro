// import YandexTranslater from "./yandex";
import localAiTranslator from "./local-ai/index.js";
import GoogleTranslater from "./google/index.js";
import AiLlmBulkTranslator from "./ai-llm/index.js";
import { sprintf, __ } from "@wordpress/i18n";
import { ChromeIcon } from "../../../../../assets/js/src/icons/chrome.js";
import { EdgeIcon } from "../../../../../assets/js/src/icons/edge.js";
import { GoogleIcon } from "../../../../../assets/js/src/icons/google.js";
import { GeminiIcon } from "../../../../../assets/js/src/icons/gemini.js";

// Browser that ships each built-in AI provider.
const BROWSER_AI_OWNER = Object.freeze({
    localAiTranslator: 'Chrome',
    edgeLocalAiTranslator: 'Edge',
});

const FALLBACK_PROVIDER = 'google';

export const getBrowserType = () => {
    let type = 'Other';
    if (navigator && navigator.userAgentData && navigator.userAgentData.brands) {
        // Edge is Chromium-based, so its brands array contains BOTH
        // "Microsoft Edge" AND "Google Chrome". Check for Edge first
        // so it takes priority and isn't misidentified as Chrome.
        const brands = navigator.userAgentData.brands;
        const isEdgeBrowser = brands.some(data => data.brand === 'Microsoft Edge');
        if (isEdgeBrowser) {
            type = 'Edge';
        } else if (brands.some(data => data.brand === 'Google Chrome')) {
            type = 'Chrome';
        }
    } else {
        if (navigator.userAgent && navigator.userAgent.includes('Edg')) {
            type = 'Edge';
        } else if (window.hasOwnProperty('chrome')) {
            type = 'Chrome';
        }
    }
    return type;
};

/**
 * Provider pre-selected in the bulk modal: the default chosen in the translation settings.
 * A built-in AI default that this browser cannot run falls back to Google Translate.
 *
 * @return {string|null} Service key, or null when nothing can be pre-selected.
 */
export const resolveDefaultService = () => {
    const defaultProvider = window.lmatBulkTranslationGlobal?.default_provider || '';

    if (!defaultProvider) {
        return null;
    }

    const requiredBrowser = BROWSER_AI_OWNER[defaultProvider];
    const service = requiredBrowser && requiredBrowser !== getBrowserType()
        ? FALLBACK_PROVIDER
        : defaultProvider;

    return TranslateService()[service] ? service : null;
};

/**
 * Provides translation services (Google Translate, built-in AI and Gemini).
 */
const TranslateService = (props) => {
    props=props || {};
    const { Service = false, openErrorModalHandler=()=>{}, prefix='' } = props;
    const providers=window.lmatBulkTranslationGlobal.providers;

    const Services = {
        // yandex: {
        google: {
            Provider: GoogleTranslater,
            title: "Google Translate",
            SettingBtnText: "Translate",
            serviceLabel: "Google Translate",
            Docs: "https://linguator.com/docs/automatic-translation-via-google-translate-widget/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=docs&utm_content=bulk_translate_google",
            heading: __("Choose Language", 'translate-words'),
            BetaEnabled: false,
            ButtonDisabled: props.googleButtonDisabled,
            ErrorMessage: props.googleButtonDisabled ? <div className={`${prefix}-provider-error`} onClick={() => openErrorModalHandler(props.googleButtonDisabled)}>{__('View Error', 'translate-words')}</div> : <></>,
            Logo: <GoogleIcon className="icon-size" />,
            filterHtmlContent: true
        },
        localAiTranslator: {
            Provider: localAiTranslator,
            title: "Chrome Built-in AI",
            SettingBtnText: "Translate",
            serviceLabel: "Chrome AI Translator",
            heading: sprintf(__("Translate Using %s", 'translate-words'), "Chrome built-in API"),
            Docs: "https://linguator.com/docs/automatic-translation-via-chrome-ai/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=docs&utm_content=bulk_translate_chrome",
            BetaEnabled: true,
            ButtonDisabled: props.localAiTranslatorButtonDisabled,
            ErrorMessage: props.localAiTranslatorButtonDisabled ? <div className={`${prefix}-provider-error`} onClick={() => openErrorModalHandler(props.localAiTranslatorButtonDisabled)}>{__('View Error', 'translate-words')}</div> : <></>,
            Logo: <ChromeIcon className="icon-size"  />,
            filterHtmlContent: true
        },
        edgeLocalAiTranslator: {
            Provider: localAiTranslator,
            title: "Edge Built-in AI",
            SettingBtnText: "Translate",
            serviceLabel: "Edge AI Translator",
            heading: sprintf(__("Translate Using %s", 'translate-words'), "Edge built-in API"),
            Docs: "https://microsoftedge.github.io/Demos/built-in-ai/playgrounds/translator-api/",
            BetaEnabled: true,
            ButtonDisabled: props.edgeLocalAiTranslatorButtonDisabled,
            ErrorMessage: props.edgeLocalAiTranslatorButtonDisabled ? <div className={`${prefix}-provider-error`} onClick={() => openErrorModalHandler(props.edgeLocalAiTranslatorButtonDisabled)}>{__('View Error', 'translate-words')}</div> : <></>,
            Logo: <EdgeIcon className="icon-size"  />,
            filterHtmlContent: true
        },
        gemini: {
            Provider: AiLlmBulkTranslator,
            title: "Google Gemini",
            SettingBtnText: window.lmatBulkTranslationGlobal.api_keys_status?.gemini ? "Translate" : "Add API Key",
            serviceLabel: "Gemini",
            heading: __("Translate Using Gemini", "translate-words"),
            Docs: "https://linguator.com/docs/automatic-translation-via-googe-gemini/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=docs",
            BetaEnabled: false,
            ButtonDisabled: !window.lmatBulkTranslationGlobal.api_keys_status?.gemini,
            ErrorMessage: !window.lmatBulkTranslationGlobal.api_keys_status?.gemini ? <a href={`${window.lmatBulkTranslationGlobal.admin_url}admin.php?page=lmat_settings&tab=translation`} target="_blank" rel="noopener noreferrer" className={`${prefix}-provider-error`}>{__('Add API Key', 'translate-words')}</a> : <></>,
            Logo: <GeminiIcon className="icon-size" />,
            filterHtmlContent: true
        }
    };

    const browserType = getBrowserType();

    const validServices={};

    providers.forEach((provider) => {
        if (Services[provider]) {
            if (browserType === 'Chrome' && provider === 'edgeLocalAiTranslator') {
                return;
            }
            if (browserType === 'Edge' && provider === 'localAiTranslator') {
                return;
            }
            validServices[provider] = Services[provider];
        }
    });

    if (!Service) {
        return validServices;
    }

    return validServices[Service];
};

export default TranslateService;
