import { select } from '@wordpress/data';
import YoastSeoFields from '../../component/translate-seo-fields/yoast-seo-fields.js';
import RankMathSeo from '../../component/translate-seo-fields/rank-math-seo.js';
import translatedMetaFields from '../meta-fields/index.js';
import saveMediaTranslations from '../../helper/save-media-translations.js';

// Update widget content with translations
const lmatUpdateWidgetContent = (translations) => {

    translations.forEach(translation => {
        // Find the model by ID using the lmatFindModelById function
        const model = lmatFindModelById(elementor.elements.models, translation.ID);
        if (model) {
            const settings = model.get('settings');
            const isAtomic = translation?.isAtomic;

            // Handle Elementor Atomic Widget fields.
            // Atomic widgets store values as nested typed objects ({ $$type, value }).
            // We split the key path and traverse the attribute tree to set the leaf.
            if(isAtomic){
                const settingKey = translation.key.split('_lmat_page_translation_');
                let totalKeys = settingKey.length - 1;

                if(settingKey && settingKey.length > 0){
                    const atomicAttributes = settings.get(settingKey[0]);
                    if(atomicAttributes){
                        let currentObject = atomicAttributes;
                        const lastKey = settingKey[totalKeys];
                        for(let i = 1; i < totalKeys; i++){
                            currentObject = currentObject[settingKey[i]];
                            if(!currentObject) break;
                        }

                        if(currentObject && lastKey && currentObject[lastKey] !== undefined){
                            currentObject[lastKey] = translation.translatedContent;
                            settings.set(settingKey[0], atomicAttributes);
                            model?.renderRemoteServer();
                            return;
                        }
                    }
                }
            }

            // Check for normal fields (title, text, editor, etc.)
            if (settings.get(translation.key)) {
                settings.set(translation.key, translation.translatedContent);  // Set the translated content
                model?.renderRemoteServer();
            }

            // Handle repeater fields (if any)
            const repeaterMatch = translation.key.match(/(.+)\[(\d+)\]\.(.+)/);
            if (repeaterMatch) {

                const [_, repeaterKey, index, subKey] = repeaterMatch;
                const repeaterArray = settings.get(repeaterKey);

                if (Array.isArray(repeaterArray.models) && repeaterArray.models[index]) {
                    let repeaterModel = repeaterArray.models[index]
                    let repeaterAttribute = repeaterModel.attributes
                    repeaterAttribute[subKey] = translation.translatedContent;

                    settings.set(repeaterKey, repeaterArray); // Set the updated array back to settings
                    model?.renderRemoteServer();
                }

            }
        }
    });

    // After updating content, ensure that the changes are saved or published
    $e.internal('document/save/set-is-modified', { status: true });
}

const lmatUpdateMetaFields = (metaFields, service) => {
    const AllowedMetaFields = select('block-lmatPageTranslation/translate').getAllowedMetaFields();

        if(!metaFields){
            return;
        }

        Object.keys(metaFields).forEach(key => {
            // Update yoast seo meta fields
            if (Object.keys(AllowedMetaFields).includes(key)) {
                const translatedMetaFields = select('block-lmatPageTranslation/translate').getTranslatedString('metaFields', metaFields[key], key, service);
                if (key.startsWith('_yoast_wpseo_') && AllowedMetaFields[key].inputType === 'string') {
                    YoastSeoFields({ key: key, value: translatedMetaFields });
                } else if (key.startsWith('rank_math_') && AllowedMetaFields[key].inputType === 'string') {
                    RankMathSeo({ key: key, value: translatedMetaFields });
                } else if (key.startsWith('_seopress_') && AllowedMetaFields[key].inputType === 'string') {
                    elementor?.settings?.page?.model?.setExternalChange(key, translatedMetaFields);
                }
            };
        });
}

const lmatUpdateTitle = (title, service) => {
    if(title && '' !== title){
        const translatedTitle = select('block-lmatPageTranslation/translate').getTranslatedString('title', title, null, service);

        if(translatedTitle && '' !== translatedTitle){
            elementor?.settings?.page?.model?.setExternalChange('post_title', translatedTitle);
        }
    }
}

/**
 * Updates ACF fields in the Elementor editor when ACF is loaded.   
 */
