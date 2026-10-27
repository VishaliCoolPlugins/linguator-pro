import ModalStringScroll from "../../string-modal-scroll/index.js";
import GoogleLanguage, { mapToGoogleLanguageCode } from "./google-language.js";
import { selectProgressStatus, selectTargetContent, selectTranslatePostInfo, selectGlossaryTerms } from "../../../redux-store/features/selectors.js";
import { store } from "../../../redux-store/store.js";
import { updateProgressStatus, updateTranslatePostInfo, unsetPendingPost } from "../../../redux-store/features/actions.js";
import { __, sprintf } from "@wordpress/i18n";

/**
 * Initializes Google Translate functionality on specific elements based on provided data.
 * @param {Object} data - The data containing source and target languages.
 */
class GoogleTranslater {
    constructor({ sourceLang = 'en', targetLangs = false, updateContent, totalPosts, storeDispatch, postId, prefix, updateDestoryHandler }) {
        this.sourceLang = sourceLang;
        this.targetLangs = targetLangs;
        this.updateContent = updateContent;
        this.totalPosts = totalPosts;
        this.storeDispatch = storeDispatch;
        this.postId = postId;
        this.prefix = prefix;
        this.updateDestoryHandler = updateDestoryHandler;
        this.stopTranslation = false;
        this.textContentObject = selectTargetContent(store.getState(), postId);
        this.glossaryTerms = selectGlossaryTerms(store.getState(), sourceLang);
        this.activeLanguageGlossaryTerms={};
        this.appendStringTable();
        updateDestoryHandler(() => {
            this.destroy();
        });

    }

    /** Count finished languages (success or terminal failure) toward the bulk progress bar. */
    advanceProgress = () => {
        this.storeDispatch(updateProgressStatus(100 / this.totalPosts));
    };

    destroy = () => {
        this.stopTranslation = true;
        if (this._mutationObserver) {
            try {
                this._mutationObserver.disconnect();
            } catch (e) {
                /* noop */
            }
            this._mutationObserver = null;
        }
        if (this._translateTimeout) {
            clearTimeout(this._translateTimeout);
            this._translateTimeout = null;
        }
    }

    createGoogleTranslator = async (targetLang, index) => {
        this.completedTranslateIndex = 0;
        this.googleTranslator = null;
        this.activeLanguageGlossaryTerms={};
        
        if (this.stopTranslation) return;

        const languageObject = lmatBulkTranslationGlobal.languageObject;
        this.completedPostStatus = selectProgressStatus(store.getState());

        if (!GoogleLanguage().includes(this.filterLanguage(targetLang))) {
            this.storeDispatch(unsetPendingPost(this.postId + '_' + targetLang));
            this.storeDispatch(updateTranslatePostInfo({ [this.postId + '_' + targetLang]: { status: 'error', messageClass: 'error', errorMessage: sprintf(__('Language %s(%s) is not supported by Google Translate', 'translate-words'), languageObject[targetLang].name, targetLang), errorHtml: false } }));
            if (!this.stopTranslation) {
                this.advanceProgress();
            }
        } else {
            this.activeTargetLang = targetLang;
            this.activeLanguageGlossaryTerms[targetLang]={};
            if(this.glossaryTerms && Object.values(this.glossaryTerms).length > 0){
                Object.values(this.glossaryTerms).forEach(term => {
                    if(term.translations && term.translations[targetLang]){
                        this.activeLanguageGlossaryTerms[targetLang][term.original_term]=term.translations[targetLang];
                    }
                })
            }

            this.storeDispatch(updateTranslatePostInfo({ [this.postId + '_' + targetLang]: { status: 'running', messageClass: '' } }));
            this.startTime = new Date();
            let isTranslated = false;
            try {
                isTranslated = await this.translateContent();
            } catch (err) {
                console.error(err);
                this.storeDispatch(unsetPendingPost(`${this.postId}_${targetLang}`));
                this.storeDispatch(
                    updateTranslatePostInfo({
                        [`${this.postId}_${targetLang}`]: {
                            status: 'error',
                            messageClass: 'error',
                            errorMessage: __('Google Translate failed for this batch. Try again.', 'translate-words'),
                            errorHtml: false,
                        },
                    })
                );
            }

            if (!this.stopTranslation) {
                this.advanceProgress();
                if (isTranslated) {
                    try {
                        await this.updateContent(targetLang);
                        await new Promise(resolve => setTimeout(resolve, 500));
                    } catch (err) {
                        console.error(err);
                        this.storeDispatch(unsetPendingPost(`${this.postId}_${targetLang}`));
                        this.storeDispatch(
                            updateTranslatePostInfo({
                                [`${this.postId}_${targetLang}`]: {
                                    status: 'error',
                                    messageClass: 'error',
                                    errorMessage: __('Google Translate failed for this batch. Try again.', 'translate-words'),
                                    errorHtml: false,
                                },
                            })
                        );
                    }
                }
            }

        }

        this.startTime = null;

        if (index < this.targetLangs.length - 1 && !this.stopTranslation) {
            await this.createGoogleTranslator(this.targetLangs[index + 1], index + 1);
        }
    }

