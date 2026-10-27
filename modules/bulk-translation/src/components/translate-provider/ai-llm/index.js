import { updateProgressStatus, updateTranslatePostInfo, unsetPendingPost } from "../../../redux-store/features/actions.js";
import { selectProgressStatus, selectTargetContent, selectTranslatePostInfo } from "../../../redux-store/features/selectors.js";
import { store } from "../../../redux-store/store.js";
import storeTranslateString from "../../store-translate-strings/index.js";
import { __, sprintf } from "@wordpress/i18n";
import { requestAiBatch, chunkStringMap, logAiTranslationError } from "./api-client.js";

class AiLlmBulkTranslator {
    constructor({
        sourceLang = "en",
        targetLangs = false,
        updateContent,
        totalPosts,
        storeDispatch,
        postId,
        prefix,
        updateDestoryHandler,
        createTranslatePostNonce = "",
        previousCompletedStrings = 0,
        abortSignal,
    }) {
        this.textContentObject = selectTargetContent(store.getState(), postId);
        this.totalSourceKeys = Math.max(0, Object.keys(this.textContentObject || {}).length);
        this.sourceLang = sourceLang;
        this.targetLangs = targetLangs;
        this.updateContent = updateContent;
        this.totalPosts = Math.max(1, totalPosts);
        this.storeDispatch = storeDispatch;
        this.postId = postId;
        this.prefix = prefix;
        this.serviceProvider = store.getState().serviceProvider;
        this.stopTranslation = false;
        this.abortSignal = abortSignal;
        this.completedPostStatus = 0;
        this.createTranslatePostNonce = createTranslatePostNonce;
        this.previousCompletedStrings = typeof previousCompletedStrings === "number" ? previousCompletedStrings : 0;
        this.progressSliceTarget = 100 / this.totalPosts;
        this.progressContributedThisJob = 0;
        this.lastSyncedProgressPercent = 0;
        updateDestoryHandler(() => {
            this.destroy();
        });
    }

    destroy = () => {
        this.stopTranslation = true;
    };

    isCancelled = () => this.stopTranslation || (this.abortSignal && this.abortSignal.aborted);

    getObjectType = () => {
        const info = store.getState().parentPostsInfo[this.postId];
        return info && info.editorType === "taxonomy" ? "term" : "post";
    };

    getRestUrl = () => {
        const base = (lmatBulkTranslationGlobal.bulkTranslateRouteUrl || "").replace(/\/$/, "");
        return `${base}/ai-translate-batch`;
    };

    /**
     * Keys still missing a Gemini translation for this target language.
     * @param {string} targetLang
     * @returns {Record<string,string>}
     */
    getRemainingStringsMap(targetLang) {
        const base = this.textContentObject || {};
        const out = {};
        const tc = store.getState().translatedContent[this.postId] || {};
        const provider = this.serviceProvider;
        Object.keys(base).forEach((k) => {
            const tr = tc[k]?.translation?.[provider]?.[targetLang];
            if (tr === undefined || tr === null || String(tr).trim() === "") {
                out[k] = base[k];
            }
        });
        return out;
    }

    updateProgressForChunk = (completedKeys, targetLang) => {
        if (this.totalSourceKeys < 1) {
            return;
        }
        const completedPercentage = Math.min(100, (completedKeys / this.totalSourceKeys) * 100);
        const progressBarCircular = document.querySelector(`.${this.prefix}-progress-bar-circular[data-id="${this.postId}_${targetLang}"]`);
        if (progressBarCircular) {
            const rounded = Math.min(100, Math.round(completedPercentage));
            progressBarCircular.querySelector(`.${this.prefix}-percentage`).innerHTML = `${rounded}%`;
            progressBarCircular.querySelector(`.${this.prefix}-progress`).style.strokeDasharray = `${rounded}, 100`;
        }
        const totalProgressBar = document.querySelector(`.${this.prefix}-overall-progress .${this.prefix}-progress`);
        if (totalProgressBar) {
            const totalProgress = Math.min(100, this.completedPostStatus + completedPercentage / this.totalPosts);
            totalProgressBar.style.width = `${totalProgress.toFixed(2)}%`;
            totalProgressBar.innerHTML = `${totalProgress.toFixed(2)}%`;
        }
    };