const postAcfFieldsUpdate = (postContent, service) => {
    const AllowedMetaFields = select('block-lmatPageTranslation/translate').getAllowedMetaFields();
    const metaFieldsData = postContent.metaFields;

    if (!window.acf || !metaFieldsData) {
        return;
    }

    acf.getFields().forEach(field => {
        const fieldData = JSON.parse(JSON.stringify({ key: field.data.key, type: field.data.type, name: field.data.name }));

        if (field.$el && field.$el.closest('.acf-field.acf-field-repeater') && field.$el.closest('.acf-field.acf-field-repeater').length > 0) {
            const rowId = field.$el.closest('.acf-row').data('id');
            const repeaterItemName = field.$el.closest('.acf-field.acf-field-repeater').data('name');

            if (rowId && '' !== rowId) {
                const index = rowId.replace('row-', '');
                fieldData.name = repeaterItemName + '_' + index + '_' + fieldData.name;
            }
        }

        if (fieldData && fieldData.key && Object.keys(AllowedMetaFields).includes(fieldData.name)) {
            const fieldName = fieldData.name;
            const inputType = fieldData.type;
            const sourceValue = metaFieldsData[fieldName] ? metaFieldsData[fieldName] : field?.val();
            const translatedMetaFields = select('block-lmatPageTranslation/translate').getTranslatedString('metaFields', sourceValue, fieldData.name, service);

            if (!translatedMetaFields || '' === translatedMetaFields) {
                return;
            }

            if ('wysiwyg' === inputType && window.tinymce) {
                const editorId = field.data.id;
                const tinymceTranslatedMetaFields = translatedMetaFields.replace(/(\r\n\r\n|\r\n)/g, '</p><p>');

                tinymce.get(editorId)?.setContent(tinymceTranslatedMetaFields);

                const tinymceTextArea = document.querySelector(`textarea#${editorId}`);
                if (tinymceTextArea) {
                    tinymceTextArea.value = translatedMetaFields;
                }
            } else {
                field.val(translatedMetaFields);
            }
        }
    });
};

/**
 * Persists translated meta fields (including ACF) via AJAX when post meta sync is disabled.
 */
const updatePostMetaFields = (postContent, service) => {
    const ajaxUrl = window.lmatPageTranslationGlobal.ajax_url;
    const postId = window.lmatPageTranslationGlobal.current_post_id;
    const nonce = window.lmatPageTranslationGlobal.post_meta_fields_key;
    const action = window.lmatPageTranslationGlobal.update_post_meta_fields;

    if (!postId || !nonce || !action) {
        return Promise.resolve();
    }

    const requestBody = {
        action: action,
        post_id: postId,
        meta_fields: JSON.stringify(translatedMetaFields(postContent.metaFields, service)),
        post_meta_fields_key: nonce,
    };

    return fetch(ajaxUrl, {
        method: 'POST',
        headers: {
            'content-type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json',
        },
        body: new URLSearchParams(requestBody),
    }).catch(error => {
        console.error('Error updating meta fields:', error);
    });
};

// Find Elementor model by ID
const lmatFindModelById = (elements, id) => {
    for (const model of elements) {
        if (model.get('id') === id) {
            return model;
        }
        const nestedElements = model.get('elements').models;
        const foundModel = lmatFindModelById(nestedElements, id);
        if (foundModel) {
            return foundModel;
        }
    }
    return null;
}

