import {filterContent, updateFilterContent} from './components/filter-content/index.js';
import { updatePendingPosts, unsetPendingPost, updateCompletedPosts, updateTranslatePostInfo, updateCountInfo, updateSourceContent, updateParentPostsInfo, updateTargetContent, updateTargetLanguages, updateBlockParseRules, updateProgressStatus, updateAllowedMetaFields, updateErrorPostsInfo, updateGlossaryTerms } from './redux-store/features/actions.js';
import { store } from './redux-store/store.js';
import { __,sprintf } from '@wordpress/i18n';
import Provider from './components/translate-provider/index.js';
import { updateTranslateData } from './helper/index.js';
import LoopCallback from './components/loop-callback/index.js';
import updateGlossaryString from './components/filter-content/update-glossary-string/index.js';
import { selectGlossaryTerms, selectTargetContent } from './redux-store/features/selectors.js';
import { buildQuotaRecoverableTranslateInfo, countMergedTranslatedStrings } from './gemini-quota-recoverable.js';
import { buildMediaPayload, storeMediaStrings } from './components/media-translation/index.js';

const initBulkTranslate=async (postKeys=[], nonce, storeDispatch, prefix, updateDestoryHandler, signal)=>{
    window.lmatBulkTranslationQuotaExceeded = false;

    const pendingPosts=store.getState().pendingPosts;
    const totalPendingCount = Math.max(1, pendingPosts.length);

    if(pendingPosts.length < 1){
        return;
    }

    const cancelled = () => Boolean(signal && signal.aborted);

    const markRemainingPendingAsQuotaError = () => {
        if (!window.lmatBulkTranslationQuotaExceeded) {
            return;
        }

        const pendingNow = [...store.getState().pendingPosts];
        const serviceProvider = store.getState().serviceProvider || 'gemini';
        const totalPosts = Math.max(1, store.getState().countInfo.totalPosts || totalPendingCount);

        pendingNow.forEach((pendingKey) => {
            const existingInfo = store.getState().translatePostInfo[pendingKey] || {};
            const postId = existingInfo.parentPostId;
            const targetLang = existingInfo.targetLanguage;
            if (!postId || !targetLang) {
                return;
            }

            const totalKeys = Object.keys(selectTargetContent(store.getState(), postId) || {}).length;
            const mergedDone = countMergedTranslatedStrings(postId, targetLang, serviceProvider);

            storeDispatch(unsetPendingPost(pendingKey));
            storeDispatch(updateCompletedPosts([pendingKey]));
            storeDispatch(updateProgressStatus(100 / totalPendingCount));
            storeDispatch(
                updateTranslatePostInfo({
                    [pendingKey]: buildQuotaRecoverableTranslateInfo({
                        existingInfo,
                        prefix,
                        postId,
                        targetLang,
                        mergedDone,
                        totalKeys,
                        nonce,
                        totalPosts,
                    }),
                })
            );
        });
    };

    const translatePost=async (index) => {
        const postId=postKeys[index];
    
        if(window.lmatBulkTranslationQuotaExceeded){
            markRemainingPendingAsQuotaError();
            return;
        }

        if(!postId || cancelled()){
            return;
        }
    
        const postContent=store.getState().parentPostsInfo[postId];
     
        if(postContent){
            const {originalContent, languages, editorType, sourceLanguage} = postContent;
            
            if(!languages || languages.length === 0){
                console.log(`All target languages for post ${postId} already exist. Skipping translation.`);
                return;
            }
        
            if(!['classic', 'block', 'elementor', 'taxonomy'].includes(editorType)){
                for(const lang of languages){   
                    storeDispatch(unsetPendingPost(postId+'_'+lang));
                    storeDispatch(updateProgressStatus(100 / pendingPosts.length));
                    storeDispatch(updateTranslatePostInfo({[postId+'_'+lang]: {status: 'error', messageClass: 'error', errorMessage: __('This post editor type is not supported for translation', 'linguator-pro')}}));
                }
            }

            // Deep clone the content object to avoid mutating the original reference
            const source = JSON.parse( JSON.stringify( originalContent ) );
            source.content = source.content || {};
            source.metaFields = source.metaFields || {};

             await translateContent({sourceLang: sourceLanguage, targetLangs: languages, totalPosts: pendingPosts.length,storeDispatch,prefix, postId, source, editorType, createTranslatePostNonce: nonce, updateDestoryHandler, signal});
        }
    
        index++;
    
        if(window.lmatBulkTranslationQuotaExceeded){
            markRemainingPendingAsQuotaError();
            return;
        }

        if(index > postKeys.length-1 || cancelled()){
            return;
        }

        await translatePost(index);
    }

    await translatePost(0);
}

