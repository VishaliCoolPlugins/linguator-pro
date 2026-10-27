import { select, dispatch } from '@wordpress/data';
import { parse, serialize } from '@wordpress/blocks';

/**
 * Media string key prefix used when saving media strings into the translation store.
 * Must match the prefix used in store-source-string/gutenberg/index.js.
 */
const MEDIA_KEY_PREFIX = 'lmat_media_';

/**
 * Remap attachment IDs inside serialized block / classic HTML using a source=>target map.
 *
 * @param {string} content
 * @param {Object<string, number>} mediaMap
 * @return {string}
 */
export const remapContentMediaIds = ( content, mediaMap ) => {
        if ( typeof content !== 'string' || ! content || ! mediaMap || typeof mediaMap !== 'object' ) {
                return content;
        }

        const entries = Object.keys( mediaMap )
                .map( ( sourceId ) => [ Number( sourceId ), Number( mediaMap[ sourceId ] ) ] )
                .filter( ( [ sourceId, targetId ] ) => sourceId > 0 && targetId > 0 && sourceId !== targetId )
                // Replace longer IDs first to avoid partial overlaps (e.g. 10 before 1).
                .sort( ( a, b ) => String( b[0] ).length - String( a[0] ).length );

        let out = content;
        entries.forEach( ( [ sourceId, targetId ] ) => {
                const src = String( sourceId );
                const tr = String( targetId );
                out = out.replace( new RegExp( `wp-image-${ src }\\b`, 'g' ), `wp-image-${ tr }` );
                out = out.replace( new RegExp( `data-id="${ src }"`, 'g' ), `data-id="${ tr }"` );
                out = out.replace( new RegExp( `attachment_id=${ src }\\b`, 'g' ), `attachment_id=${ tr }` );
                // [caption id="attachment_N"] (and its TinyMCE <dl id="attachment_N"> view).
                out = out.replace( new RegExp( `(^|[^\\w-])id="attachment_${ src }"`, 'g' ), `$1id="attachment_${ tr }"` );
                out = out.replace( new RegExp( `"id":${ src }([,}])`, 'g' ), `"id":${ tr }$1` );
                out = out.replace( new RegExp( `"mediaId":${ src }([,}])`, 'g' ), `"mediaId":${ tr }$1` );
                out = out.replace( new RegExp( `"ids":\\[([^\\]]*)\\]`, 'g' ), ( match, inner ) => {
                        const remapped = inner.split( ',' ).map( ( part ) => {
                                const trimmed = part.trim();
                                return trimmed === src ? tr : trimmed;
                        } );
                        return `"ids":[${ remapped.join( ',' ) }]`;
                } );
        } );

        return out;
};

/**
 * Apply a media_map to the active Gutenberg or Classic editor contents.
 *
 * @param {Object<string, number>} mediaMap
 * @return {void}
 */
export const applyMediaMapToEditor = ( mediaMap, featuredSourceId = 0 ) => {
        if ( ! mediaMap || typeof mediaMap !== 'object' || Object.keys( mediaMap ).length === 0 ) {
                return;
        }

        // Featured image: prefer explicit source id from the translation payload, else current editor value.
        try {
                const currentFeatured = select( 'core/editor' )?.getEditedPostAttribute?.( 'featured_media' );
                const sourceFeatured = featuredSourceId > 0 ? featuredSourceId : Number( currentFeatured || 0 );
                if ( sourceFeatured > 0 && mediaMap[ String( sourceFeatured ) ] ) {
                        dispatch( 'core/editor' ).editPost( {
                                featured_media: Number( mediaMap[ String( sourceFeatured ) ] ),
                        } );
                }
        } catch ( e ) {
                // Editor store may be unavailable outside Gutenberg.
        }

        // Gutenberg blocks.
        try {
                const blockEditor = select( 'core/block-editor' );
                if ( blockEditor && typeof blockEditor.getBlocks === 'function' ) {
                        const blocks = blockEditor.getBlocks();
                        if ( Array.isArray( blocks ) && blocks.length > 0 ) {
                                const serialized = serialize( blocks );
                                const remapped = remapContentMediaIds( serialized, mediaMap );
                                if ( remapped !== serialized ) {
                                        dispatch( 'core/block-editor' ).resetBlocks( parse( remapped ) );
                                }
                                return;
                        }
                }
        } catch ( e ) {
                // Fall through to classic.
        }

        // Classic editor (TinyMCE / textarea#content).
        try {
                if ( typeof window !== 'undefined' && window.tinymce && window.tinymce.get( 'content' ) ) {
                        const editor = window.tinymce.get( 'content' );
                        const html = editor.getContent( { format: 'raw' } );
                        const remapped = remapContentMediaIds( html, mediaMap );
                        if ( remapped !== html ) {
                                editor.setContent( remapped );
                        }
                }
                const textarea = typeof document !== 'undefined' ? document.querySelector( 'textarea#content' ) : null;
                if ( textarea && typeof textarea.value === 'string' ) {
                        const remapped = remapContentMediaIds( textarea.value, mediaMap );
                        if ( remapped !== textarea.value ) {
                                textarea.value = remapped;
                        }
                }
        } catch ( e ) {
                console.error( 'lmat: failed to remap classic editor media IDs', e );
        }
};

