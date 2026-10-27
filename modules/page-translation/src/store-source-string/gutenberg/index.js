import FilterBlockNestedAttr from "../../component/filter-nested-attr/index.js";
import { dispatch, select } from "@wordpress/data";
import { serialize } from "@wordpress/blocks";
import { getRuleAttributeGroups, getXpathRuleAttributePaths } from "../../component/xpath-attribute-paths/index.js";
import { isTechnicalAttribute, isUserRuleAttribute } from "../../component/technical-value/index.js";

/**
 * Filters and translates attributes of a block.
 * 
 * @param {string} blockId - The ID of the block.
 * @param {Object} blockAttr - The attributes of the block.
 * @param {Object} filterAttr - The attributes to filter.
 * @param {Object} block - The full block, used to evaluate XPath rules against its markup.
 */
const filterTranslateAttr = (blockId, blockAttr, filterAttr, block) => {

    const filterAttrArr = getRuleAttributeGroups(filterAttr);
    const userRules = select('block-lmatPageTranslation/translate').getBlockRules()?.LmatUserBlockRules;

    /**
     * Saves translated attributes based on the provided ID array and filter attribute object.
     * 
     * @param {Array} idArr - The array of IDs.
     * @param {Object} filterAttrObj - The filter attribute object.
     */
    const saveTranslatedAttr = (idArr, filterAttrObj) => {
       
        if (true === filterAttrObj) {
            const newIdArr = new Array(...idArr);
            const childIdArr = new Array();

            let dynamicBlockAttr = blockAttr;
            let uniqueId = blockId;

            newIdArr.forEach(key => {
                childIdArr.push(key);
                uniqueId += `lmat_page_translation_${key}`;
                dynamicBlockAttr = dynamicBlockAttr ? dynamicBlockAttr[key] : dynamicBlockAttr;
            });

            let blockAttrContent = dynamicBlockAttr;

            if(blockAttrContent instanceof wp.richText.RichTextData) {
                blockAttrContent=blockAttrContent.originalHTML;
            }

          
            if (undefined !== blockAttrContent && typeof blockAttrContent === 'string' && blockAttrContent.trim() !== '') {

                let filterKey = uniqueId.replace(/[^\p{L}\p{N}]/gu, '');

                if (!/[\p{L}\p{N}]/gu.test(blockAttrContent) || (isTechnicalAttribute(newIdArr, blockAttrContent) && !isUserRuleAttribute(userRules, block?.name, newIdArr))) {
                    return false;
                }

                dispatch('block-lmatPageTranslation/translate').contentSaveSource(filterKey, blockAttrContent);
            }

            return;
        }

        FilterBlockNestedAttr(idArr,filterAttrObj,blockAttr,saveTranslatedAttr);
    }

    filterAttrArr.forEach(data => {
        Object.keys(data).forEach(key => {
            const idArr = new Array(key);
            saveTranslatedAttr(idArr, data[key]);
        });
    });

    // XPath rules: also save the attributes holding the selected text, skipping paths
    // already handled by attribute rules.
    if (block && Array.isArray(filterAttr?.xpaths) && filterAttr.xpaths.length > 0) {
        let blockMarkup = '';

        try {
            blockMarkup = serialize({ ...block, innerBlocks: [] });
        } catch (error) {
            blockMarkup = '';
        }

        getXpathRuleAttributePaths(blockMarkup, filterAttr, blockAttr).forEach(path => {
            saveTranslatedAttr(path, true);
        });
    }
}
/**
 * Retrieves the translation string for a block based on block rules and applies translation.
 * 
 * @param {Object} block - The block to translate.
 * @param {Object} blockRules - The rules for translating the block.
 */
const getTranslateString = (block, blockRules) => {
    const blockTranslateName = Object.keys(blockRules.LmatBlockParseRules);

    if (!blockTranslateName.includes(block.name)) {
        return;
    }

    filterTranslateAttr(block.clientId, block.attributes, blockRules['LmatBlockParseRules'][block.name], block);
}

/**
 * Recursively processes child block attributes for translation.
 * 
 * @param {Array} blocks - The array of blocks to translate.
 * @param {Object} blockRules - The rules for translating the blocks.
 */
const childBlockAttributesContent = (blocks, blockRules) => {
    blocks.forEach(block => {
        getTranslateString(block, blockRules);
        if (block.innerBlocks) {
            childBlockAttributesContent(block.innerBlocks, blockRules);
        }
    });
}

/**
 * Processes the attributes of a block for translation.
 * 
 * @param {Object} parseBlock - The block to parse for translation.
 * @param {Object} blockRules - The rules for translating the block.
 */
const blockAttributeContent = (parseBlock, blockRules) => {
    Object.values(parseBlock).forEach(block => {
        getTranslateString(block, blockRules);
        if (block.innerBlocks) {
            childBlockAttributesContent(block.innerBlocks, blockRules);
        }
    });
}

/**
 * Saves the translation for a block based on its attributes.
 * 
 * @param {Object} block - The block to save translation for.
 * @param {Object} blockRules - The rules for translating the block.
 */
const GutenbergBlockSaveSource = (block, blockRules) => {

    Object.keys(block).forEach(key => {
        if (key === 'content') {
            blockAttributeContent(block[key], blockRules);
        }else if(['title', 'excerpt'].includes(key)){
            if(block[key] && block[key].trim() !== ''){
                const action = `${key}SaveSource`;
                dispatch('block-lmatPageTranslation/translate')[action](block[key]);
            }
        }

        if(key === 'slug_name' && lmatPageTranslationGlobal.slug_translation_option === 'slug_translate'){
            if(block[key] && block[key].trim() !== ''){
                dispatch('block-lmatPageTranslation/translate').slugSaveSource(block[key]);
            }
        }

        // Register featured-image strings into the translation queue.
        if ( key === 'featured_image' && lmatPageTranslationGlobal.mediaSupport && block[key] ) {
            const fi = block[key];
            [ 'title', 'alt', 'caption', 'description' ].forEach( ( field ) => {
                if ( fi[field] && fi[field].trim() !== '' ) {
                    dispatch('block-lmatPageTranslation/translate').contentSaveSource(
                        `lmat_media_featured_${ field }`, fi[field]
                    );
                }
            } );
        }

        // Register content-attachment strings into the translation queue.
        if ( key === 'content_media' && lmatPageTranslationGlobal.mediaSupport && Array.isArray( block[key] ) ) {
            block[key].forEach( ( attachment ) => {
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
    });
}

export default GutenbergBlockSaveSource;