export const translateContent=async ({sourceLang, targetLangs, totalPosts, storeDispatch, postId, prefix, source, editorType, createTranslatePostNonce, updateDestoryHandler, signal})=>{

    const activeProvider=store.getState().serviceProvider;
    const providerDetails=Provider({Service: activeProvider});

    if(providerDetails && providerDetails.Provider){

        const updateContentCallback=async (lang)=>
            { await updateContent({source, postId, sourceLang, lang, editorType, createTranslatePostNonce , storeDispatch, signal})};

        const data={sourceLang, targetLangs, totalPosts,storeDispatch, postId, createTranslatePostNonce, updateContent: updateContentCallback, prefix, updateDestoryHandler, abortSignal: signal};

        const provider=new providerDetails.Provider(data);

        await provider.initTranslation();
    }
}

export const updateContent=async ({source, postId, sourceLang, lang, editorType, createTranslatePostNonce, storeDispatch, signal})=>{

    const service=store.getState().serviceProvider;

    const deepCloneSource=JSON.parse(JSON.stringify(source));

    const updateContent=await updateFilterContent({source: deepCloneSource,postId, lang, editorType, service});
    const mediaPayload = buildMediaPayload( { postId, source, language: lang, provider: service } );

    const bulkTranslateRouteUrl = lmatBulkTranslationGlobal.bulkTranslateRouteUrl;
    const nonce = lmatBulkTranslationGlobal.nonce;

    storeDispatch(updateTranslatePostInfo({[postId+'_'+lang]: { status: 'in-progress', messageClass: 'in-progress'}}));

    if (signal && signal.aborted) {
        return;
    }

    let endPoint='create-translate-post';

    let body={
        target_language: lang,
        editor_type: editorType,
        privateKey: createTranslatePostNonce,
        source_language: sourceLang,
    }

    if(editorType === 'taxonomy'){
        endPoint='create-translate-taxonomy';
        body.term_id=postId;
        body.taxonomy_name=updateContent.title || '';
        body.taxonomy_description=updateContent.content || '';
        body.taxonomy=lmatBulkTranslationGlobal.taxonomy_page;

        if(updateContent.post_name && updateContent.post_name.trim() !== ''){
            body.taxonomy_slug=updateContent.post_name;
        }   
    }else{
        body.post_id=postId;
        body.post_title= updateContent.title || '';
        body.post_name= updateContent.post_name || '';
        body.post_content=updateContent.content ? JSON.stringify(updateContent.content) : '';
        body.post_meta_fields=updateContent.metaFields ? JSON.stringify(updateContent.metaFields) : '';
        body.post_excerpt=updateContent.excerpt || '';

		if ( mediaPayload.featured_image ) {
			body.featured_image = JSON.stringify( mediaPayload.featured_image );
		}
		if ( mediaPayload.content_media ) {
			body.content_media = JSON.stringify( mediaPayload.content_media );
		}
		if ( mediaPayload.elementor_media ) {
			body.elementor_media = JSON.stringify( mediaPayload.elementor_media );
		}
    }
    
    await fetch(bulkTranslateRouteUrl + `/${postId}:${endPoint}`, {
        method: 'POST',
        body: new URLSearchParams(body),
        headers: {
            'X-WP-Nonce': nonce,
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json',
        },
        ...(signal ? { signal } : {}),
    }).then(async response=>{
        // Some server/proxy errors return HTML (e.g. 502/504). Don't blindly JSON.parse.
        const raw = await response.text();
        let data;
        try {
            data = raw ? JSON.parse(raw) : {};
        } catch (e) {
            data = { success: false, code: response.status, message: raw };
        }
        
        let updateData={};

        // wp_send_json_* (taxonomy / legacy) vs REST response (create-translate-post).
        const successPayload =
            (data.success && data.data && data.data.post_id)
                ? data.data
                : (response.ok && data.post_id && !data.code ? data : null);

        if (successPayload){

            const extraData={};

            if(editorType === 'taxonomy'){
                extraData.taxonomy=lmatBulkTranslationGlobal.taxonomy_page;
            }

            updateTranslateData({provider: service, sourceLang, targetLang: lang, currentPostId: successPayload.post_id, parentPostId: postId, editorType, updateTranslateDataNonce: successPayload.update_translate_data_nonce, extraData, signal});

            successPayload.post_title = '' === successPayload.post_title ? __('N/A', 'linguator-pro') : successPayload.post_title;
            updateData={targetPostId: successPayload.post_id, targetPostTitle: successPayload.post_title, targetLanguage: lang, postLink: successPayload.post_link, postEditLink: successPayload.post_edit_link, elementorEditLink: successPayload.elementor_edit_link || false, status: 'completed', messageClass: 'success'};
            storeDispatch(updateCountInfo({postsTranslated: store.getState().countInfo.postsTranslated+1}));
        }else{
            if(data.data && data.data.error){
                let errorHtml='Error Code:' + (data.data.status);

                if(typeof data.data.error === 'string'){
                    errorHtml+='<br>Error Message:' + data.data.error + '(' + data.data.error + ')';
                }
    
                if(typeof data.data.error === 'object'){
                    errorHtml+='<br>Error Message:' + JSON.stringify(data.data.error);
                }

                updateData={status: 'error', messageClass: 'error', errorMessage: __('Post not created. Please try again.', 'linguator-pro'), errorHtml: '<div class="lmat-error-html">'+errorHtml+'</div>'};
            }else if(data.code && data.message){
                updateData={status: 'error', messageClass: 'error', errorMessage: __('Post not created. Please try again.', 'linguator-pro'), errorHtml: '<div class="lmat-error-html">'+data.message+'</div>'};
            }else if(!data.success || data.data){
                const errorMessage = __('Something went wrong. Please try again.', 'linguator-pro');
                const detail = data.data == null ? errorMessage : data.data;
                updateData={status: 'error', messageClass: 'error', errorMessage, errorHtml: '<div class="lmat-error-html">'+detail+'</div>', allowTranslate: data.data == null};
            }else if(!data.data.post_id){
                updateData={status: 'error', messageClass: 'error', errorMessage: __('Post not created. Please try again.', 'linguator-pro'), errorHtml: '<div class="lmat-error-html">'+data.data+'</div>'};
            }else if(typeof data === 'string'){
                updateData={status: 'error', messageClass: 'error', errorMessage: __('Post not created. Please try again.', 'linguator-pro'), errorHtml: '<div class="lmat-error-html">'+data+'</div>'};
            }
        }
        
        storeDispatch(unsetPendingPost(postId+'_'+lang));
        storeDispatch(updateCompletedPosts([postId+'_'+lang]));
        storeDispatch(updateTranslatePostInfo({[postId+'_'+lang]: updateData}));

    }).catch(error=>{
        if (error && error.name === 'AbortError') {
            return;
        }
        console.log(error);
        storeDispatch(unsetPendingPost(postId+'_'+lang));
        storeDispatch(updateCompletedPosts([postId+'_'+lang]));
        let errorHtml=error;

        if(error.message){
            errorHtml=error.message;
        }

        if(error.data && error.data.status){
            errorHtml='Error Code:' + (error.data.status);

            if(typeof error.data.error === 'string'){
                errorHtml+='<br>Error Message:' + error.data.error;
            }

            if(typeof error.data.error === 'object'){
                errorHtml+='<br>Error Message:' + JSON.stringify(error.data.error);
            }
        }

        storeDispatch(updateTranslatePostInfo({[postId+'_'+lang]: { status: 'error', messageClass: 'error', errorMessage: __('Post not created. Please try again.', 'linguator-pro'), errorHtml: '<div class="lmat-error-html">'+errorHtml+'</div>'}}));
    })
}

