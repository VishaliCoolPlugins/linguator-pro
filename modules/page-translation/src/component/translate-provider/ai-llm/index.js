import { select, dispatch } from "@wordpress/data";
import SaveTranslation from "../../store-translated-string/index.js";
import StoreTimeTaken from "../../store-time-taken/index.js";
import { requestAiBatch, chunkStringMap, logAiTranslationError } from "../../../../../bulk-translation/src/components/translate-provider/ai-llm/api-client.js";
import { __, sprintf } from "@wordpress/i18n";
import AddProgressBar from "../../progress-bar/index.js";
import ShowStringCount from "../../progress-bar/show-string-count.js";

/**
 * @param {string} providerId gemini
 * @returns {(props: Object) => Promise<void>}
 */
export default function createAiLlmPageTranslator(providerId) {
    return async (props) => {
        const { sourceLang, targetLang, translateStatusHandler, ID, translateStatus, destroyUpdateHandler, modalRenderId, translationAbortSignal } = props;
        const AllowedMetaFields = select("block-lmatPageTranslation/translate").getAllowedMetaFields();

        const clearErrorNotice = () => {
            document.dispatchEvent(new CustomEvent("lmat-page-translation:translation-error-clear", { bubbles: true }));
        };

        const releaseRecoverableUpdateBlock = () => {
            document.dispatchEvent(
                new CustomEvent("lmat-page-translation:recoverable-ai-error-resolved", { bubbles: true })
            );
        };

        const showErrorNotice = (messageOrDetail) => {
            const detail =
                messageOrDetail && typeof messageOrDetail === "object"
                    ? messageOrDetail
                    : { message: String(messageOrDetail || "") };
            document.dispatchEvent(
                new CustomEvent("lmat-page-translation:translation-error", {
                    bubbles: true,
                    detail,
                })
            );
        };

        const escapeHtml = (text) =>
            String(text ?? "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;");

        const targetEl = ID ? document.getElementById(ID) : null;
        if (!targetEl) {
            return;
        }

        const buttonTextMap = {
            gemini: __("Translate with Gemini", "translate-words"),
        };

        // Render button (and avoid duplicating on re-renders)
        targetEl.innerHTML = "";
        const btn = document.createElement("button");
        btn.type = "button";
        // Reuse Chrome AI button classes for identical styling
        btn.className = "local_ai_translator_btn button button-primary";
        btn.textContent = buttonTextMap[providerId] || sprintf(__("Translate with %s", "translate-words"), providerId);
        if (translateStatus) {
            btn.disabled = true;
        }
        targetEl.appendChild(btn);

        const getBatchConfig = () => {
            const maxTokens = Number(lmatPageTranslationGlobal?.AIRequestMaxTokens);
            return {
                maxTokens: Number.isFinite(maxTokens) && maxTokens > 0 ? maxTokens : 500,
            };
        };

        const buildRecoverableHtml = (done, total, limitExceeded = false) => {
            const totalSafe = Math.max(1, total);
            const translateAgainLabel = limitExceeded
                ? __('Click "Translate" after updating your API key to try again.', "translate-words")
                : __('Click "Translate" to try again.', "translate-words");
            const translateAgainPartialLabel = limitExceeded
                ? __('Click "Translate" after updating your API key to re-translate the remaining strings.', "translate-words")
                : __('Click "Translate" to re-translate the remaining strings.', "translate-words");

            if (done <= 0) {
                const heading = limitExceeded
                    ? __("You’ve exceeded your current plan limit.", "translate-words")
                    : __("Oops! Something went wrong during translation", "translate-words");
                const intro = limitExceeded
                    ? __("To continue, please check your plan details and update your API key.", "translate-words")
                    : __("No strings were saved yet. You can retry the same step or stop here.", "translate-words");
                return `<div class="lmat_page_translation_ai_pending">
                <p class="lmat_page_translation_ai_pending_heading">${escapeHtml(heading)}</p>
                ${limitExceeded ? "" : `<p>${escapeHtml(__("To see more details, open your browser’s developer console.", "translate-words"))}</p>`}
                <p>${escapeHtml(intro)}</p>
                <p><strong>${escapeHtml(__("Next Steps:", "translate-words"))}</strong></p>
                <p>${escapeHtml(translateAgainLabel)}</p>
                <p><strong>${escapeHtml(__("OR", "translate-words"))}</strong></p>
                <p>${escapeHtml(__('Click "Continue" to close this message and keep the editor as it is.', "translate-words"))}</p>
            </div>`;
            }
            const completedPercent = Math.min(100, Math.round(((done / totalSafe) * 100) * 10) / 10).toFixed(1);
            const notCompletedPercent = Math.min(100, Math.round((100 - (done / total) * 100) * 10) / 10).toFixed(1);
            const heading = limitExceeded
                ? __("You’ve exceeded your current plan limit.", "translate-words")
                : __("Oops! Something went wrong during translation", "translate-words");
            const intro = limitExceeded
                ? __("To continue, please check your plan details and update your API key.", "translate-words")
                : "";
            return `<div class="lmat_page_translation_ai_pending">
                <p class="lmat_page_translation_ai_pending_heading">${escapeHtml(heading)}</p>
                ${intro ? `<p>${escapeHtml(intro)}</p>` : `<p>${escapeHtml(__("To see more details, open your browser’s developer console.", "translate-words"))}</p>`}
                <p>✅ ${escapeHtml(sprintf(__("You’ve translated %s of the strings.", "translate-words"), completedPercent + "%"))}</p>
                <p>❌ ${escapeHtml(sprintf(__("%s of the strings are still not translated.", "translate-words"), notCompletedPercent + "%"))}</p>
                <p><strong>${escapeHtml(__("Next Steps:", "translate-words"))}</strong></p>
                <p>${escapeHtml(translateAgainPartialLabel)}</p>
                <p><strong>${escapeHtml(__("OR", "translate-words"))}</strong></p>
                <p>${escapeHtml(__('Click "Continue" to proceed without translating the rest of the strings.', "translate-words"))}</p>
            </div>`;
        };

        const runTranslation = async () => {
            if (btn.disabled) {
                return;
            }

            btn.disabled = true;
            translateStatusHandler(true);

            const entries = select("block-lmatPageTranslation/translate").getTranslationEntries();
            const entryById = {};
            const strings = {};
            entries.forEach((row) => {
                if (!row.source || String(row.source).trim() === "") {
                    return;
                }
                entryById[row.id] = row;
                strings[row.id] = row.filteredString || row.source;
            });

            const stringContainer = jQuery("#lmat_page_translation_strings_model .modal-content .lmat_page_translation_string_container");

            /** Template uses `display:none` in CSS; it must be faded in after React mounts this node (including retries). */
            const mountPageTranslationProgressUi = () => {
                const pb = jQuery("#lmat_page_translation_strings_model .lmat_page_translation_translate_progress");
                const sc = jQuery("#lmat_page_translation_strings_model .modal-content .lmat_page_translation_string_container");
                if (!pb.length) {
                    return;
                }
                AddProgressBar(providerId);
                if (sc[0] && sc[0].scrollHeight > 100) {
                    pb.fadeIn("slow");
                } else {
                    pb.css({ display: "block", opacity: 1 });
                }
            };

            const scheduleMountPageTranslationProgressUi = () => {
                window.setTimeout(() => {
                    mountPageTranslationProgressUi();
                }, 0);
            };

            if (Object.keys(strings).length === 0) {
                clearErrorNotice();
                translateStatusHandler(false);
                btn.disabled = false;
                return;
            }
            scheduleMountPageTranslationProgressUi();

            const startTime = new Date().getTime();
            const restUrl = lmatPageTranslationGlobal.ai_batch_translate_url;
            const nonce = lmatPageTranslationGlobal.rest_nonce;
            const postId = lmatPageTranslationGlobal.current_post_id;
            const objectType = lmatPageTranslationGlobal.editor_type === "taxonomy" ? "term" : "post";

            /** When true, translation finished without error — keep translate button disabled. */
            let translationSucceeded = false;

            const finish = ({ hideProgress } = {}) => {
                const pb = jQuery("#lmat_page_translation_strings_model .lmat_page_translation_translate_progress");
                if (hideProgress) {
                    pb.fadeOut("slow");
                } else {
                    pb.show();
                }
                translateStatusHandler(false);
                if (!translationSucceeded) {
                    btn.disabled = false;
                } else {
                    btn.disabled = true;
                }
            };

            try {
                clearErrorNotice();
                btn.setAttribute("aria-busy", "true");
                const { maxTokens } = getBatchConfig();
                const chunks = chunkStringMap(strings, { maxTokens });
                const totalKeys = Math.max(1, Object.keys(strings).length);
                let chunkIndex = 0;
                let doneKeys = 0;
                let totalChars = 0;
                let totalStrings = 0;

                const modelKey = "gemini_model";
                const selectedModel =
                    lmatPageTranslationGlobal?.ai_models && lmatPageTranslationGlobal.ai_models[modelKey]
                        ? String(lmatPageTranslationGlobal.ai_models[modelKey])
                        : "";

                const updateProgressUi = () => {
                    const pct = Math.min(100, Math.round((doneKeys / totalKeys) * 100));
                    jQuery(`.${providerId}-translator_progress`).css("width", `${pct}%`).text(`${pct}%`);
                    const el = stringContainer && stringContainer[0] ? stringContainer[0] : null;
                    if (el && el.scrollHeight > el.clientHeight) {
                        const maxScroll = el.scrollHeight - el.clientHeight;
                        el.scrollTop = Math.round((pct / 100) * maxScroll);
                    }
                };

                const applyChunk = async (chunk) => {
                    const translations = await requestAiBatch({
                        provider: providerId,
                        postId,
                        objectType,
                        sourceLang,
                        targetLang,
                        strings: chunk,
                        model: selectedModel,
                        restUrl,
                        nonce,
                        signal: translationAbortSignal,
                    });

                    if (Object.keys(translations).length === 0 && Object.keys(chunk).length > 0) {
                        const emptyMsg = __("The AI returned an empty translation response. Please try again.", "translate-words");
                        logAiTranslationError(emptyMsg, { emptyResponse: true });
                        throw new Error(emptyMsg);
                    }

                    for (const key of Object.keys(chunk)) {
                        const row = entryById[key];
                        if (!row) continue;

                        const t = translations[key] !== undefined ? translations[key] : chunk[key];
                        SaveTranslation({
                            type: row.type,
                            key: row.id,
                            translateContent: t,
                            source: row.source,
                            provider: providerId,
                            AllowedMetaFields,
                        });
                        const src = row.source || "";
                        totalChars += src.trim().length;
                        totalStrings += src.trim().split(/(?<=[.!?]+)\s+/).filter(Boolean).length;
                        doneKeys++;
                    }
                    updateProgressUi();
                };

                const showGeminiRecoverableError = (limitExceeded) => {
                    showErrorNotice({
                        recoverable: true,
                        messagePlain: limitExceeded
                            ? __("You’ve exceeded your current plan limit.", "translate-words")
                            : __("Translation failed.", "translate-words"),
                        html: buildRecoverableHtml(doneKeys, totalKeys, limitExceeded),
                        onTranslateAgain: () => {
                            clearErrorNotice();
                            btn.disabled = true;
                            translateStatusHandler(true);
                            scheduleMountPageTranslationProgressUi();
                            btn.setAttribute("aria-busy", "true");
                            void (async () => {
                                const r = await runChunksFromCurrentIndex();
                                btn.removeAttribute("aria-busy");
                                if (r === "ok") {
                                    finishSuccess();
                                    releaseRecoverableUpdateBlock();
                                } else if (r !== "recoverable") {
                                    StoreTimeTaken({
                                        prefix: providerId,
                                        start: startTime,
                                        end: new Date().getTime(),
                                        translateStatus: false,
                                    });
                                    releaseRecoverableUpdateBlock();
                                }
                                translateStatusHandler(false);
                                if (!translationSucceeded) {
                                    btn.disabled = false;
                                }
                                if (translationSucceeded) {
                                    setTimeout(() => finish({ hideProgress: true }), 800);
                                } else {
                                    finish({ hideProgress: false });
                                }
                            })();
                        },
                        onContinue: () => {
                            releaseRecoverableUpdateBlock();
                            clearErrorNotice();
                            jQuery(`.${providerId}-translator_progress`)
                                .css("width", `${Math.min(100, Math.round((doneKeys / totalKeys) * 100))}%`)
                                .text(`${Math.min(100, Math.round((doneKeys / totalKeys) * 100))}%`);
                            dispatch("block-lmatPageTranslation/translate").translationInfo({
                                targetStringCount: totalStrings,
                                targetWordCount: entries.reduce((acc, row) => {
                                    const s = (row.source || "").trim();
                                    return acc + s.split(/\s+/).filter((word) => /[^\p{L}\p{N}]/.test(word)).length;
                                }, 0),
                                targetCharacterCount: totalChars,
                                translateStatus: doneKeys > 0,
                                provider: providerId,
                            });
                            ShowStringCount(providerId, "block", totalChars);
                            StoreTimeTaken({
                                prefix: providerId,
                                start: startTime,
                                end: new Date().getTime(),
                                translateStatus: doneKeys > 0,
                            });
                            translationSucceeded = doneKeys > 0;
                            translateStatusHandler(false);
                            btn.disabled = !translationSucceeded;
                            btn.removeAttribute("aria-busy");
                            if (translationSucceeded) {
                                setTimeout(() => finish({ hideProgress: true }), 800);
                            } else {
                                finish({ hideProgress: false });
                            }
                        },
                    });
                };

                const finishSuccess = () => {
                    dispatch("block-lmatPageTranslation/translate").translationInfo({
                        targetStringCount: totalStrings,
                        targetWordCount: entries.reduce((acc, row) => {
                            const s = (row.source || "").trim();
                            return acc + s.split(/\s+/).filter((word) => /[^\p{L}\p{N}]/.test(word)).length;
                        }, 0),
                        targetCharacterCount: totalChars,
                        translateStatus: true,
                        provider: providerId,
                    });

                    jQuery(`.${providerId}-translator_progress`).css("width", "100%").text("100%");
                    ShowStringCount(providerId, "block", totalChars);
                    StoreTimeTaken({ prefix: providerId, start: startTime, end: new Date().getTime(), translateStatus: true });
                    translationSucceeded = true;
                };

                const runChunksFromCurrentIndex = async () => {
                    while (chunkIndex < chunks.length) {
                        if (translationAbortSignal && translationAbortSignal.aborted) {
                            return "aborted";
                        }
                        const chunk = chunks[chunkIndex];
                        try {
                            // eslint-disable-next-line no-await-in-loop
                            await applyChunk(chunk);
                            chunkIndex += 1;
                        } catch (err) {
                            if (err && err.name === "AbortError") {
                                return "aborted";
                            }
                            const errorMessage = err?.message || __("Translation failed. Please try again.", "translate-words");
                            const lower = String(errorMessage || "").toLowerCase();
                            const isQuota =
                                err?.code === "LLM_QUOTA_EXCEEDED" ||
                                lower.includes("429") ||
                                lower.includes("quota") ||
                                lower.includes("rate limit") ||
                                lower.includes("resource has been exhausted");

                            const totalStringKeys = Object.keys(strings).length;
                            const canOfferGeminiRecovery =
                                providerId === "gemini" &&
                                totalStringKeys > 0 &&
                                chunks.length > 0 &&
                                (isQuota || chunkIndex < chunks.length);

                            if (canOfferGeminiRecovery) {
                                logAiTranslationError(errorMessage);
                                showGeminiRecoverableError(isQuota);
                                return "recoverable";
                            }

                            logAiTranslationError(errorMessage);
                            showErrorNotice(errorMessage);
                            return "fail";
                        }
                    }
                    return "ok";
                };

                const outcome = await runChunksFromCurrentIndex();
                btn.removeAttribute("aria-busy");

                if (outcome === "aborted") {
                    releaseRecoverableUpdateBlock();
                    clearErrorNotice();
                    translateStatusHandler(false);
                    btn.disabled = false;
                    finish({ hideProgress: false });
                    return;
                }

                if (outcome === "ok") {
                    finishSuccess();
                    releaseRecoverableUpdateBlock();
                } else if (outcome === "recoverable") {
                    StoreTimeTaken({ prefix: providerId, start: startTime, end: new Date().getTime(), translateStatus: false });
                } else {
                    StoreTimeTaken({ prefix: providerId, start: startTime, end: new Date().getTime(), translateStatus: false });
                    releaseRecoverableUpdateBlock();
                }
            } catch (e) {
                if (e && e.name === "AbortError") {
                    releaseRecoverableUpdateBlock();
                    clearErrorNotice();
                    translateStatusHandler(false);
                    btn.disabled = false;
                    finish({ hideProgress: false });
                    return;
                }
                StoreTimeTaken({ prefix: providerId, start: startTime, end: new Date().getTime(), translateStatus: false });
                const errorMessage = e?.message || __("Translation failed. Please try again.", "translate-words");
                const lower = String(errorMessage || "").toLowerCase();
                const isQuota =
                    e?.code === "LLM_QUOTA_EXCEEDED" ||
                    lower.includes("429") ||
                    lower.includes("quota") ||
                    lower.includes("rate limit") ||
                    lower.includes("resource has been exhausted");
                const totalStringKeys = Object.keys(strings).length;

                logAiTranslationError(errorMessage);
                if (providerId === "gemini" && isQuota && totalStringKeys > 0) {
                    showGeminiRecoverableError(true);
                } else {
                    showErrorNotice(errorMessage);
                    releaseRecoverableUpdateBlock();
                }
            }

            // UX: hide progress only when translation succeeds.
            if (translationSucceeded) {
                setTimeout(() => finish({ hideProgress: true }), 800);
            } else {
                finish({ hideProgress: false });
            }
        };

        btn.addEventListener("click", runTranslation);

        // If translation is already in progress (e.g. re-render), keep button disabled.
        if (translateStatus) {
            btn.disabled = true;
        }

        if (typeof destroyUpdateHandler === "function") {
            destroyUpdateHandler(() => {
                btn.removeEventListener("click", runTranslation);
                if (targetEl) {
                    targetEl.innerHTML = "";
                }
                clearErrorNotice();
            });
        }
    };
}
