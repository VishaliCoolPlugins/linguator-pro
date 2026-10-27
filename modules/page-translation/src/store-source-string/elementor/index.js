import { select, dispatch } from "@wordpress/data";

const ElementorSaveSource = (content) => {

    const loopCallback=(callback, loop, index)=>{
        callback(loop[index], index);

        index++;

        if(index < loop.length){
            loopCallback(callback, loop, index);
        }
    }

    const translateContent=(ids,value)=>{
        if(typeof value === 'string' && value.trim() !== '' && ids.length > 0){
            const uniqueKey = ids.join('_lmat_page_translation_');

            if(value && '' !== value){  
                dispatch('block-lmatPageTranslation/translate').contentSaveSource(uniqueKey, value);
            }
        }
    }

    const subStringsToCheck=(strings)=>{
        const dynamicSubStrings=['title', 'description', 'editor', 'text', 'content', 'label'];
        const staticSubStrings=['caption','heading','sub_heading', 'testimonial_content', 'testimonial_job', 'testimonial_name', 'name'];

        return dynamicSubStrings.some(substring => strings.toLowerCase().includes(substring)) || staticSubStrings.some(substring => strings === substring);
    }

    // Define a list of properties to exclude
    const cssProperties = [
        'content_width', 'title_size', 'font_size', 'margin', 'padding', 'background', 'border', 'color', 'text_align',
        'font_weight', 'font_family', 'line_height', 'letter_spacing', 'text_transform', 'border_radius', 'box_shadow',
        'opacity', 'width', 'height', 'display', 'position', 'z_index', 'visibility', 'align', 'max_width', 'content_typography_typography', 'flex_justify_content', 'title_color', 'description_color', 'email_content'
    ];

    /**
     * Handles Elementor Atomic Widget settings.
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
    const storeAtomicWidgetStrings = (element, ids=[]) => {
        const currentKey = ids[ids.length - 1];
        // Keys that are always translatable for atomic widgets even if they don't
        // match the generic subStringsToCheck patterns (e.g. 'placeholder', 'paragraph').
        const validAtomicKeys = ['placeholder', 'paragraph', 'override_value'];

        if(!subStringsToCheck(currentKey) && !validAtomicKeys.includes(currentKey)){
            return;
        }

        if(element?.$$type === 'html-v3'){
            if(element.value && element.value.content && element.value.content?.$$type === 'string' && element.value.content.value && '' !== element.value.content.value){
                translateContent([...ids, 'value', 'content', 'value'], element.value.content.value);
            }
        } else if(element?.$$type === 'string'){
            if(element.value && '' !== element.value){
                translateContent([...ids, 'value'], element.value);
            }
        }
    }

    const storeComponentInstanceOverrides = (element, ids=[]) => {
        if (element?.$$type === 'component-instance' && element.value && element.value.overrides) {
            const overrides = element.value.overrides;
            if (overrides.$$type === 'overrides' && Array.isArray(overrides.value)) {
                overrides.value.forEach((override, overrideIndex) => {
                    if (override.$$type === 'override' && override.value && override.value.override_value) {
                        const overrideValue = override.value.override_value;
                        const baseIds = [...ids, 'value', 'overrides', 'value', overrideIndex, 'value', 'override_value'];
                        storeAtomicWidgetStrings(overrideValue, baseIds);
                    }
                });
            }
        }
    }

    const storeWidgetStrings = (element, index, ids=[]) => {
        const settings = element.settings;
        ids.push(index);

        // Check if settings is an object
        if (typeof settings === 'object' && settings !== null && Object.keys(settings).length > 0) {
            // Define the substrings to check for translatable content

            const keysLoop= (key, index)=>{
                if (cssProperties.some(substring => key.toLowerCase().includes(substring))) {
                    return; // Skip this property and continue to the next one
                }

                if (key === 'component_instance') {
                    storeComponentInstanceOverrides(settings[key], [...ids, 'settings', key]);
                } else if (subStringsToCheck(key) &&
                    typeof settings[key] === 'string' && settings[key].trim() !== '') {
                    // Plain string setting — classic Elementor widget field.
                    translateContent([...ids, 'settings', key],settings[key]);
                } else if(settings[key] && typeof settings[key] === 'object' && Object.hasOwn(settings[key], '$$type') ){
                    // Atomic Widget typed object — e.g. { $$type: 'string', value: '...' }
                    storeAtomicWidgetStrings(settings[key], [...ids, 'settings', key]);
                } else if(Array.isArray(settings[key]) && settings[key].length > 0){
                    // Repeater / array fields.
                    const settingsLoop=(item, index)=>{
                        if(typeof item === 'object' && item !== null){
                            const settingsItemsLoop= (repeaterKey)=>{

                                if (cssProperties.includes(repeaterKey.toLowerCase())) {
                                    return; // Skip this property
                                }

                                if(subStringsToCheck(repeaterKey) &&
                                    typeof item[repeaterKey] === 'string' && item[repeaterKey].trim() !== '') {
                                    translateContent([...ids, 'settings', key, index, repeaterKey],item[repeaterKey]);
                                } else if(item[repeaterKey] && typeof item[repeaterKey] === 'object' && Object.hasOwn(item[repeaterKey], '$$type') ){
                                    // Atomic Widget typed object inside a repeater field.
                                    storeAtomicWidgetStrings(item[repeaterKey], [...ids, 'settings', key, index, repeaterKey]);
                                }
                            }

                            loopCallback(settingsItemsLoop, Object.keys(item), 0);
                        }
                    }

                    loopCallback(settingsLoop, settings[key], 0);
                }
            }
    
            loopCallback(keysLoop, Object.keys(settings), 0);
        }

        // If there are nested elements, process them recursively
        const isComponentDoc = window.lmatPageTranslationGlobal?.post_type === 'elementor_component';
        if ((isComponentDoc || element.widgetType !== 'e-component') && element.elements && Array.isArray(element.elements) && element.elements.length > 0) {
            const runLoop=(childElement, index)=>{
                storeWidgetStrings(childElement, index, [...ids, 'elements']);
            }

            loopCallback(runLoop, element.elements, 0);
        }
    }

    if(content.widgetsContent && content.widgetsContent.length > 0){
        const runLoop= (element, index)=>{
            storeWidgetStrings(element, index, []);
        }

        loopCallback(runLoop, content.widgetsContent, 0);
    }

    if(content.title && '' !== content.title){
        const currentPostId=lmatPageTranslationGlobal.current_post_id;

        if(currentPostId){
            const existingTitle=elementor?.settings?.page?.model?.get('post_title');

            if(existingTitle && '' !== existingTitle && existingTitle === `Elementor #${currentPostId}`){
                dispatch('block-lmatPageTranslation/translate').titleSaveSource(content.title);
            }
        }
    }

    if(lmatPageTranslationGlobal.slug_translation_option === 'slug_translate'){
        dispatch('block-lmatPageTranslation/translate').slugSaveSource(content.slug_name);
    }

    // Register featured-image strings into the translation queue.
    if ( lmatPageTranslationGlobal.mediaSupport && content.featured_image ) {
        const fi = content.featured_image;
        [ 'title', 'alt', 'caption', 'description' ].forEach( ( field ) => {
            if ( fi[field] && fi[field].trim() !== '' ) {
                dispatch('block-lmatPageTranslation/translate').contentSaveSource(
                    `lmat_media_featured_${ field }`, fi[field]
                );
            }
        } );
    }

    // Register content-attachment strings into the translation queue.
    if ( lmatPageTranslationGlobal.mediaSupport && Array.isArray( content.elementor_media ) ) {
        content.elementor_media.forEach( ( attachment ) => {
            const attachId = attachment.id;
            [ 'title', 'alt', 'caption', 'description' ].forEach( ( field ) => {
                if ( attachment[field] && attachment[field].trim() !== '' ) {
                    dispatch('block-lmatPageTranslation/translate').contentSaveSource(
                        `lmat_media_${ attachId }_${ field }`, attachment[field]
                    );
                }
            } );
        } );
    }
}

export default ElementorSaveSource;