    /**
     * Update the progress bar for the target language.
     * @param {number} mergedDone previousCompletedStrings + keys done this run
     * @param {string} targetLang
     */
    addReduxProgressForCompletedCount(mergedDone, targetLang) {
        const total = Math.max(1, this.totalSourceKeys);
        const newPct = Math.min(100, (mergedDone / total) * 100);
        const prevPct =
            typeof this.lastSyncedProgressPercent === "number"
                ? this.lastSyncedProgressPercent
                : (this.previousCompletedStrings / total) * 100;
        const delta = ((newPct - prevPct) / 100) * this.progressSliceTarget;
        if (delta > 0.0001) {
            this.storeDispatch(updateProgressStatus(delta));
            this.progressContributedThisJob += delta;
        }
        this.lastSyncedProgressPercent = newPct;
        this.updateProgressForChunk(mergedDone, targetLang);
    }

    finalizeProgressSliceRedux() {
        const rem = this.progressSliceTarget - this.progressContributedThisJob;
        if (rem > 0.0001) {
            this.storeDispatch(updateProgressStatus(rem));
            this.progressContributedThisJob += rem;
        }
    }

    getBatchConfig = () => {
        const maxTokens = Number(lmatBulkTranslationGlobal?.AIRequestMaxTokens);
        const batchSize = Number(lmatBulkTranslationGlobal?.AIRequestBatchSize);
        return {
            maxTokens: Number.isFinite(maxTokens) && maxTokens > 0 ? maxTokens : 500,
            concurrency: Number.isFinite(batchSize) && batchSize > 0 ? Math.min(10, Math.max(1, batchSize)) : 5,
        };
    };

    runWithConcurrency = async (items, concurrency, handler) => {
        const limit = Math.min(Math.max(1, concurrency), Math.max(1, items.length));
        let idx = 0;
        let firstError = null;

        const worker = async () => {
            while (idx < items.length) {
                if (firstError || this.isCancelled()) {
                    return;
                }
                const currentIndex = idx++;
                try {
                    // eslint-disable-next-line no-await-in-loop
                    await handler(items[currentIndex], currentIndex);
                } catch (e) {
                    if (e && !e.failedChunk) {
                        e.failedChunk = items[currentIndex];
                    }
                    firstError = e;
                    return;
                }
            }
        };

        await Promise.all(Array.from({ length: limit }, () => worker()));
        if (firstError) throw firstError;
    };

    buildRecoverableErrorHtml(mergedDone, totalKeys, limitExceeded) {
        const total = Math.max(1, totalKeys);
        const completedPercent = Math.min(100, Math.round(((mergedDone / total) * 100) * 10) / 10).toFixed(1);
        const notCompletedPercent = Math.min(100, Math.round((100 - (mergedDone / total) * 100) * 10) / 10).toFixed(1);

        let errorMessage = "";
        let translateBtnMessage = "";
        if (limitExceeded) {
            errorMessage =
                `<p class="${this.prefix}-ai-pending-request-heading">` +
                __("You’ve exceeded your current plan limit.", "translate-words") +
                "</p> " +
                __("To continue, please check your plan details and update your API key.", "translate-words");
            translateBtnMessage = __(
                'Click "Translate" after updating your API key to re-translate the remaining strings.',
                "translate-words"
            );
        } else {
            errorMessage =
                `<p class="${this.prefix}-ai-pending-request-heading">` + __("Oops! Something went wrong during translation", "translate-words") + "</p>";
            translateBtnMessage = __('Click "Translate" to re-translate the remaining strings.', "translate-words");
        }

        return `<div class="${this.prefix}-ai-pending-request">
                    <div>${errorMessage}</div>
                    <p>${__("To see more details, open your browser’s developer console.", "translate-words")}</p>
                <p>✅ ${sprintf(__("You’ve translated %s of the strings.", "translate-words"), completedPercent + "%")}</p>
                <p>❌ ${sprintf(__("%s of the strings are still not translated.", "translate-words"), notCompletedPercent + "%")}</p>
                <p><strong>${__("Next Steps:", "translate-words")}</strong></p>
                <p>${translateBtnMessage}</p>
                <p><strong>${__("OR", "translate-words")}</strong></p>
                <p>${__('Click "Continue" to proceed without translating the rest of the strings.', "translate-words")}</p>
                </div>`;
    }