    appendTranslateWidget = () => {
        const g = typeof window !== "undefined" ? window.google : undefined;
        const TranslateElementCtor = g?.translate?.TranslateElement;
        if (!TranslateElementCtor) {
            return false;
        }

        try {
            this.googleTranslator = new TranslateElementCtor(
                {
                    pageLanguage: this.filterLanguage(this.sourceLang),
                    autoDisplay: false,
                },
                `${this.prefix}-google-translate-btn`
            );
        } catch {
            return false;
        }

        return true;
    };

    appendStringTable = () => {

        const tableContainer = jQuery(`#${this.prefix}-google-table-container`);
        const container = jQuery(`#${this.prefix}-container`);

        if (tableContainer && tableContainer.length > 0) {
            tableContainer.find(`#${this.prefix}-google-translate-strings-container`).remove();
            tableContainer.attr('data-render-id', this.postId);
            const stringHtml = `<div id="${this.prefix}-google-translate-strings-container">
            ${Object.keys(this.textContentObject).map(key => `<div class="${this.prefix}-google-table-row"><div class="${this.prefix}-google-table-cell" data-key="${key}">${this.textContentObject[key]}</div></div>`).join('')}</div>`;

            tableContainer.append(stringHtml);

        } else {
            const tableHtml = `<div id="${this.prefix}-google-table-container" class="translate" data-render-id="${this.postId}"><div id="${this.prefix}-google-translate-btn">Translate</div><div id="${this.prefix}-google-translate-strings-container">${Object.keys(this.textContentObject).map(key => `<div class="${this.prefix}-google-table-row"><div class="${this.prefix}-google-table-cell" data-key="${key}" lang="${this.sourceLang}">${this.textContentObject[key]}</div></div>`).join('')}</div></div>`

            container.append(tableHtml);
        }

        document.documentElement.setAttribute('translate', 'no');
        document.body.classList.add('notranslate');
    }

    /**
     * Wait for Google Translate widget combo + options to finish loading.
     */
    waitForGoogleLanguageSelector = async (langCode, timeoutMs = 15000) => {
        const started = Date.now();
        const intervalMs = 250;

        while (Date.now() - started < timeoutMs) {
            if (this.stopTranslation) {
                return { selector: null, languageReady: false };
            }

            const selector = document.querySelector(`#${this.prefix}-google-translate-btn .goog-te-combo`);
            if (selector && selector.options && selector.options.length > 1) {
                const languageReady = !!selector.querySelector(`option[value="${langCode}"]`);
                if (languageReady) {
                    return { selector, languageReady: true };
                }
            }

            await new Promise((resolve) => setTimeout(resolve, intervalMs));
        }

        const selector = document.querySelector(`#${this.prefix}-google-translate-btn .goog-te-combo`);
        const languageReady = !!(selector && selector.querySelector(`option[value="${langCode}"]`));
        return { selector, languageReady };
    };