/**
 * Sends translated attachment metadata to the server via AJAX.
 *
 * @param {string} service     The active AI provider key (e.g. 'gemini', 'openai').
 * @param {Object} postContent The original post data returned by the server.
 * @return {Promise<{ok: boolean, mediaMap: Object<string, number>}>}
 */
const saveMediaTranslations = async ( service, postContent ) => {
    if ( ! lmatPageTranslationGlobal.mediaSupport ) {
                return { ok: true, skipped: true, mediaMap: {} };
        }

    const ajaxUrl  = lmatPageTranslationGlobal.ajax_url;
    const nonce    = lmatPageTranslationGlobal.save_media_nonce;
    const action   = lmatPageTranslationGlobal.save_media_translations;
    const postId   = lmatPageTranslationGlobal.current_post_id;
    const parentId = lmatPageTranslationGlobal.parent_post_id;

    if ( ! ajaxUrl || ! nonce || ! action || ! postId || ! parentId ) {
                return { ok: false, mediaMap: {} };
        }

    const allEntries = select( 'block-lmatPageTranslation/translate' ).getTranslationEntries();
    const mediaEntries = allEntries.filter(
        ( e ) => e.type === 'content' && typeof e.id === 'string' && e.id.startsWith( MEDIA_KEY_PREFIX )
    );

    if ( mediaEntries.length === 0 ) {
                return { ok: true, skipped: true, mediaMap: {} };
        }

    const getTranslated = ( storeKey ) => {
        const entry = mediaEntries.find( ( e ) => e.id === storeKey );
        if ( entry && entry.translatedData && typeof entry.translatedData[ service ] === 'string' ) {
            return entry.translatedData[ service ].trim();
        }
        return '';
    };

    const buildTranslatedFields = ( attachment, keyPrefix ) => {
        const translated = {};
        [ 'title', 'alt', 'caption', 'description' ].forEach( ( field ) => {
            if ( ! attachment[ field ] ) return;
            const value = getTranslated( `${ keyPrefix }${ field }` );
            if ( value ) translated[ field ] = value;
        } );
        return translated;
    };

    // Featured image.
    let featuredImage = null;
    if ( postContent && postContent.featured_image ) {
        const fi = postContent.featured_image;
        const translated = buildTranslatedFields( fi, `${ MEDIA_KEY_PREFIX }featured_` );
        if ( Object.keys( translated ).length > 0 ) featuredImage = translated;
    }

    // Content and Elementor media use the same attachment-key format.
    const sourceMedia = [
        ...( Array.isArray( postContent?.content_media ) ? postContent.content_media : [] ),
        ...( Array.isArray( postContent?.elementor_media ) ? postContent.elementor_media : [] ),
    ];
    const mediaById = new Map();
    sourceMedia.forEach( ( attachment ) => {
        if ( attachment?.id ) mediaById.set( Number( attachment.id ), attachment );
    } );

    const contentMedia = [];
    mediaById.forEach( ( attachment, attachmentId ) => {
        const translated = buildTranslatedFields( attachment, `${ MEDIA_KEY_PREFIX }${ attachmentId }_` );
        if ( Object.keys( translated ).length > 0 ) {
            contentMedia.push( { id: attachmentId, ...translated } );
        }
    } );

    if ( ! featuredImage && contentMedia.length === 0 ) {
                return { ok: true, skipped: true, mediaMap: {} };
        }

    const requestBody = {
        action:           action,
        post_id:          postId,
        source_post_id:   parentId,
        lmat_media_nonce: nonce,
    };
    if ( featuredImage ) requestBody.featured_image = JSON.stringify( featuredImage );
    if ( contentMedia.length > 0 ) requestBody.content_media = JSON.stringify( contentMedia );

    try {
        const response = await fetch( ajaxUrl, {
        method:  'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded; charset=UTF-8', Accept: 'application/json' },
        body:    new URLSearchParams( requestBody ),
        } );
        const result = await response.json();
        if ( ! response.ok || ! result.success || ( result.data && result.data.updated === false ) ) {
            console.error( 'lmat: media translations were not saved', result.data || result );
            return { ok: false, mediaMap: {} };
        }

                const mediaMap = result?.data?.media_map && typeof result.data.media_map === 'object'
                        ? result.data.media_map
                        : {};

                if ( Object.keys( mediaMap ).length > 0 ) {
                        const featuredSourceId = postContent?.featured_image?.id
                                ? Number( postContent.featured_image.id )
                                : 0;
                        applyMediaMapToEditor( mediaMap, featuredSourceId );
                }

        return { ok: true, mediaMap };
    } catch ( error ) {
        console.error( 'lmat: saveMediaTranslations error', error );
        return { ok: false, mediaMap: {} };
    }
};

export default saveMediaTranslations;