    dispatchRecoverableError(targetLang, mergedDone, message, limitExceeded = false) {
        logAiTranslationError(message || __("Translation failed.", "translate-words"));
        const infoKey = `${this.postId}_${targetLang}`;
        const existing = store.getState().translatePostInfo[infoKey] || {};
        const pendingHtml = this.buildRecoverableErrorHtml(mergedDone, this.totalSourceKeys, limitExceeded);
        this.storeDispatch(
            updateTranslatePostInfo({
                [infoKey]: {
                    ...existing,
                    parentPostId: this.postId,
                    targetLanguage: targetLang,
                    status: "error",
                    messageClass: "error",
                    errorMessage: limitExceeded ? "" : __("Translation failed.", "translate-words"),
                    errorHtml: pendingHtml,
                    errorAllowHtml: false,
                    aiError: true,
                    quotaRecoverable: limitExceeded,
                    nonce: this.createTranslatePostNonce,
                    completedStrings: mergedDone,
                    totalPosts: this.totalPosts,
                },
            })
        );
    }

    /**
     * Run Gemini chunk requests for one target language.
     * @param {string} targetLang
     * @returns {Promise<boolean>} true if strings translated and post save can run
     */
    async runChunksForTargetLanguage(targetLang) {
        if (this.isCancelled() || window.lmatBulkTranslationQuotaExceeded) {
            return false;
        }

        this.completedPostStatus = selectProgressStatus(store.getState());
        this.progressContributedThisJob = 0;
        const total = Math.max(1, this.totalSourceKeys);
        this.lastSyncedProgressPercent = (this.previousCompletedStrings / total) * 100;

        if (!this.textContentObject || this.totalSourceKeys === 0) {
            this.storeDispatch(unsetPendingPost(`${this.postId}_${targetLang}`));
            this.finalizeProgressSliceRedux();
            this.storeDispatch(
                updateTranslatePostInfo({
                    [`${this.postId}_${targetLang}`]: {
                        status: "error",
                        messageClass: "error",
                        errorMessage: __("No content to translate", "translate-words"),
                        errorHtml: false,
                        aiError: false,
                    },
                })
            );
            return false;
        }

        this.storeDispatch(updateTranslatePostInfo({ [`${this.postId}_${targetLang}`]: { status: "running", messageClass: "" } }));
        const startTime = new Date();

        const stringsToTranslate = this.getRemainingStringsMap(targetLang);
        if (Object.keys(stringsToTranslate).length === 0) {
            if (!this.isCancelled()) {
                this.finalizeProgressSliceRedux();
                const duration = new Date() - startTime;
                const tInfo = selectTranslatePostInfo(store.getState());
                const previousDuration = (tInfo && tInfo[`${this.postId}_${targetLang}`] && tInfo[`${this.postId}_${targetLang}`].duration) || 0;
                this.storeDispatch(
                    updateTranslatePostInfo({
                        [`${this.postId}_${targetLang}`]: { duration: previousDuration + duration },
                    })
                );
            }
            return true;
        }

        try {
            const { maxTokens, concurrency } = this.getBatchConfig();
            const chunks = chunkStringMap(stringsToTranslate, { maxTokens });
            const modelKey = "gemini_model";
            const selectedModel =
                lmatBulkTranslationGlobal?.ai_models && lmatBulkTranslationGlobal.ai_models[modelKey]
                    ? String(lmatBulkTranslationGlobal.ai_models[modelKey])
                    : "";
            let doneThisRun = 0;

            await this.runWithConcurrency(chunks, concurrency, async (chunk) => {
                if (this.isCancelled() || window.lmatBulkTranslationQuotaExceeded) {
                    return;
                }

                const translations = await requestAiBatch({
                    provider: this.serviceProvider,
                    postId: this.postId,
                    objectType: this.getObjectType(),
                    sourceLang: this.sourceLang,
                    targetLang,
                    strings: chunk,
                    model: selectedModel,
                    restUrl: this.getRestUrl(),
                    nonce: lmatBulkTranslationGlobal.nonce,
                    signal: this.abortSignal,
                });
                if (Object.keys(translations).length === 0 && Object.keys(chunk).length > 0) {
                    const emptyMsg = __("The AI returned an empty translation response. Please try again.", "translate-words");
                    logAiTranslationError(emptyMsg, { emptyResponse: true });
                    const emptyErr = new Error(emptyMsg);
                    emptyErr.failedChunk = chunk;
                    throw emptyErr;
                }
                for (const key of Object.keys(chunk)) {
                    const value = translations[key] !== undefined ? translations[key] : chunk[key];
                    storeTranslateString(this.postId, key, targetLang, value, this.serviceProvider, targetLang, this.storeDispatch);
                    doneThisRun++;
                    const merged = this.previousCompletedStrings + doneThisRun;
                    this.addReduxProgressForCompletedCount(merged, targetLang);
                }
            });

            if (!this.isCancelled()) {
                this.finalizeProgressSliceRedux();
                const duration = new Date() - startTime;
                const tInfo = selectTranslatePostInfo(store.getState());
                const previousDuration = (tInfo && tInfo[`${this.postId}_${targetLang}`] && tInfo[`${this.postId}_${targetLang}`].duration) || 0;
                this.storeDispatch(
                    updateTranslatePostInfo({
                        [`${this.postId}_${targetLang}`]: { duration: previousDuration + duration },
                    })
                );
            }
            return true;
        } catch (err) {
            if (err && err.name === "AbortError") {
                return false;
            }
            const msg = err && err.message ? err.message : __("Translation failed.", "translate-words");
            const isQuotaError = err?.code === "LLM_QUOTA_EXCEEDED";
            const isGeminiQuotaByMessage =
                this.serviceProvider === "gemini" &&
                /429|quota exceeded|rate limit|resource has been exhausted|too many requests/i.test(String(msg));
            const haltForQuota = isQuotaError || isGeminiQuotaByMessage;

            const mergedFromStore = Object.keys(this.textContentObject).filter((k) => {
                const tr = store.getState().translatedContent[this.postId]?.[k]?.translation?.[this.serviceProvider]?.[targetLang];
                return tr !== undefined && tr !== null && String(tr).trim() !== "";
            }).length;

            if (haltForQuota) {
                logAiTranslationError(msg);
                this.stopTranslation = true;
                window.lmatBulkTranslationQuotaExceeded = true;
                const rem = Math.max(0, this.progressSliceTarget - this.progressContributedThisJob);
                if (rem > 0.0001) {
                    this.storeDispatch(updateProgressStatus(rem));
                    this.progressContributedThisJob += rem;
                }
                this.dispatchRecoverableError(targetLang, mergedFromStore, msg, true);
                return false;
            }

            this.dispatchRecoverableError(targetLang, mergedFromStore, msg);
            return false;
        }
    }

