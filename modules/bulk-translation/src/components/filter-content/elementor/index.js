import React from 'react';
import filterContent from '../../../../../page-translation/src/component/filter-target-content/index.js';
import extractInnerContent from '../extarct-inner-content/index.js';
import storeSourceString from '../../store-source-string/index.js';
import {selectGlossaryTerms} from '../../../redux-store/features/selectors.js';
import updateGlossaryString from '../update-glossary-string/index.js';
import {store} from '../../../redux-store/store.js';

const FilterElementorContent = async({content, service, postId, storeDispatch, filterHtmlContent, sourceLanguage}) => {
    const translateContentObject={};
    const glossaryTerms=selectGlossaryTerms(store.getState(), sourceLanguage);

    const loopCallback=async (callback, loop, index)=>{
        await callback(loop[index], index);

        index++;

        if(index < loop.length){
            await loopCallback(callback, loop, index);
        }
    }

    const translateContent=async (ids,value)=>{
        if(typeof value === 'string' && value.trim() !== '' && ids.length > 0){
            const uniqueKey = ids.join('_lmat_bulk_content_temp_');
            let stringContent=value;

            if(filterHtmlContent){
                let reactElement=filterContent({content: value, service, contentKey: uniqueKey, skipTags:['script', 'style']});
                stringContent=await extractInnerContent(reactElement);

                if(['google','localAiTranslator','edgeLocalAiTranslator'].includes(service) && glossaryTerms && Object.values(glossaryTerms).length > 0){
                    stringContent=await updateGlossaryString({content: stringContent, glossaryTerms});
                }

                reactElement=null;
            }
            
            
            storeSourceString(postId, uniqueKey, value, stringContent, storeDispatch);
            if(stringContent && '' !== stringContent){  
                translateContentObject[uniqueKey]=stringContent;
            }
        }
    }

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
     * Handles Elementor Atomic Widget settings for bulk translation.
     *
     * Atomic widgets store translatable values as typed objects with a `$$type`
     * property instead of plain strings. This function detects the two common
     * atomic value shapes and dispatches them to `translateContent`:
     *
     *  - `$$type === 'string'`   → plain text stored in `.value`
     *  - `$$type === 'html-v3'`  → rich text stored in `.value.content.value`
     *
     * @param {Object} element - The atomic widget value object (has `$$type`).
     * @param {Array}  ids     - The current ID path used to build the unique key.
     */
    const storeAtomicWidgetStrings = async (element, ids=[]) => {
        const currentKey = ids[ids.length - 1];
        // Keys that are always translatable for atomic widgets even if they don't
        // match the generic subStringsToCheck patterns (e.g. 'placeholder', 'paragraph').
        const validAtomicKeys = ['placeholder', 'paragraph', 'override_value'];

        if(!subStringsToCheck(currentKey) && !validAtomicKeys.includes(currentKey)){
            return;
        }

        if(element?.$$type === 'html-v3'){
            if(element.value && element.value.content && element.value.content?.$$type === 'string' && element.value.content.value && '' !== element.value.content.value){
                await translateContent([...ids, 'value', 'content', 'value'], element.value.content.value);
            }
        } else if(element?.$$type === 'string'){
            if(element.value && '' !== element.value){
                await translateContent([...ids, 'value'], element.value);
            }
        }
    }

    const storeComponentInstanceOverrides = async (element, ids=[]) => {
        if (element?.$$type === 'component-instance' && element.value && element.value.overrides) {
            const overrides = element.value.overrides;
            if (overrides.$$type === 'overrides' && Array.isArray(overrides.value)) {
                for (let overrideIndex = 0; overrideIndex < overrides.value.length; overrideIndex++) {
                    const override = overrides.value[overrideIndex];
                    if (override.$$type === 'override' && override.value && override.value.override_value) {
                        const overrideValue = override.value.override_value;
                        const baseIds = [...ids, 'value', 'overrides', 'value', overrideIndex, 'value', 'override_value'];
                        await storeAtomicWidgetStrings(overrideValue, baseIds);
                    }
                }
            }
        }
    }

    const storeWidgetStrings = async(element, index, ids=[]) => {
        const settings = element.settings;
        ids.push(index);

        // Check if settings is an object
        if (typeof settings === 'object' && settings !== null && Object.keys(settings).length > 0) {

            const keysLoop=async (key, index)=>{
                if (cssProperties.some(substring => key.toLowerCase().includes(substring))) {
                    return; // Skip this property and continue to the next one
                }

                if (key === 'component_instance') {
                    await storeComponentInstanceOverrides(settings[key], [...ids, 'settings', key]);
                } else if (subStringsToCheck(key) &&
                    typeof settings[key] === 'string' && settings[key].trim() !== '') {
                    // Plain string setting — classic Elementor widget field.
                    await translateContent([...ids, 'settings', key],settings[key]);
                } else if(settings[key] && typeof settings[key] === 'object' && Object.hasOwn(settings[key], '$$type')){
                    // Atomic Widget typed object — e.g. { $$type: 'string', value: '...' }
                    await storeAtomicWidgetStrings(settings[key], [...ids, 'settings', key]);
                } else if(Array.isArray(settings[key]) && settings[key].length > 0){
                    // Repeater / array fields.
                    const settingsLoop=async(item, index)=>{
                        if(typeof item === 'object' && item !== null){
                            const settingsItemsLoop=async (repeaterKey)=>{

                                if (cssProperties.includes(repeaterKey.toLowerCase())) {
                                    return; // Skip this property
                                }

                                if(subStringsToCheck(repeaterKey) &&
                                    typeof item[repeaterKey] === 'string' && item[repeaterKey].trim() !== '') {
                                    await translateContent([...ids, 'settings', key, index, repeaterKey],item[repeaterKey]);
                                } else if(item[repeaterKey] && typeof item[repeaterKey] === 'object' && Object.hasOwn(item[repeaterKey], '$$type')){
                                    // Atomic Widget typed object inside a repeater field.
                                    await storeAtomicWidgetStrings(item[repeaterKey], [...ids, 'settings', key, index, repeaterKey]);
                                }
                            }

                            await loopCallback(settingsItemsLoop, Object.keys(item), 0);
                        }
                    }

                    await loopCallback(settingsLoop, settings[key], 0);
                }
            }
    
            await loopCallback(keysLoop, Object.keys(settings), 0);
        }

        // If there are nested elements, process them recursively
        if (element.elements && Array.isArray(element.elements) && element.elements.length > 0) {
            const runLoop=async (childElement, index)=>{
                await storeWidgetStrings(childElement, index, [...ids, 'elements']);
            }

            await loopCallback(runLoop, element.elements, 0);
        }
    }

    if(content && content.length > 0){
        const runLoop=async (element, index)=>{
            await storeWidgetStrings(element, index, ['content']);
        }

        await loopCallback(runLoop, content, 0);
    }
}

export default FilterElementorContent;

