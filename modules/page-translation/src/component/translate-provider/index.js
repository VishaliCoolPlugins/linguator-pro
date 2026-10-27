import GoogleTranslater from "./google/index.js";
import localAiTranslator from "./local-ai-translator/index.js";
import createAiLlmPageTranslator from "./ai-llm/index.js";
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
 * Provider pre-selected in the translation popup: the default chosen in the translation settings.
 * A built-in AI default that this browser cannot run falls back to Google Translate.
 *
 * @return {string|null} Service key, or null when nothing can be pre-selected.
 */
export const resolveDefaultService = () => {
    const defaultProvider = window.lmatPageTranslationGlobal?.default_provider || '';

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
    const { Service = false, openErrorModalHandler=()=>{} } = props;
    const providers=window.lmatPageTranslationGlobal.providers;

    const Services = {
        google: {
            Provider: GoogleTranslater,
            title: "Google Translate",
            SettingBtnText: "Translate",
            serviceLabel: "Google Translate",
            Docs: "https://linguator.com/docs/automatic-translation-via-google-translate-widget/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=docs&utm_content=popup_google_pro",
            heading: __("Choose Language", "linguator-pro"),
            BetaEnabled: false,
            ButtonDisabled: props.googleButtonDisabled,
            ErrorMessage: props.googleButtonDisabled ? <div className="lmat-page-translation-provider-error" onClick={() => openErrorModalHandler("google")}>{__('View Error', 'linguator-pro')}</div> : <></>,
            Logo: <GoogleIcon className="icon-size"/>
        },
        localAiTranslator: {
            Provider: localAiTranslator,
            title: "Chrome Built-in AI",
            SettingBtnText: "Translate",
            serviceLabel: "Chrome AI Translator",
            heading: sprintf(__("Translate Using %s", "linguator-pro"), "Chrome built-in API"),
            Docs: "https://linguator.com/docs/automatic-translation-via-chrome-ai/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=docs&utm_content=popup_chrome_pro",
            BetaEnabled: true,
            ButtonDisabled: props.localAiTranslatorButtonDisabled,
            ErrorMessage: props.localAiTranslatorButtonDisabled ? <div className="lmat-page-translation-provider-error" onClick={() => openErrorModalHandler("localAiTranslator")}>{__('View Error', 'linguator-pro')}</div> : <></>,
            Logo: <ChromeIcon className="icon-size"/>
        },
        edgeLocalAiTranslator: {
            Provider: localAiTranslator,
            title: "Edge Built-in AI",
            SettingBtnText: "Translate",
            serviceLabel: "Edge AI Translator",
            heading: sprintf(__("Translate Using %s", "linguator-pro"), "Edge built-in API"),
            Docs: "https://microsoftedge.github.io/Demos/built-in-ai/playgrounds/translator-api/",
            BetaEnabled: true,
            ButtonDisabled: props.edgeLocalAiTranslatorButtonDisabled,
            ErrorMessage: props.edgeLocalAiTranslatorButtonDisabled ? <div className="lmat-page-translation-provider-error" onClick={() => openErrorModalHandler("edgeLocalAiTranslator")}>{__('View Error', 'linguator-pro')}</div> : <></>,
            Logo: <EdgeIcon className="icon-size"/>
        },
        gemini: {
            Provider: createAiLlmPageTranslator("gemini"),
            title: "Google Gemini",
            SettingBtnText: window.lmatPageTranslationGlobal.api_keys_status?.gemini ? "Translate" : "Add API Key",
            serviceLabel: "Gemini",
            heading: __("Translate Using Gemini", "linguator-pro"),
            Docs: "https://linguator.com/docs/automatic-translation-via-googe-gemini/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=docs",
            BetaEnabled: false,
            ButtonDisabled: !window.lmatPageTranslationGlobal.api_keys_status?.gemini,
            ErrorMessage: !window.lmatPageTranslationGlobal.api_keys_status?.gemini ? <a href={`${window.lmatPageTranslationGlobal.admin_url}admin.php?page=lmat_settings&tab=translation`} target="_blank" rel="noopener noreferrer" className="lmat-page-translation-provider-error">{__('Add API Key', 'linguator-pro')}</a> : <></>,
            Logo: <GeminiIcon className="icon-size" />
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
