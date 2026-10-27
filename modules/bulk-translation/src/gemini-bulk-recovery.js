/**
 * Gemini bulk translation recovery.
 */
import { store } from "./redux-store/store.js";
import { selectTargetContent } from "./redux-store/features/selectors.js";
import { updateProgressStatus, updateTranslatePostInfo } from "./redux-store/features/actions.js";
import AiLlmBulkTranslator from "./components/translate-provider/ai-llm/index.js";
import { updateContent as updateContentBulkTranslate } from "./bulk-translate.js";

/**
 * @param {Object} args
 * @param {number|string} args.postId
 * @param {string} args.targetLang
 * @param {Function} args.storeDispatch
 * @param {string} args.prefix
 * @param {Function} args.updateDestoryHandler
 * @param {string} args.nonce
 * @param {Function} args.closeErrorModal
 * @param {number} args.completedStrings
 * @param {number} args.totalPosts
 */
export async function geminiTranslateAgain({ postId, targetLang, storeDispatch, prefix, updateDestoryHandler, nonce, closeErrorModal, completedStrings, totalPosts }) {
    const postContent = store.getState().parentPostsInfo[postId];
    if (!postContent) {
        return;
    }

    const {
        originalContent: { title, content, post_name, excerpt, metaFields },
        editorType,
        sourceLanguage,
    } = postContent;

    const source = {
        title,
        content: JSON.parse(JSON.stringify(content)),
        post_name,
        excerpt,
        metaFields: metaFields && Object.keys(metaFields).length > 0 ? JSON.parse(JSON.stringify(metaFields)) : {},
    };

    const updateContent = async (lang) => {
        await updateContentBulkTranslate({ source, postId, sourceLang: sourceLanguage, lang, editorType, createTranslatePostNonce: nonce, storeDispatch });
    };

    window.lmatBulkTranslationQuotaExceeded = false;

    if (typeof closeErrorModal === "function") {
        closeErrorModal();
    }

    await new Promise((resolve) => setTimeout(resolve, 500));

    const translator = new AiLlmBulkTranslator({
        sourceLang: sourceLanguage,
        targetLangs: [targetLang],
        totalPosts,
        storeDispatch,
        postId,
        prefix,
        updateContent,
        createTranslatePostNonce: nonce,
        updateDestoryHandler,
        previousCompletedStrings: typeof completedStrings === "number" ? completedStrings : 0,
    });

    await translator.initTranslation();
}

/**
 * Save partial translations and skip remaining strings.
 */
export async function geminiTranslateComplete({ postId, targetLang, storeDispatch, nonce, closeErrorModal, completedStrings, totalPosts }) {
    const postContent = store.getState().parentPostsInfo[postId];
    if (!postContent) {
        return;
    }

    const {
        originalContent: { title, content, post_name, excerpt, metaFields },
        editorType,
        sourceLanguage,
    } = postContent;

    const source = {
        title,
        content: JSON.parse(JSON.stringify(content)),
        post_name,
        excerpt,
        metaFields: metaFields && Object.keys(metaFields).length > 0 ? JSON.parse(JSON.stringify(metaFields)) : {},
    };

    const updateContent = async (lang) => {
        await updateContentBulkTranslate({ source, postId, sourceLang: sourceLanguage, lang, editorType, createTranslatePostNonce: nonce, storeDispatch });
    };

    const slice = totalPosts > 0 ? 100 / totalPosts : 0;
    const totalKeys = Object.keys(selectTargetContent(store.getState(), postId) || {}).length;
    const prev = Math.min(typeof completedStrings === "number" ? completedStrings : 0, Math.max(0, totalKeys));
    const remainingFrac = totalKeys > 0 ? (totalKeys - prev) / totalKeys : 0;
    if (remainingFrac > 0 && slice > 0) {
        storeDispatch(updateProgressStatus(remainingFrac * slice));
    }

    const key = `${postId}_${targetLang}`;
    const existing = store.getState().translatePostInfo[key] || {};
    storeDispatch(
        updateTranslatePostInfo({
            [key]: {
                ...existing,
                status: "in-progress",
                messageClass: "in-progress",
                errorHtml: false,
                aiError: false,
            },
        })
    );

    window.lmatBulkTranslationQuotaExceeded = false;

    if (typeof closeErrorModal === "function") {
        closeErrorModal();
    }

    await new Promise((resolve) => setTimeout(resolve, 400));
    await updateContent(targetLang);
}