    async initTranslation() {
        if (this.textContentObject && this.totalSourceKeys > 0 && this.targetLangs && this.targetLangs.length > 0 && !this.isCancelled()) {
            const langs = [...this.targetLangs];
            for (let i = 0; i < langs.length; i++) {
                if (this.isCancelled() || window.lmatBulkTranslationQuotaExceeded) {
                    break;
                }
                const lang = langs[i];
                // eslint-disable-next-line no-await-in-loop
                const ok = await this.runChunksForTargetLanguage(lang);
                if (!this.isCancelled() && !window.lmatBulkTranslationQuotaExceeded && ok) {
                    // eslint-disable-next-line no-await-in-loop
                    await this.updateContent(lang);
                }
            }
        } else if (this.targetLangs && this.targetLangs.length > 0 && !this.isCancelled()) {
            this.targetLangs.forEach((lang) => {
                this.storeDispatch(unsetPendingPost(`${this.postId}_${lang}`));
                this.storeDispatch(updateProgressStatus(100 / this.totalPosts));
                this.storeDispatch(
                    updateTranslatePostInfo({
                        [`${this.postId}_${lang}`]: {
                            status: "error",
                            messageClass: "error",
                            errorMessage: __("No content to translate", "translate-words"),
                            errorHtml: false,
                            aiError: false,
                        },
                    })
                );
            });
        }
    }
}

export default AiLlmBulkTranslator;