    translateContent = async () => {
        const langCode = this.filterLanguage(this.activeTargetLang);
        const { selector: languageSelector, languageReady } = await this.waitForGoogleLanguageSelector(langCode);

        if (!languageSelector) {
            this.storeDispatch(unsetPendingPost(`${this.postId}_${this.activeTargetLang}`));
            this.storeDispatch(
                updateTranslatePostInfo({
                    [`${this.postId}_${this.activeTargetLang}`]: {
                        status: 'error',
                        messageClass: 'error',
                        errorMessage: __(
                            'Google Translate did not load. Check your internet connection, disable ad blockers for this site, and try again.',
                            'translate-words'
                        ),
                        errorHtml: false,
                    },
                })
            );
            return false;
        }

        if (!languageReady) {
            const languageObject = lmatBulkTranslationGlobal.languageObject;

            this.storeDispatch(updateTranslatePostInfo({
                [`${this.postId}_${this.activeTargetLang}`]: {
                    status: 'error',
                    messageClass: 'error',
                    errorMessage: sprintf(__('Language %s(%s) is not supported by Google Translate', 'translate-words'), languageObject[this.activeTargetLang]?.name, this.activeTargetLang),
                    errorHtml: false
                }
            }));
            this.storeDispatch(unsetPendingPost(`${this.postId}_${this.activeTargetLang}`));
            return false;
        }

        document.body.classList.add(`${this.prefix}-google-translate`);
        languageSelector.value = langCode;

        languageSelector.dispatchEvent(new Event('change'));

        if (this.stopTranslation) return false;
    
        const container = document.querySelector(`#${this.prefix}-google-translate-strings-container`);
        if (!container) {
            this.storeDispatch(unsetPendingPost(`${this.postId}_${this.activeTargetLang}`));
            this.storeDispatch(
                updateTranslatePostInfo({
                    [`${this.postId}_${this.activeTargetLang}`]: {
                        status: 'error',
                        messageClass: 'error',
                        errorMessage: __('Translation UI is missing. Reload the page and try again.', 'translate-words'),
                        errorHtml: false,
                    },
                })
            );
            return false;
        }

        let status=false;
        let mutationRun=false;
    
        // ✅ Wait for translated element using a promise
        await new Promise((resolve, reject) => {
            const mutationObserver = new MutationObserver(async (mutations, obs) => {
                for (const mutation of mutations) {
                    if (mutation.type === 'childList') {
                        const nodes = Array.from(mutation.addedNodes);
                        for (const node of nodes) {
                            if (node.nodeType === 1 && node.tagName === 'FONT') {
                                const fontStyle = node.getAttribute('style');
                                if (!fontStyle) continue;
                                mutationRun=true;
                                mutationObserver.disconnect();
                                this._mutationObserver = null;
    
                                try {
                                    status = await this.startTranslate();
                                    resolve(); // ✅ Finish waiting
                                } catch (err) {
                                    console.error("Translation error:", err);
                                    reject(err);
                                }
    
                                return;
                            }
                        }
                    }
                }
            });

            this._mutationObserver = mutationObserver;

            this._translateTimeout = setTimeout(async () => {
                mutationObserver.disconnect();
                this._mutationObserver = null;
                this._translateTimeout = null;
                if (!mutationRun) {
                    try {
                        status = await this.startTranslate();
                        resolve();
                    } catch (err) {
                        reject(err);
                    }
                }
            }, 6000);
    
            mutationObserver.observe(container, {
                childList: true,
                subtree: true
            });
        });
    
        // ✅ This will now run AFTER translation finishes
        return status;
    }    

    startTranslate = async () => {
        await ModalStringScroll({ provider: 'google', prefix: this.prefix, postId: this.postId, lang: this.activeTargetLang, storeDispatch: this.storeDispatch, totalPosts: this.totalPosts, completedPostStatus: this.completedPostStatus, glossaryTerms: this.activeLanguageGlossaryTerms  });

        const endTime = new Date();
        const duration = endTime - this.startTime;
        const previousDuration = selectTranslatePostInfo(store.getState(), this.postId + '_' + this.activeTargetLang).duration || 0;

        this.storeDispatch(updateTranslatePostInfo({ [this.postId + '_' + this.activeTargetLang]: { duration: previousDuration + duration } }));

        return true;
    }

    filterLanguage = (lang) => {
        return mapToGoogleLanguageCode(lang, lmatBulkTranslationGlobal.languageObject || {});
    }

    // Function to initialize translation if conditions are met
    async initTranslation() {
        if (this.textContentObject && Object.keys(this.textContentObject).length > 0 && this.targetLangs && this.targetLangs.length > 0 && !this.stopTranslation) {
            const loaded = await this.appendTranslateWidget();
            if (!loaded) {
                this.targetLangs.forEach((lang) => {
                    if (this.stopTranslation) {
                        return;
                    }
                    this.storeDispatch(unsetPendingPost(`${this.postId}_${lang}`));
                    this.storeDispatch(
                        updateTranslatePostInfo({
                            [`${this.postId}_${lang}`]: {
                                status: 'error',
                                messageClass: 'error',
                                errorMessage: __(
                                    'Google Translate could not be loaded. Check your internet connection and try again.',
                                    'translate-words'
                                ),
                                errorHtml: false,
                            },
                        })
                    );
                    this.advanceProgress();
                });
                return;
            }
            await this.createGoogleTranslator(this.targetLangs[0], 0);
        } else if (this.targetLangs && this.targetLangs.length > 0 && !this.stopTranslation) {
            this.targetLangs.forEach((lang) => {
                if (this.stopTranslation) {
                    return;
                }
                this.storeDispatch(unsetPendingPost(this.postId + '_' + lang));
                this.storeDispatch(updateTranslatePostInfo({ [this.postId + '_' + lang]: { status: 'error', messageClass: 'error', errorMessage: __('No content to translate', 'translate-words'), errorHtml: false } }));
                this.advanceProgress();
            });
        }
    }
}

export default GoogleTranslater;