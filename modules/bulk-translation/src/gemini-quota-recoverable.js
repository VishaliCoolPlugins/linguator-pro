/**
 * Shared quota-exceeded row + modal content for Gemini bulk translation.
 */
import { store } from "./redux-store/store.js";
import { selectTargetContent } from "./redux-store/features/selectors.js";
import { __, sprintf } from "@wordpress/i18n";

function escapeHtml(text) {
    return String(text ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

/**
 * @param {number|string} postId
 * @param {string} targetLang
 * @param {string} [serviceProvider]
 */
export function countMergedTranslatedStrings(postId, targetLang, serviceProvider = "gemini") {
    const textContentObject = selectTargetContent(store.getState(), postId) || {};
    return Object.keys(textContentObject).filter((k) => {
        const tr = store.getState().translatedContent[postId]?.[k]?.translation?.[serviceProvider]?.[targetLang];
        return tr !== undefined && tr !== null && String(tr).trim() !== "";
    }).length;
}

/**
 * HTML for the Error Details modal when API quota is exceeded.
 */
export function buildQuotaRecoverableErrorHtml(prefix, mergedDone, totalKeys) {
    const total = Math.max(1, totalKeys);
    const completedPercent = Math.min(100, Math.round(((mergedDone / total) * 100) * 10) / 10).toFixed(1);
    const notCompletedPercent = Math.min(100, Math.round((100 - (mergedDone / total) * 100) * 10) / 10).toFixed(1);

    const safePrefix = escapeHtml(prefix);
    const errorMessage =
        `<p class="${safePrefix}-ai-pending-request-heading">` +
        escapeHtml(__("You’ve exceeded your current plan limit.", "translate-words")) +
        "</p> " +
        escapeHtml(__("To continue, please check your plan details and update your API key.", "translate-words"));
    const translateBtnMessage = escapeHtml(
        __('Click "Translate" after updating your API key to re-translate the remaining strings.', "translate-words")
    );
    const translatedLine = escapeHtml(
        sprintf(__("You’ve translated %s of the strings.", "translate-words"), completedPercent + "%")
    );
    const notTranslatedLine = escapeHtml(
        sprintf(__("%s of the strings are still not translated.", "translate-words"), notCompletedPercent + "%")
    );

    return `<div class="${safePrefix}-ai-pending-request">
                    <div>${errorMessage}</div>
                    <p>${escapeHtml(__("To see more details, open your browser’s developer console.", "translate-words"))}</p>
                <p>✅ ${translatedLine}</p>
                <p>❌ ${notTranslatedLine}</p>
                <p><strong>${escapeHtml(__("Next Steps:", "translate-words"))}</strong></p>
                <p>${translateBtnMessage}</p>
                <p><strong>${escapeHtml(__("OR", "translate-words"))}</strong></p>
                <p>${escapeHtml(__('Click "Continue" to proceed without translating the rest of the strings.', "translate-words"))}</p>
                </div>`;
}

/**
 * Redux row state: Error Details button only in the table; full message in the modal.
 */
export function buildQuotaRecoverableTranslateInfo({
    existingInfo = {},
    prefix,
    postId,
    targetLang,
    mergedDone,
    totalKeys,
    nonce,
    totalPosts,
}) {
    return {
        ...existingInfo,
        parentPostId: postId,
        targetLanguage: targetLang,
        status: "error",
        messageClass: "error",
        errorMessage: "",
        errorHtml: buildQuotaRecoverableErrorHtml(prefix, mergedDone, totalKeys),
        errorAllowHtml: false,
        aiError: true,
        quotaRecoverable: true,
        nonce,
        completedStrings: mergedDone,
        totalPosts,
    };
}
