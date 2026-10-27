class ChromeAiTranslator {
    // Static method to create an instance of ChromeAiTranslator and return extra data
    static Object = (options) => {
        const selfObject = new this(options);
        return selfObject.extraData();
    };

    // Constructor to initialize the translator with options
    constructor(options) {
        this.textContentObject=options.textContentObject;
        this.onStartTranslationProcess = options.onStartTranslationProcess || (() => { }); // Callback for when translation starts
        this.onComplete = options.onComplete || (() => { }); // Callback for when translation completes
        this.onLanguageError = options.onLanguageError || (() => { }); // Callback for language errors
        this.onLanguageLoading = options.onLanguageLoading || (() => { }); // Callback while language pack loads
        this.onLanguagePackRequired = options.onLanguagePackRequired || null; // Pause until user clicks Install
        this.onBeforeTranslate = options.onBeforeTranslate || (() => { }); // Callback for before translation
        this.onAfterTranslate = options.onAfterTranslate || (() => { }); // Callback for after translation
        this.sourceLanguageLabel = options.sourceLanguageLabel || "English"; // Default source language label
        this.targetLanguageLabel = options.targetLanguageLabel || "Hindi"; // Default target language label
        this.isEdge = options.isEdge || false;
        // Map Linguator slugs/locales to Translator API BCP-47 codes.
        this.sourceLanguage = ChromeAiTranslator.resolveApiLanguageCode(options.sourceLanguage || "en");
        this.targetLanguage = ChromeAiTranslator.resolveApiLanguageCode(options.targetLanguage || "hi");
    }

    // Method to check language support and return relevant data
    extraData = async () => {
        // Check if the language is supported
        const langSupportedStatus = await ChromeAiTranslator.languageSupportedStatus(this.sourceLanguage, this.targetLanguage, this.targetLanguageLabel, this.sourceLanguageLabel, this.isEdge);

        if (langSupportedStatus !== true) {
            const hardFailTypes = [
                'browser-not-supported',
                'translation-api-not-available',
                'language-not-supported',
                'language-pack-not-installed',
                'language-pack-missing',
            ];
            const hardFail = langSupportedStatus
                && typeof langSupportedStatus === 'object'
                && hardFailTypes.includes(langSupportedStatus.type);

            if (hardFail) {
                this.onLanguageError(langSupportedStatus);
                return {};
            }

            const sourceApi = this.sourceLanguage;
            const targetApi = this.targetLanguage;

            // 1) Try to load the pack automatically first.
            if (typeof this.onLanguageLoading === 'function') {
                this.onLanguageLoading(true);
            }

            const autoResult = await ChromeAiTranslator.tryAutomaticLanguagePack(sourceApi, targetApi);

            if (typeof this.onLanguageLoading === 'function') {
                this.onLanguageLoading(false);
            }

            if (autoResult.ok) {
                // Pack ready — continue translating automatically.
            } else if (
                autoResult.needsInstall
                && typeof this.onLanguagePackRequired === 'function'
            ) {
                // 2) Auto failed (usually needs a click) — show Install button.
                const installed = await this.onLanguagePackRequired({
                    sourceLanguage: sourceApi,
                    targetLanguage: targetApi,
                    sourceLanguageLabel: this.sourceLanguageLabel,
                    targetLanguageLabel: this.targetLanguageLabel,
                    isEdge: this.isEdge,
                });
                if (!installed) {
                    return {};
                }
            } else {
                const scheme = this.isEdge ? 'edge' : 'chrome';
                this.onLanguageError({
                    type: 'language-pack-download-failed',
                    message: `Language pack not ready for ${this.targetLanguageLabel} (${this.targetLanguage}). Install it from ${scheme}://on-device-translation-internals and reload.`,
                });
                return {};
            }
        }

        this.defaultLang = this.targetLanguage; // Set default language

        // Return methods for translation control
        return {
            continueTranslation: this.continueTranslation,
            stopTranslation: this.stopTranslation,
            startTranslation: this.startTranslation,
            reInit: this.reInit,
            init: this.init
        };
    }

    /**
     * Map Linguator slug/locale to a Translator API BCP-47 language code.
     */
    static resolveApiLanguageCode = (code) => {
        if (!code || typeof code !== 'string') {
            return code;
        }

        const languageObject = (typeof lmatBulkTranslationGlobal !== 'undefined' && lmatBulkTranslationGlobal.languageObject) || {};
        let raw = languageObject[code]?.locale || code;
        raw = String(raw).replace(/_/g, '-').toLowerCase();

        if (raw === 'zh-cn' || raw.startsWith('zh-hans')) return 'zh-hans';
        if (raw === 'zh-tw' || raw.startsWith('zh-hant')) return 'zh-hant';
        if (raw === 'pt-br' || raw === 'pt-pt' || raw === 'fr-ca' || raw === 'sr-latn' || raw === 'sr-cyrl') {
            return raw;
        }

        const aliases = { iw: 'he', in: 'id', ji: 'yi' };
        const primary = raw.split('-')[0];
        return aliases[primary] || primary;
    }

    // Chrome Translator API supported codes (developer.chrome.com/docs/ai/translator-api).
    static supportedLanguages = [
        'en', 'es', 'ja', 'ar', 'de', 'bn', 'fr', 'hi', 'it', 'ko',
        'nl', 'pl', 'pt', 'ru', 'th', 'tr', 'vi', 'zh', 'zh-hant', 'zh-hans',
        'bg', 'cs', 'da', 'el', 'fi', 'hr', 'hu', 'id', 'iw', 'he',
        'lt', 'no', 'nb', 'ro', 'sk', 'sl', 'sv', 'uk', 'kn', 'ta', 'te', 'mr',
    ];

    static edgeOnlyLanguages = [
        'nb', 'af', 'is', 'fo', 'lb', 'pt-pt', 'pt-br', 'ca', 'gl', 'oc', 'la',
        'bs', 'dsb', 'hsb', 'sr-latn', 'be', 'mk', 'sr-cyrl', 'kk', 'ky', 'tg',
        'tt', 'mn-cyrl', 'ba', 'ce', 'cv', 'zh-hans', 'lzh', 'yue', 'gu', 'ml',
        'ur', 'as', 'or', 'pa', 'ne', 'si', 'my', 'dv', 'awa', 'bho', 'brx',
        'doi', 'gom', 'hne', 'kha', 'lus', 'mag', 'mai', 'mni', 'sat', 'ms',
        'km', 'lo', 'jv', 'su', 'ceb', 'fil', 'mi', 'fj', 'haw', 'sm', 'to',
        'ty', 'tet', 'fa', 'he', 'ps', 'prs', 'ku', 'ks', 'sd', 'ug', 'ha',
        'ig', 'yo', 'zu', 'xh', 'sw', 'sn', 'st', 'tn', 'nso', 'run', 'rw',
        'ln', 'mg', 'so', 'am', 'ti', 'nya', 'et', 'lv', 'mt', 'eu', 'cy',
        'ga', 'sq', 'hy', 'ka', 'az', 'uz', 'tk', 'bo', 'dzo', 'fr-ca', 'ht',
        'ikt', 'iu-latn', 'iu', 'kmr', 'lug', 'luo', 'mn-mong', 'mww', 'otq',
        'sa', 'tlh-latn', 'yua',
    ];

    /**
     * Checks if the specified source and target languages are supported by the Local Translator AI modal.
     * Error popup HTML matches page-translation local-ai-translator.js.
     */
    static languageSupportedStatus = async (sourceLanguage, targetLanguage, targetLanguageLabel, sourceLanguageLabel, isEdge = false) => {
        let supportedLanguages = [...ChromeAiTranslator.supportedLanguages];
        const edgeOnlyLanguages = ChromeAiTranslator.edgeOnlyLanguages;

        if (isEdge) {
            supportedLanguages = [...supportedLanguages, ...edgeOnlyLanguages];
        }

        const safeBrowser = window.location.protocol === 'https:';
        const browserContentSecure = window?.isSecureContext;

        const getBrowserType = () => {
            let type = 'Other';
            if (navigator && navigator.userAgentData && navigator.userAgentData.brands) {
                navigator.userAgentData.brands.forEach(data => {
                    if (data.brand === 'Google Chrome') {
                        type = 'Chrome';
                    } else if (data.brand === 'Microsoft Edge') {
                        type = 'Edge';
                    }
                });
            } else if (navigator.userAgent.includes('Edg')) {
                type = 'Edge';
            } else if (window.hasOwnProperty('chrome')) {
                type = 'Chrome';
            }
            return type;
        };

        const browserType = getBrowserType();
        const scheme = isEdge ? 'edge' : 'chrome';
        const browserName = isEdge ? 'Edge' : 'Chrome';
        const docUrl = isEdge ? 'https://microsoftedge.github.io/Demos/built-in-ai/playgrounds/translator-api/' : 'https://developer.chrome.com/docs/ai/translator-api';
        const edgeLangRef = '<a href="https://microsoftedge.github.io/Demos/built-in-ai/playgrounds/translator-api/" target="_blank" rel="noopener"><strong style="color: #2271b1;">test your language on the Edge Translator API playground</strong></a>';
        const docsLangUrl = isEdge
            ? 'https://microsoftedge.github.io/Demos/built-in-ai/playgrounds/translator-api/'
            : 'https://developer.chrome.com/docs/ai/translator-api#supported-languages';

        const asError = (html, message, type) => ({ html, message, type });

        // Browser check (same copy as page translation)
        if (isEdge) {
            if (browserType !== 'Edge') {
                const message = jQuery(`<span style="color: #ff4646; display: inline-block;">
                    <strong>Important Notice:</strong>
                    <ol>
                        <li>The Translator API, which leverages Edge local AI models, is designed specifically for use with the Microsoft Edge browser.</li>
                        <li>For comprehensive information about Edge built-in AI translation, <a href="${docUrl}" target="_blank" rel="noopener">open the Edge Translator API playground</a>.</li>
                    </ol>
                    <p>Please ensure you are using the Microsoft Edge browser for optimal performance and compatibility.</p>
                </span>`);
                return asError(message, 'Browser not supported', 'browser-not-supported');
            }
        } else if (browserType !== 'Chrome') {
            const message = jQuery(`<span style="color: #ff4646; display: inline-block;">
                <strong>Important Notice:</strong>
                <ol>
                    <li>The Translator API, which leverages Chrome local AI models, is designed specifically for use with the Chrome browser.</li>
                    <li>For comprehensive information about the Translator API, <a href="${docUrl}" target="_blank">click here</a>.</li>
                </ol>
                <p>Please ensure you are using the Chrome browser for optimal performance and compatibility.</p>
            </span>`);
            return asError(message, 'Browser not supported', 'browser-not-supported');
        }

        if (!('translation' in self && 'createTranslator' in self.translation) && !('ai' in self && 'translator' in self.ai) && !("Translator" in self && "create" in self.Translator) && !safeBrowser && !browserContentSecure) {
            const message = jQuery(`<span style="color: #ff4646; display: inline-block;">
                <strong>Important Notice:</strong>
                <ol>
                    <li>
                        The Translator API is not functioning due to an insecure connection.
                    </li>
                    <li>
                        Please switch to a secure connection (HTTPS) or add this URL to the list of insecure origins treated as secure by visiting
                        <span data-clipboard-text="${scheme}://flags/#unsafely-treat-insecure-origin-as-secure" target="_blank" class="chrome-ai-translator-flags">
                            ${scheme}://flags/#unsafely-treat-insecure-origin-as-secure ${ChromeAiTranslator.svgIcons('copy')}
                        </span>.
                        Click on the URL to copy it, then open a new window and paste this URL to access the settings.
                    </li>
                </ol>
            </span>`);
            return asError(message, 'Browser not supported', 'browser-not-supported');
        }

        if (!('translation' in self && 'createTranslator' in self.translation) && !('ai' in self && 'translator' in self.ai) && !("Translator" in self && "create" in self.Translator)) {
            const message = jQuery(`<span style="color: #ff4646; display: inline-block;">
                <h4>Steps to Enable the Translator AI Modal:</h4>
                <ol>
                    <li>Open this URL in a new ${browserName} tab: <strong><span data-clipboard-text="${scheme}://flags/#translation-api" target="_blank" class="chrome-ai-translator-flags">${scheme}://flags/#translation-api ${ChromeAiTranslator.svgIcons('copy')}</span></strong>. Click on the URL to copy it, then open a new window and paste this URL to access the settings.</li>
                    <li>Ensure that the <strong>Experimental translation API</strong> option is set to <strong>Enabled</strong>.</li>
                    <li>Click on the <strong>Save</strong> button to apply the changes.</li>
                    <li>The Translator AI modal should now be enabled and ready for use.</li>
                </ol>
                <p>For more information, please refer to the <a href="${docUrl}" target="_blank">documentation</a>.</p>
                <p>If the issue persists, please ensure that your browser is up to date and restart your browser.</p>
                <p>If you continue to experience issues after following the above steps, please <a href="https://my.coolplugins.net/account/support-tickets/" target="_blank" rel="noopener">open a support ticket</a> with our team. We are here to help you resolve any problems and ensure a smooth translation experience.</p>
            </span>`);
            return asError(message, 'Translation API not available', 'translation-api-not-available');
        }

        if (!targetLanguage || typeof targetLanguage !== 'string') {
            return asError(jQuery('<span style="color: #ff4646;">Invalid target language.</span>'), 'Invalid target language', 'language-not-supported');
        }
        if (!sourceLanguage || typeof sourceLanguage !== 'string') {
            return asError(jQuery('<span style="color: #ff4646;">Invalid source language.</span>'), 'Invalid source language', 'language-not-supported');
        }

        const sourceApi = ChromeAiTranslator.resolveApiLanguageCode(sourceLanguage);
        const targetApi = ChromeAiTranslator.resolveApiLanguageCode(targetLanguage);

        // Language support check (same order/copy as page translation)
        if (!supportedLanguages.includes(String(targetApi).toLowerCase()) && !supportedLanguages.includes(String(targetApi).split('-')[0].toLowerCase())) {
            const message = jQuery(`<span style="color: #ff4646; display: inline-block;">
                <strong>Language Support Information:</strong>
                <ol>
                    <li>The current version of ${browserName} AI Translator does not support the Target Language <strong>${targetLanguageLabel} (${targetApi})</strong></li>
                    ${isEdge ? `<li>To check if this language is available in Edge, ${edgeLangRef}.</li>` : `<li>To view the list of supported languages, please <span data-clipboard-text="${scheme}://on-device-translation-internals" target="_blank" class="chrome-ai-translator-flags">${scheme}://on-device-translation-internals ${ChromeAiTranslator.svgIcons('copy')}</span>. Click on the URL to copy it, then open a new window and paste this URL to access the settings.</li>`}
                    <li>Ensure your ${browserName} browser is updated to the latest version for optimal performance.</li>
                </ol>
            </span>`);
            return asError(message, `Target Language not supported: ${targetLanguageLabel} (${targetApi})`, 'language-not-supported');
        }

        if (!supportedLanguages.includes(String(sourceApi).toLowerCase()) && !supportedLanguages.includes(String(sourceApi).split('-')[0].toLowerCase())) {
            const message = jQuery(`<span style="color: #ff4646; display: inline-block;">
                <strong>Language Support Information:</strong>
                <ol>
                    <li>The current version of ${browserName} AI Translator does not support the Source Language <strong>${sourceLanguageLabel} (${sourceApi})</strong></li>
                    ${isEdge ? `<li>To check if this language is available in Edge, ${edgeLangRef}.</li>` : `<li>To view the list of supported languages, please <span data-clipboard-text="${scheme}://on-device-translation-internals" target="_blank" class="chrome-ai-translator-flags">${scheme}://on-device-translation-internals ${ChromeAiTranslator.svgIcons('copy')}</span>. Click on the URL to copy it, then open a new window and paste this URL to access the settings.</li>`}
                    <li>Ensure your ${browserName} browser is updated to the latest version for optimal performance.</li>
                </ol>
            </span>`);
            return asError(message, `Source Language not supported: ${sourceLanguageLabel} (${sourceApi})`, 'language-not-supported');
        }

        const status = await ChromeAiTranslator.languagePairAvality(sourceApi, targetApi);

        const makePending = (downloading = false) => {
            const pending = jQuery('<span class="atlt-chromeai-download-pending' + (downloading ? ' atlt-chromeai-downloading' : '') + '"></span>')
                .attr('data-source', sourceApi)
                .attr('data-target', targetApi)
                .attr('data-source-label', sourceLanguageLabel || sourceLanguage)
                .attr('data-target-label', targetLanguageLabel || targetLanguage);
            return {
                html: pending,
                message: downloading ? 'Language pack downloading' : 'Language pack downloadable',
                type: downloading ? 'language-pack-downloading' : 'language-pack-downloadable'
            };
        };

        if (status === 'readily' || status === 'available' || status === true) {
            return true;
        }

        if (status === 'after-download' || status === 'downloadable') {
            return makePending(false);
        }

        if (status === 'unavailable' || status === 'no') {
            const message = jQuery(`<span style="color: #ff4646; display: inline-block;">
                <h4>Installation Instructions for Language Packs:</h4>
                <ol>
                    <li>
                        To proceed, please install the language pack for <strong>${targetLanguageLabel} (${targetApi})</strong> or <strong>${sourceLanguageLabel} (${sourceApi})</strong>.
                    </li>
                    <li>
                        After installing the language pack, add this language to your browser's system languages in ${browserName} settings.<br>
                        Go to <strong>Settings &gt; Languages &gt; Add languages</strong> and add <strong>${targetLanguageLabel}</strong> or <strong>${sourceLanguageLabel}</strong> to your preferred languages list &amp; reload the page.
                    </li>
                    <li>
                        You can install it by visiting the following link:
                        <strong>
                            <span data-clipboard-text="${scheme}://on-device-translation-internals" target="_blank" class="chrome-ai-translator-flags">${scheme}://on-device-translation-internals ${ChromeAiTranslator.svgIcons('copy')}</span>
                        </strong>. Click on the URL to copy it, then open a new window and paste this URL to access the settings.
                    </li>
                    <li>
                        Please check if both your source <strong>(<span style="color:#2271b1">${sourceApi}</span>)</strong> and target <strong>(<span style="color:#2271b1">${targetApi}</span>)</strong> languages are available in the language packs list.
                    </li>
                    <li>
                        You need to install both language packs for translation to work. You can search for each language by its language code: <strong>${sourceApi}</strong> and <strong>${targetApi}</strong>.
                    </li>
                    <li>For more help, refer to the <a href="${docsLangUrl}" target="_blank" rel="noopener noreferrer">documentation to check supported languages</a>.</li>
                </ol>
            </span>`);
            return asError(message, `Language pack not installed: ${targetLanguageLabel} (${targetApi}) or ${sourceLanguageLabel} (${sourceApi})`, 'language-pack-not-installed');
        }

        if (status === 'downloading') {
            return makePending(true);
        }

        const message = jQuery(`<span style="color: #ff4646; display: inline-block;">
            <h4>Language Pack Installation Required</h4>
            <ol>
                <li>Please ensure that the language pack for <strong>${targetLanguageLabel} (${targetApi})</strong> or <strong>${sourceLanguageLabel} (${sourceApi})</strong> is installed and set as a preferred language in your browser.</li>
                <li>To install the language pack, ${isEdge ? `visit the Edge settings to install language packs. For more info, ${edgeLangRef}.` : `visit <strong><span data-clipboard-text="${scheme}://on-device-translation-internals" target="_blank" class="chrome-ai-translator-flags">${scheme}://on-device-translation-internals ${ChromeAiTranslator.svgIcons('copy')}</span></strong>. Click on the URL to copy it, then open a new window and paste this URL to access the settings.`}</li>
                <li>If you encounter any issues, please refer to the <a href="${docsLangUrl}" target="_blank" rel="noopener noreferrer">documentation to check supported languages</a> for further assistance.</li>
            </ol>
        </span>`);
        return asError(message, `Language pack missing for ${targetLanguageLabel} (${targetApi}) or ${sourceLanguageLabel} (${sourceApi})`, 'language-pack-missing');
    }

    /**
     * Check pair availability (read-only — does not start downloads).
     */
    static languagePairAvality = async (source, target) => {
        const sourceApi = ChromeAiTranslator.resolveApiLanguageCode(source);
        const targetApi = ChromeAiTranslator.resolveApiLanguageCode(target);

        if (('translation' in self && 'createTranslator' in self.translation)) {
            return await self.translation.canTranslate({
                sourceLanguage: sourceApi,
                targetLanguage: targetApi,
            });
        } else if (('ai' in self && 'translator' in self.ai)) {
            const translatorCapabilities = await self.ai.translator.capabilities();
            return await translatorCapabilities.languagePairAvailable(sourceApi, targetApi);
        } else if ("Translator" in self && "create" in self.Translator) {
            return await self.Translator.availability({
                sourceLanguage: sourceApi,
                targetLanguage: targetApi,
            });
        }

        return false;
    }

    /**
     * Start pack download via Translator.create() — must run from a user click.
     */
    static downloadLanguagePair = async (source, target, onProgress) => {
        const sourceApi = ChromeAiTranslator.resolveApiLanguageCode(source);
        const targetApi = ChromeAiTranslator.resolveApiLanguageCode(target);

        try {
            if ('translation' in self && 'createTranslator' in self.translation) {
                await self.translation.createTranslator({ sourceLanguage: sourceApi, targetLanguage: targetApi });
            } else if ('ai' in self && 'translator' in self.ai) {
                await self.ai.translator.create({ sourceLanguage: sourceApi, targetLanguage: targetApi });
            } else if ('Translator' in self && 'create' in self.Translator) {
                await self.Translator.create({
                    sourceLanguage: sourceApi,
                    targetLanguage: targetApi,
                    monitor(m) {
                        m.addEventListener('downloadprogress', (e) => {
                            if (typeof onProgress === 'function') {
                                onProgress(e);
                            }
                            console.log('Downloaded ' + (e.loaded * 100) + '%');
                        });
                    },
                });
            }
        } catch (err) {
            console.log('Language pack download error:', err);
            if (err && (err.name === 'NotSupportedError' || /not supported|unsupported/i.test(String(err.message || '')))) {
                return 'unavailable';
            }
            if (err && (err.name === 'NotAllowedError' || /user gesture/i.test(String(err.message || '')))) {
                return 'downloadable';
            }
        }

        return await ChromeAiTranslator.languagePairAvality(sourceApi, targetApi);
    }

    static waitForLanguagePair = async (sourceApi, targetApi, maxAttempts = 120, stopIfStuckDownloadable = false) => {
        let status = await ChromeAiTranslator.languagePairAvality(sourceApi, targetApi);
        let attempts = 0;
        let stuckDownloadable = 0;

        while (
            (status === 'downloading' || status === 'downloadable' || status === 'after-download')
            && attempts < maxAttempts
        ) {
            if (stopIfStuckDownloadable && (status === 'downloadable' || status === 'after-download')) {
                stuckDownloadable++;
                if (stuckDownloadable >= 5) {
                    break;
                }
            } else {
                stuckDownloadable = 0;
            }

            await new Promise((resolve) => setTimeout(resolve, 1000));
            status = await ChromeAiTranslator.languagePairAvality(sourceApi, targetApi);
            attempts++;
        }

        return status;
    }

    /**
     * Try to load a language pack without requiring a dedicated Install click.
     * Returns { ok, needsInstall, status }.
     */
    static tryAutomaticLanguagePack = async (source, target, onProgress) => {
        const sourceApi = ChromeAiTranslator.resolveApiLanguageCode(source);
        const targetApi = ChromeAiTranslator.resolveApiLanguageCode(target);
        let status = await ChromeAiTranslator.languagePairAvality(sourceApi, targetApi);

        if (status === 'available' || status === 'readily' || status === true) {
            return { ok: true, needsInstall: false, status };
        }

        // Already downloading — wait for it.
        if (status === 'downloading') {
            status = await ChromeAiTranslator.waitForLanguagePair(sourceApi, targetApi);
            const ok = status === 'available' || status === 'readily' || status === true;
            return {
                ok,
                needsInstall: !ok && (status === 'downloadable' || status === 'after-download' || status === 'downloading'),
                status,
            };
        }

        // Try create() automatically (works if a prior gesture still applies / pack can start).
        if (status === 'downloadable' || status === 'after-download') {
            status = await ChromeAiTranslator.downloadLanguagePair(sourceApi, targetApi, onProgress);

            if (status === 'downloading') {
                status = await ChromeAiTranslator.waitForLanguagePair(sourceApi, targetApi);
            } else if (status === 'downloadable' || status === 'after-download') {
                // NotAllowedError path — don't hang; fall through to Install button.
                status = await ChromeAiTranslator.waitForLanguagePair(sourceApi, targetApi, 5, true);
            }
        }

        const ok = status === 'available' || status === 'readily' || status === true;
        const needsInstall = !ok && (
            status === 'downloadable'
            || status === 'after-download'
            || status === 'downloading'
        );

        return { ok, needsInstall, status };
    }

    /**
     * Install pack from a user click, then wait until available.
     */
    static installLanguagePackFromClick = async (source, target, onProgress) => {
        const sourceApi = ChromeAiTranslator.resolveApiLanguageCode(source);
        const targetApi = ChromeAiTranslator.resolveApiLanguageCode(target);
        let status = await ChromeAiTranslator.languagePairAvality(sourceApi, targetApi);

        if (status === 'available' || status === 'readily' || status === true) {
            return { ok: true, status };
        }

        if (status !== 'downloading') {
            status = await ChromeAiTranslator.downloadLanguagePair(sourceApi, targetApi, onProgress);
        }

        if (status === 'downloading' || status === 'downloadable' || status === 'after-download') {
            status = await ChromeAiTranslator.waitForLanguagePair(sourceApi, targetApi);
        }

        const ok = status === 'available' || status === 'readily' || status === true;
        return { ok, status };
    }

    AITranslator=async (targetLanguage)=>{
        if(('translation' in self && 'createTranslator' in self.translation)){
            const translator=await self.translation.createTranslator({
                sourceLanguage: this.sourceLanguage,
                targetLanguage,
            });

            return translator;
        }else if(('ai' in self && 'translator' in self.ai )){
            const translator = await self.ai.translator.create({
                sourceLanguage: this.sourceLanguage,
                targetLanguage,
              });

            return translator;
        }else if("Translator" in self && "create" in self.Translator){
            const translator = await self.Translator.create({
                sourceLanguage: this.sourceLanguage,
                targetLanguage,
            });

            return translator;
        }

        return false;
    }

    // Method to initialize the translation process
    init = async (textContentArray) => {
        this.textContent = textContentArray;
        this.textContentKeys=Object.keys(this.textContent);
        this.translationStart = false; // Flag to indicate if translation has started
        this.completedTranslateIndex=0;
        this.completedCharacterCount = 0; // Count of characters translated
    };

    // Method to start the translation process
    startTranslationProcess = async () => {
        this.onStartTranslationProcess(); // Call the start translation callback
        const langCode = this.defaultLang; // Get the default language code

        this.translationStart = true; // Set translation start flag

        // Create a translator instance
        this.translator = await this.AITranslator(langCode);

        if(this.textContentKeys.length > 0 && this.textContentKeys.length > this.completedTranslateIndex){
            await this.stringTranslation(this.completedTranslateIndex);
        }
    };

    // Method to translate a specific string at the given index
    stringTranslation = async (index) => {
        if (!this.translateStatus) return; // Exit if translation is stopped

        let ele = document.createElement('div'); // Get the element to translate
        ele.innerHTML = this.textContent[this.textContentKeys[index]];
        this.onBeforeTranslate(ele); // Call the before translation callback
        const orignalText = ele.innerText;
        let originalString = [];

        if (ele.childNodes.length > 0 && !ele.querySelector('.notranslate')) {
            ele.childNodes.forEach(child => {
                if (
                    child.nodeType === 3 &&
                    child.nodeValue.trim() !== '' &&
                    !/^\d+$/.test(child.nodeValue.trim())
                ) {
                    originalString.push(child);
                }else if(child.childNodes.length > 0){
                    // this.translateChildNodes(child, originalString);
                }
            });
        } else if (ele.querySelector('.notranslate')) {
            ele.childNodes.forEach(child => {
                if (
                    child.nodeType === 3 &&
                    child.nodeValue.trim() !== '' &&
                    !/^\d+$/.test(child.nodeValue.trim())
                ) {
                    originalString.push(child);
                }
            });
        }

        if (originalString.length > 0) {
            await this.stringTranslationBatch(originalString, 0);
        }

        this.completedTranslateIndex=index;
        this.completedCharacterCount += orignalText.length; // Update character count

        this.textContent[this.textContentKeys[index]]=ele.innerText;

        this.onAfterTranslate(this.textContentKeys[index], this.textContent[this.textContentKeys[index]]); // Call the after translation callback

        ele.remove();
        ele=null;

        if(this.textContentKeys.length > this.completedTranslateIndex + 1){
            await this.stringTranslation(this.completedTranslateIndex + 1);
        }

        if(index === this.textContentKeys.length - 1){
            this.onComplete({ characterCount: this.completedCharacterCount }); // Call the complete callback
        }
    };

    translateChildNodes = async (ele, originalString) => {
        if(ele.childNodes.length > 0 && !ele.querySelector('.notranslate')){
            ele.childNodes.forEach(child => {
                if(child.nodeType === 3 && child.nodeValue.trim() !== '' && !/^\d+$/.test(child.nodeValue.trim())){
                    originalString.push(child);
                }else if(child.childNodes.length > 0){
                    this.translateChildNodes(child, originalString);
                }
            });
        }else if(ele.querySelector('.notranslate')){
            ele.childNodes.forEach(child => {
                if(child.nodeType === 3 && child.nodeValue.trim() !== '' && !/^\d+$/.test(child.nodeValue.trim())){
                    originalString.push(child);
                }else if(child.childNodes.length > 0){
                    this.translateChildNodes(child, originalString);
                }
            });
        }
    }

    stringTranslationBatch = async (originalString, index) => {
        try {
            const sourceText = originalString[index].nodeValue;
            const translatedString = await this.translator.translate(sourceText); // Translate the string

            if (translatedString && '' !== translatedString) {
                originalString[index].nodeValue = translatedString; // Set the translated string
            }
        } catch (error) {
            console.error("[Bulk] Error during translation in stringTranslationBatch:", error);
        }

        if (index < originalString.length - 1) {
            await this.stringTranslationBatch(originalString, index + 1);
        }

        return true;
    }

    // Method to stop the translation process
    stopTranslation = () => {
        this.translateStatus = false; // Set translation status to false
    }

    // Method to reinitialize button events
    reInit = () => {
        this.translateBtnEvents(); // Re-setup button events
    }

    // Method to start translation from the current index
    startTranslation = async () => {
        this.translateStatus = true; // Set translation status to true
        await this.startTranslationProcess(); // Start translation process
    }

    
    static svgIcons=(iconName)=>{
        const Icons={
            'copy':`<svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 448 512" height="16px" width="16px" xmlns="http://www.w3.org/2000/svg" fill="#2271b1"><path d="M433.941 65.941l-51.882-51.882A48 48 0 0 0 348.118 0H176c-26.51 0-48 21.49-48 48v48H48c-26.51 0-48 21.49-48 48v320c0 26.51 21.49 48 48 48h224c26.51 0 48-21.49 48-48v-48h80c26.51 0 48-21.49 48-48V99.882a48 48 0 0 0-14.059-33.941zM266 464H54a6 6 0 0 1-6-6V150a6 6 0 0 1 6-6h74v224c0 26.51 21.49 48 48 48h96v42a6 6 0 0 1-6 6zm128-96H182a6 6 0 0 1-6-6V54a6 6 0 0 1 6-6h106v88c0 13.255 10.745 24 24 24h88v202a6 6 0 0 1-6 6zm6-256h-64V48h9.632c1.591 0 3.117.632 4.243 1.757l48.368 48.368a6 6 0 0 1 1.757 4.243V112z"></path></svg>`
        }

        return Icons[iconName] || '';
    }
}

/*
 * Example Usage of the ChromeAiTranslator.init method.
 * This method initializes the Chrome AI Translator with a comprehensive set of configuration options to facilitate the translation process.
 * 
 * Configuration Options:
 * 
 * - mainWrapperSelector: A CSS selector for the main wrapper element that encapsulates all translation-related elements.
 * - btnSelector: A CSS selector for the button that initiates the translation process.
 * - btnClass: A custom class for styling the translation button.
 * - btnText: The text displayed on the translation button.
 * - stringSelector: A CSS selector for the elements that contain the strings intended for translation.
 * - progressBarSelector: A CSS selector for the progress bar element that visually represents the translation progress.
 * - sourceLanguage: The language code representing the source language (e.g., "es" for Spanish).
 * - targetLanguage: The language code representing the target language (e.g., "fr" for French).
 * - onStartTranslationProcess: A callback function that is executed when the translation process begins.
 * - onBeforeTranslate: A callback function that is executed prior to each individual translation.
 * - onAfterTranslate: A callback function that is executed following each translation.
 * - onComplete: A callback function that is executed upon the completion of the translation process.
 * - onLanguageError: A callback function that is executed when a language-related error occurs.
 */

// Example for checking language support status
// ChromeAiTranslator.languageSupportedStatus("en", "fr", "French");

// const chromeAiTranslatorObject = ChromeAiTranslator.Object(
//     {
//         mainWrapperSelector: ".main-wrapper", // CSS selector for the main wrapper element
//         btnSelector: ".translator-container .translator-button", // CSS selector for the translation button
//         btnClass: "Btn_custom_class", // Custom class for button styling
//         btnText: "Translate To French", // Text displayed on the translation button
//         stringSelector: ".translator-body .translation-item", // CSS selector for translation string elements
//         progressBarSelector: ".translator-progress-bar", // CSS selector for the progress bar
//         sourceLanguage: "es", // Language code for the source language
//         targetLanguage: "fr", // Language code for the target language
//         onStartTranslationProcess: () => { console.log("Translation process started."); }, // Callback for translation start
//         onBeforeTranslate: () => { console.log("Before translation."); }, // Callback before each translation
//         onAfterTranslate: () => { console.log("After translation."); }, // Callback after each translation
//         onComplete: () => { console.log("Translation completed."); }, // Callback for completion
//         onLanguageError: () => { console.error("Language error occurred."); } // Callback for language errors
//     }
// );
// chromeAiTranslatorObject.init();

export default ChromeAiTranslator;