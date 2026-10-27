import { select } from '@wordpress/data';

/**
 * Combine a modal/session abort signal with a per-request AbortController so either can cancel the fetch.
 *
 * @param {AbortSignal|undefined|null} parentSignal
 * @param {AbortController|undefined|null} childController
 * @returns {AbortSignal|undefined}
 */
export function mergeFetchAbortSignals(parentSignal, childController) {
    if (!childController) {
        return parentSignal || undefined;
    }
    const childSig = childController.signal;
    if (!parentSignal) {
        return childSig;
    }
    if (typeof AbortSignal.any === 'function') {
        try {
            return AbortSignal.any([parentSignal, childSig]);
        } catch (e) {
            // fall through
        }
    }
    if (parentSignal.aborted) {
        try {
            childController.abort();
        } catch (e) {
            /* noop */
        }
        return childSig;
    }
    const onParentAbort = () => {
        try {
            childController.abort();
        } catch (e) {
            /* noop */
        }
    };
    parentSignal.addEventListener('abort', onParentAbort, { once: true });
    const onChildDone = () => {
        parentSignal.removeEventListener('abort', onParentAbort);
    };
    childSig.addEventListener('abort', onChildDone, { once: true });
    return childSig;
}

export const updateTranslateData = ({ provider, sourceLang, targetLang, postId }) => {
    const translateData = select('block-lmatPageTranslation/translate').getTranslationInfo();
    const totalStringCount = translateData.translateData?.[provider]?.targetStringCount || 0;
    const totalWordCount = translateData.translateData?.[provider]?.targetWordCount || 0;
    const totalCharacterCount = translateData.translateData?.[provider]?.targetCharacterCount || 0;
    const timeTaken = translateData.translateData?.[provider]?.timeTaken || 0;
    const sourceWordCount = translateData?.sourceWordCount || 0;
    const sourceCharacterCount = translateData?.sourceCharacterCount || 0;
    const sourceStringCount = translateData?.sourceStringCount || 0;
    const editorType = lmatPageTranslationGlobal.editor_type;
    const date = new Date().toISOString();

    const data = { provider, totalStringCount, totalWordCount, totalCharacterCount, editorType, date, sourceStringCount, sourceWordCount, sourceCharacterCount, sourceLang, targetLang, timeTaken, action: lmatPageTranslationGlobal.update_translate_data, update_translation_key: lmatPageTranslationGlobal.update_translation_check, post_id: postId };

    fetch(lmatPageTranslationGlobal.ajax_url, {
        method: 'POST',
        headers: {
            'content-type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json',
        },
        body: new URLSearchParams(data)
    }).then().catch(error => {
        console.error(error);
    });
}