const bulkTranslateEntries = async ({ids, langs, storeDispatch, signal}) => {
    
    const bulkTranslateRouteUrl = lmatBulkTranslationGlobal.bulkTranslateRouteUrl;
    const bulkTranslatePrivateKey = lmatBulkTranslationGlobal.bulkTranslatePrivateKey;
    const nonce = lmatBulkTranslationGlobal.nonce;
    let storeParseBlockRules=false;

    const body={
        ids: JSON.stringify(ids),
        lang: JSON.stringify(langs),
        privateKey: bulkTranslatePrivateKey,
    }

    let postUrl='lmat:bulk-translate-entries';

    if(lmatBulkTranslationGlobal.taxonomy_page && '' !== lmatBulkTranslationGlobal.taxonomy_page){
        body.taxonomy=lmatBulkTranslationGlobal.taxonomy_page;
        postUrl='lmat:bulk-translate-taxonomy-entries';
    }

    let untranslatedPosts;
    let untranslatedPostsData;
    try {
        untranslatedPosts = await fetch(bulkTranslateRouteUrl + '/' + postUrl, {
            method: 'POST',
            body: new URLSearchParams(body),
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept': 'application/json',
            },
            ...(signal ? { signal } : {}),
        });

        untranslatedPostsData = await untranslatedPosts.json();
    } catch (err) {
        if (err && err.name === 'AbortError') {
            return { success: false, message: '', aborted: true };
        }
        if (signal && signal.aborted) {
            return { success: false, message: '', aborted: true };
        }
        return {
            success: false,
            message: __('Could not load posts to translate. Check your connection and try again.', 'linguator-pro'),
        };
    }

    if (signal && signal.aborted) {
        return { success: false, message: '', aborted: true };
    }
    
    if(!untranslatedPostsData.success && !untranslatedPostsData.code && untranslatedPostsData.data && untranslatedPostsData.data.message){
        return {success: false, message: untranslatedPostsData.data.message};
    }else if(!untranslatedPostsData.success && !untranslatedPostsData.message && untranslatedPostsData.data.error){
        return {success: false, message: JSON.stringify(untranslatedPostsData.data.error)};
    }else if(!untranslatedPostsData.success && untranslatedPostsData.message && untranslatedPostsData.data.trace && untranslatedPostsData.data.message){
        // trace key aur uska data hatao, phir pura object stringify karo
        if (untranslatedPostsData.data && untranslatedPostsData.data.trace) {
            delete untranslatedPostsData.data.trace;
        }
        return {success: false, message: JSON.stringify(untranslatedPostsData.data)};
    }else if(!untranslatedPostsData.success && untranslatedPostsData.message){
        return {success: false, message: untranslatedPostsData.message};
    }

    if(!untranslatedPostsData){
        return {success: false, message: __('No posts to translate data undefined', 'linguator-pro')};
    }

    if(!untranslatedPostsData.success){
        return {success: false, message: untranslatedPostsData.message};
    }

    if(!untranslatedPostsData.data){
        return {success: false, message: __('No posts to translate untranslated data not found', 'linguator-pro')};
    }

    if(!untranslatedPostsData.data.posts){
        return {success: false, message: __('No posts to translate untranslated posts data not found', 'linguator-pro')};
    }

    if(!untranslatedPostsData.data.CreateTranslatePostNonce){
        return {success: false, message: __('No create translate post nonce', 'linguator-pro')};
    }

    const posts=untranslatedPostsData.data.posts;

    const postKeys=Object.keys(posts);

    
    if(postKeys.length > 0){
        let allTargetLanguages={};
        
        const postIdExist=new Array();
        const existsPostInPendingPosts=Object.keys(store.getState().translatePostInfo);

        postKeys.forEach(postId=>{
           const languages=posts[postId].languages;
           const parentPostTitle=posts[postId].title;

           allTargetLanguages[posts[postId].sourceLanguage]={languages: [...(allTargetLanguages[posts[postId].sourceLanguage]?.languages || []), ...(posts[postId].languages || [])]};

           if(languages && languages.length > 0){
                languages.forEach(language=>{

                    if(existsPostInPendingPosts.includes(postId+'_'+language)){
                        return;
                    }

                    let firstPostLanguage=false;

                    if(!postIdExist.includes(postId) && !existsPostInPendingPosts.includes(postId+'_'+language)){
                        postIdExist.push(postId);
                        firstPostLanguage=true;
                    }

                    const flagUrl=lmatBulkTranslationGlobal.languageObject[language].flag;
                    const languageName=lmatBulkTranslationGlobal.languageObject[language].name;
                    storeDispatch(updatePendingPosts([postId+'_'+language]));
                    storeDispatch(updateTranslatePostInfo({[postId+'_'+language]: {parentPostId: postId, targetPostId: null, targetLanguage: language, postLink: null, status: 'pending', parentPostTitle, firstPostLanguage, flagUrl, languageName, messageClass: 'warning'}}));
                });
           }
        });

        const fetchGlossaryTerms=async(allTargetLanguages)=>{

            const fethcGlossary=async(sourceLanauges)=>{
                if (signal && signal.aborted) {
                    return;
                }
                const targetLanguages=allTargetLanguages[sourceLanauges];

                if(!targetLanguages || typeof targetLanguages !== 'object' || Object.values(targetLanguages).length < 1){
                    return;
                }

                try {
                    const data={
                        action: 'lmat_get_glossary',
                        source_lang: sourceLanauges,
                        target_lang: Object.values(targetLanguages).join(','),
                        _wpnonce: lmatBulkTranslationGlobal.get_glossary_validate
                    }

                    // Add sourceLang and targetLang as query params
                    const url = `${lmatBulkTranslationGlobal.ajax_url}`;
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                        body: new URLSearchParams(data),
                        ...(signal ? { signal } : {}),
    
                    });
    
                    const rawGlossary = await response.text();
                    let responseData;
                    try {
                        responseData = rawGlossary ? JSON.parse( rawGlossary ) : {};
                    } catch (e) {
                        responseData = { success: false, code: response.status, message: rawGlossary };
                    }

                    if(responseData.success && responseData.data.terms){
                        storeDispatch(updateGlossaryTerms({sourceLanguage: sourceLanauges, translations: Object.values(responseData.data.terms)}));
                    }
                } catch (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    console.log(err);
                }
            }

            const sourceLanauges=Object.keys(allTargetLanguages);

            if(sourceLanauges.length > 0){
                await LoopCallback({callback: fethcGlossary, loop: sourceLanauges, index: 0});
            }
            

        }

        const storeSourceContent=async(index, translatePostsCount)=>{

            if (signal && signal.aborted) {
                return;
            }

            const postId=postKeys[index];
            const activeProvider=store.getState().serviceProvider;

            const {
				title,
				content,
				post_name,
				languages,
				editor_type,
				metaFields = null,
				sourceLanguage,
				excerpt = null,
				featured_image = null,
				content_media = null,
				elementor_media = null,
			} = posts[postId];
            
            if(!sourceLanguage){
                const postTitle=title || 'N/A';
                let titleLink=false;
                let postLink=false;
                if(posts[postId]?.post_link){
                    postLink=posts[postId].post_link;
                    titleLink=postLink;
                }

                const errorInfo={
                    title: title,
                    editorType: editor_type,
                    sourceLanguage,
                    errorMessage: sprintf(
                        __('Set source language for this %s %s before translating.', 'linguator-pro'),
                        titleLink ? '<a href="'+titleLink+'" target="_blank" rel="noopener noreferrer">'+postTitle+'</a>' : postTitle,
                        window?.lmatBulkTranslationGlobal?.taxonomy_page || window?.lmatBulkTranslationGlobal?.post_label
                    )
                }

                if(posts[postId]?.post_link){
                    errorInfo.postLink=postLink;
                }

                storeDispatch(updateErrorPostsInfo({
                    postId,
                    data: errorInfo
                }));

                storeDispatch(updateCountInfo({errorPosts: store.getState().countInfo.errorPosts+1}));

                index++;
                if(index > postKeys.length-1){
                    return;
                }

                await storeSourceContent(index, translatePostsCount);

                return;
            }

            if(languages && languages.length > 0){
                storeDispatch(updateTargetLanguages({lang: languages}));

                const data={content, editorType:editor_type, metaFields, service: activeProvider, postId, storeDispatch, sourceLanguage};

                if(untranslatedPostsData?.data?.allowedMetaFields && metaFields){
                    data.allowedMetaFields=JSON.parse(untranslatedPostsData?.data?.allowedMetaFields);
                }
                
                if(editor_type === 'block'){
                    data.blockParseRules=JSON.parse(untranslatedPostsData?.data?.blockParseRules);

                    if(!storeParseBlockRules){
                        storeDispatch(updateBlockParseRules(JSON.parse(untranslatedPostsData?.data?.blockParseRules)));
                        storeParseBlockRules=true;
                    }
                }

                if((content && content !== '') || (metaFields && Object.keys(metaFields).length > 0)){
                    if (signal && signal.aborted) {
                        return;
                    }
                    await filterContent(data);
                }

				storeMediaStrings( {
					postId,
					featuredImage: featured_image,
					contentMedia: content_media,
					elementorMedia: elementor_media,
					storeDispatch,
				} );
            
                if(['classic', 'block', 'elementor', 'taxonomy'].includes(editor_type)){

                    const glossaryTerms=selectGlossaryTerms(store.getState(), sourceLanguage);
                    
                    if(title && title.trim() !== ''){
                        let filteredTitle= title;
                        if(['google','localAiTranslator','edgeLocalAiTranslator'].includes(activeProvider)){
                            filteredTitle= await updateGlossaryString({content: title, glossaryTerms});
                        }
                        storeDispatch(updateSourceContent({postId, uniqueKey: 'title', value: title}));
                        storeDispatch(updateTargetContent({postId, uniqueKey: 'title', value: filteredTitle}));
                    }

                    if(post_name && post_name.trim() !== ''){
                        let filteredPostName= post_name;
                        if(['google','localAiTranslator','edgeLocalAiTranslator'].includes(activeProvider)){
                            filteredPostName= await updateGlossaryString({content: post_name, glossaryTerms});
                        }
                        storeDispatch(updateSourceContent({postId, uniqueKey: 'post_name', value: post_name}));
                        storeDispatch(updateTargetContent({postId, uniqueKey: 'post_name', value: filteredPostName}));
                    }

                    if(excerpt && excerpt.trim() !== ''){
                        let filteredExcerpt= excerpt;
                        if(['google','localAiTranslator','edgeLocalAiTranslator'].includes(activeProvider)){
                            filteredExcerpt= await updateGlossaryString({content: excerpt, glossaryTerms});
                        }
                        storeDispatch(updateSourceContent({postId, uniqueKey: 'excerpt', value: excerpt}));
                        storeDispatch(updateTargetContent({postId, uniqueKey: 'excerpt', value: filteredExcerpt}));
                    }

                    const previousParentPostsInfo=store.getState().parentPostsInfo[postId];
                    
                    const charactersCount=(previousParentPostsInfo?.charactersCount || 0) + title.length;
                    const wordsCount=(previousParentPostsInfo?.wordsCount || 0) + title.split(/\s+/).filter(word => /[^\p{L}\p{N}]/.test(word)).length;
                    const stringsCount=(previousParentPostsInfo?.stringsCount || 0) + title.split(/(?<=[.!?]+)\s+/).length;

                    const originalContent={};

                    if(title && title.trim() !== ''){
                        originalContent.title=title;
                    }

                    if(content){
                        originalContent.content=content;
                    }else{
                        originalContent.content={};
                    }

                    if(post_name && post_name.trim() !== ''){
                        originalContent.post_name=post_name;
                    }

                    if(excerpt && excerpt.trim() !== ''){
                        originalContent.excerpt=excerpt;
                    }

                    if(metaFields && Object.keys(metaFields).length > 0){
                        originalContent.metaFields=metaFields;
                    }

					if ( featured_image ) {
						originalContent.featured_image = featured_image;
					}
					if ( Array.isArray( content_media ) ) {
						originalContent.content_media = content_media;
					}
					if ( Array.isArray( elementor_media ) ) {
						originalContent.elementor_media = elementor_media;
					}

                    storeDispatch(updateParentPostsInfo({postId, data: {editorType: editor_type, originalContent, languages, sourceLanguage, charactersCount, wordsCount, stringsCount}}));
                    storeDispatch(updateCountInfo({totalPosts: store.getState().countInfo.totalPosts+languages.length}));
                }
            }else{
                console.log(`All target languages for post ${postId} already exist. Skipping translation.`);
            }
            
            index++;
            if(index > postKeys.length-1){
                return;
            }

            await storeSourceContent(index, translatePostsCount);
        }

        const translatePostsCount=store.getState().pendingPosts.length;

        await fetchGlossaryTerms(allTargetLanguages);
        await storeSourceContent(0, translatePostsCount);

        if(untranslatedPostsData?.data?.allowedMetaFields){
            storeDispatch(updateAllowedMetaFields(JSON.parse(untranslatedPostsData?.data?.allowedMetaFields)));
        }

        return {postKeys, nonce: untranslatedPostsData.data.CreateTranslatePostNonce};
    }
}

export {bulkTranslateEntries, initBulkTranslate};
