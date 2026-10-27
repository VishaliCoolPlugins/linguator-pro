import {updateTranslatedContent} from '../../redux-store/features/actions.js';
import updateTranslationCount from '../update-translation-count/index.js';
import normalizeBulkTranslationEscapes from '../../utils/normalize-bulk-translation-escapes.js';

const storeTranslateString=(postId, uniqueKey, key, value, provider, lang, storeDispatch)=>{
    updateTranslationCount({postId, key: uniqueKey, lang, storeDispatch});
    const normalized = typeof value === 'string' ? normalizeBulkTranslationEscapes(value) : value;
    storeDispatch(updateTranslatedContent({postId, uniqueKey, key, provider, value: normalized}));
}

export default storeTranslateString;