const updateElementorPage = ({ postContent, modalClose, service }) => {
    const postID = elementor.config.document.id;

    // Collect translated content
    const translations = [];

    // Define a list of properties to exclude
    const cssProperties = [
        'content_width', 'title_size', 'font_size', 'margin', 'padding', 'background', 'border', 'color', 'text_align',
        'font_weight', 'font_family', 'line_height', 'letter_spacing', 'text_transform', 'border_radius', 'box_shadow',
        'opacity', 'width', 'height', 'display', 'position', 'z_index', 'visibility', 'align', 'max_width', 'content_typography_typography', 'flex_justify_content', 'title_color', 'description_color', 'email_content'
    ];

    const subStringsToCheck=(strings)=>{
        const dynamicSubStrings=['title', 'description', 'editor', 'text', 'content', 'label'];
        const staticSubStrings=['caption','heading','sub_heading', 'testimonial_content', 'testimonial_job', 'testimonial_name', 'name'];

        return dynamicSubStrings.some(substring => strings.toLowerCase().includes(substring)) || staticSubStrings.some(substring => strings === substring);
    }

    /**
     * Handles Elementor Atomic Widget typed values within storeSourceStrings.
     *
     * Atomic widgets store translatable fields as objects with a `$$type` property
     * (e.g. `{ $$type: 'string', value: 'Hello' }` or `{ $$type: 'html-v3', ... }`).
     * This helper extracts the inner string, looks up its translation, and pushes a
     * translation entry marked with `isAtomic: true` so the apply phase knows to
     * traverse the nested attribute path.
     *
     * @param {Object}      element  - The atomic widget value object (has `$$type`).
     * @param {Array}       ids      - Current ID path (used to build uniqueKey lookup).
     * @param {string|null} widgetId - The Elementor widget element ID for model lookup.
     */
    const storeAtomicWidgetStrings = (element, ids=[], widgetId=null) => {
        const currentKey = ids[ids.length - 1];
        // Keys that are always translatable for atomic widgets even if they don't
        // match the generic subStringsToCheck patterns (e.g. 'placeholder', 'paragraph').
        const validAtomicKeys = ['placeholder', 'paragraph', 'override_value'];

        if(!subStringsToCheck(currentKey) && !validAtomicKeys.includes(currentKey)){
            return;
        }

        const settingsIndex = ids.indexOf('settings');
        const pathKey = ids.slice(settingsIndex + 1).join('_lmat_page_translation_');

        if(element?.$$type === 'html-v3'){
            if(element.value && element.value.content && element.value.content?.$$type === 'string' && element.value.content.value && '' !== element.value.content.value){
                const uniqueKey = ids.join('_lmat_page_translation_') + '_lmat_page_translation_value_lmat_page_translation_content_lmat_page_translation_value';
                const translatedData = select('block-lmatPageTranslation/translate').getTranslatedString('content', element.value.content.value, uniqueKey, service);
                translations.push({
                    ID: widgetId,
                    key: `${pathKey}_lmat_page_translation_value_lmat_page_translation_content_lmat_page_translation_value`,
                    translatedContent: translatedData,
                    isAtomic: true
                });
            }
        } else if(element?.$$type === 'string'){
            if(element.value && '' !== element.value){
                const uniqueKey = ids.join('_lmat_page_translation_') + '_lmat_page_translation_value';
                const translatedData = select('block-lmatPageTranslation/translate').getTranslatedString('content', element.value, uniqueKey, service);
                translations.push({
                    ID: widgetId,
                    key: `${pathKey}_lmat_page_translation_value`,
                    translatedContent: translatedData,
                    isAtomic: true
                });
            }
        }
    }

    const storeComponentInstanceOverrides = (element, ids=[], widgetId=null) => {
        if (element?.$$type === 'component-instance' && element.value && element.value.overrides) {
            const overrides = element.value.overrides;
            if (overrides.$$type === 'overrides' && Array.isArray(overrides.value)) {
                overrides.value.forEach((override, overrideIndex) => {
                    if (override.$$type === 'override' && override.value && override.value.override_value) {
                        const overrideValue = override.value.override_value;
                        const baseIds = [...ids, 'value', 'overrides', 'value', overrideIndex, 'value', 'override_value'];
                        storeAtomicWidgetStrings(overrideValue, baseIds, widgetId);
                    }
                });
            }
        }
    }

    const storeSourceStrings = (element,index, ids=[]) => {
        const widgetId = element.id;
        const settings = element.settings;
        ids.push(index)

        // Check if settings is an object
        if (typeof settings === 'object' && settings !== null) {

            // Iterate through the keys in settings
            Object.keys(settings).forEach(key => {
                // Skip keys that are CSS properties
                if (cssProperties.some(substring => key.toLowerCase().includes(substring))) {
                    return; // Skip this property and continue to the next one
                }

                if (key === 'component_instance') {
                    storeComponentInstanceOverrides(settings[key], [...ids, 'settings', key], widgetId);
                } else if (subStringsToCheck(key) &&
                    typeof settings[key] === 'string' && settings[key].trim() !== '') {
                    const uniqueKey = ids.join('_lmat_page_translation_') + '_lmat_page_translation_settings_lmat_page_translation_' + key;

                    const translatedData = select('block-lmatPageTranslation/translate').getTranslatedString('content', settings[key], uniqueKey, service);

                    translations.push({
                        ID: widgetId,
                        key: key,
                        translatedContent: translatedData
                    })
                } else if(settings[key] && typeof settings[key] === 'object' && Object.hasOwn(settings[key], '$$type')){
                    // Atomic Widget typed object — e.g. { $$type: 'string', value: '...' }
                    storeAtomicWidgetStrings(settings[key], [...ids, 'settings', key], widgetId);
                } else if (Array.isArray(settings[key])) {
                    // Repeater / array fields.
                    settings[key].forEach((item, index) => {
                        if (typeof item === 'object' && item !== null) {
                            // Check for translatable content in repeater fields
                            Object.keys(item).forEach(repeaterKey => {
                                // Skip if it's a CSS-related property
                                if (cssProperties.includes(repeaterKey.toLowerCase())) {
                                    return; // Skip this property
                                }

                                if (subStringsToCheck(repeaterKey) &&
                                    typeof item[repeaterKey] === 'string' && item[repeaterKey].trim() !== '') {

                                    const fieldKey = `${key}[${index}].${repeaterKey}`
                                    const uniqueKey = ids.join('_lmat_page_translation_') + '_lmat_page_translation_settings_lmat_page_translation_' + key + '_lmat_page_translation_' + index + '_lmat_page_translation_' + repeaterKey;

                                    const translatedData = select('block-lmatPageTranslation/translate').getTranslatedString('content', item[repeaterKey], uniqueKey, service);

                                    translations.push({
                                        ID: widgetId,
                                        key: fieldKey,
                                        translatedContent: translatedData
                                    })
                                } else if(item[repeaterKey] && typeof item[repeaterKey] === 'object' && Object.hasOwn(item[repeaterKey], '$$type')){
                                    // Atomic Widget typed object inside a repeater field.
                                    storeAtomicWidgetStrings(item[repeaterKey], [...ids, 'settings', key, index, repeaterKey], widgetId);
                                }
                            });
                        }
                    });
                }
            });
        }

        // If there are nested elements, process them recursively
        const isComponentDoc = window.lmatPageTranslationGlobal?.post_type === 'elementor_component';
        if ((isComponentDoc || element.widgetType !== 'e-component') && element.elements && Array.isArray(element.elements)) {
            element.elements.forEach((nestedElement,index) => {
                storeSourceStrings(nestedElement,index, [...ids, 'elements']);
            });
        }
    }

    postContent.widgetsContent.map((widget,index) => storeSourceStrings(widget,index,[]));

    // Update widget content with translations
    lmatUpdateWidgetContent(translations);
    
    // Update Meta Fields (SEO plugins in Elementor panel)
    lmatUpdateMetaFields(postContent.metaFields, service);

    // Update ACF fields in the editor when post meta sync is disabled
    if (lmatPageTranslationGlobal.postMetaSync === 'false') {
        postAcfFieldsUpdate(postContent, service);
    }

    // Update Title
    lmatUpdateTitle(postContent.title, service);

    const replaceSourceString=()=>{
        const elementorData = lmatPageTranslationGlobal.elementorData;
        const translateStrings=wp.data.select('block-lmatPageTranslation/translate').getTranslationEntries();

        translateStrings.forEach(translation => {
            const sourceString = translation.source;
            const ids = translation.id;
            const translatedContent = translation.translatedData;
            const type=translation.type;

            if(!sourceString || '' === sourceString || 'content' !== type){
                return;
            }
            
            const keyArray = ids.split('_lmat_page_translation_');
            
            const translateValue = translatedContent[service];
            let parentElement = null;
            let parentKey = null;

            let currentElement = elementorData;
               
            keyArray.forEach(key => {
                parentElement = currentElement;
                parentKey = key;
                currentElement = currentElement ? currentElement[key] : null;
            });

            if(parentElement && parentKey && parentElement[parentKey] && parentElement[parentKey] === sourceString){
                parentElement[parentKey] = translateValue;
            }
        });

        return elementorData;
    }

    
    const elementorData = replaceSourceString();

    const requestBody={
        action: lmatPageTranslationGlobal.update_elementor_data,
        post_id: postID,
        elementor_data: JSON.stringify(elementorData),
        lmat_page_translation_nonce: lmatPageTranslationGlobal.ajax_nonce,
        parent_post_id: lmatPageTranslationGlobal.parent_post_id
    }

    if(postContent.slug_name && '' !== postContent.slug_name && lmatPageTranslationGlobal.slug_translation_option === 'slug_translate'){
        const slug_name=postContent.slug_name;
        const translatedSlug=select('block-lmatPageTranslation/translate').getTranslatedString('slug', slug_name, null, service);

        if(translatedSlug && '' !== translatedSlug){
            requestBody.post_name=translatedSlug;
        }
    }

    fetch(lmatPageTranslationGlobal.ajax_url, {
        method: 'POST',
        headers: {
            'content-type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json',
        },
        body: new URLSearchParams(requestBody)
    })
        .then(response => response.json())
        .then(async (data) => {
            if (data.success) {
                const translateButton = document.querySelector('.lmat-page-translation-button[name="lmat_page_translation_meta_box_translate"]');
                if(translateButton){
                    translateButton.setAttribute('title', 'Translation process completed successfully.');
                }
            } else {
                console.error('Failed to update Elementor data:', data.data);
            }

            if (lmatPageTranslationGlobal.postMetaSync === 'false') {
                await updatePostMetaFields(postContent, service);
            }

            // Save translated attachment metadata.
            await saveMediaTranslations( service, postContent );

            modalClose();
        })
        .catch(error => {
            modalClose();
            console.error('Error updating Elementor data:', error);
        });
}

export default updateElementorPage;
