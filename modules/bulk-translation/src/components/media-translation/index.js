import storeSourceString from '../store-source-string/index.js';
import { store } from '../../redux-store/store.js';
import normalizeBulkTranslationEscapes from '../../utils/normalize-bulk-translation-escapes.js';

const MEDIA_KEY_PREFIX = 'media_';
const MEDIA_FIELDS = [ 'title', 'alt', 'caption', 'description' ];

const storeAttachmentFields = ( postId, attachment, keyPrefix, storeDispatch ) => {
    if ( ! attachment || typeof attachment !== 'object' ) return;

    MEDIA_FIELDS.forEach( ( field ) => {
        const value = attachment[ field ];
        if ( typeof value === 'string' && value.trim() !== '' ) {
            storeSourceString( postId, `${ keyPrefix }${ field }`, value, value, storeDispatch );
        }
    } );
};

export const storeMediaStrings = ( { postId, featuredImage, contentMedia, elementorMedia, storeDispatch } ) => {
    if ( ! window.lmatBulkTranslationGlobal?.mediaSupport ) return;

    storeAttachmentFields( postId, featuredImage, `${ MEDIA_KEY_PREFIX }featured_`, storeDispatch );

    const attachments = [
        ...( Array.isArray( contentMedia ) ? contentMedia : [] ),
        ...( Array.isArray( elementorMedia ) ? elementorMedia : [] ),
    ];
    const uniqueAttachments = new Map();
    attachments.forEach( ( attachment ) => {
        const attachmentId = Number( attachment?.id );
        if ( attachmentId > 0 ) uniqueAttachments.set( attachmentId, attachment );
    } );

    uniqueAttachments.forEach( ( attachment, attachmentId ) => {
        storeAttachmentFields( postId, attachment, `${ MEDIA_KEY_PREFIX }${ attachmentId }_`, storeDispatch );
    } );
};

const getTranslatedField = ( postId, key, language, provider ) => {
    const value = store.getState().translatedContent[ postId ]?.[ key ]?.translation?.[ provider ]?.[ language ];
    if ( typeof value !== 'string' ) return '';
    return normalizeBulkTranslationEscapes( value ).trim();
};

const buildAttachmentPayload = ( postId, attachment, keyPrefix, language, provider ) => {
    const payload = {};

    MEDIA_FIELDS.forEach( ( field ) => {
        if ( typeof attachment?.[ field ] !== 'string' || attachment[ field ].trim() === '' ) return;
        const translated = getTranslatedField( postId, `${ keyPrefix }${ field }`, language, provider );
        if ( translated ) payload[ field ] = translated;
    } );

    return payload;
};

const buildAttachmentList = ( postId, attachments, language, provider ) => {
    if ( ! Array.isArray( attachments ) ) return [];

    return attachments.reduce( ( payload, attachment ) => {
        const attachmentId = Number( attachment?.id );
        if ( attachmentId <= 0 ) return payload;

        const fields = buildAttachmentPayload(
            postId,
            attachment,
            `${ MEDIA_KEY_PREFIX }${ attachmentId }_`,
            language,
            provider
        );
        if ( Object.keys( fields ).length > 0 ) payload.push( { id: attachmentId, ...fields } );
        return payload;
    }, [] );
};

export const buildMediaPayload = ( { postId, source, language, provider } ) => {
    if ( ! window.lmatBulkTranslationGlobal?.mediaSupport ) return {};

    const payload = {};
    if ( source.featured_image ) {
        const featuredImage = buildAttachmentPayload(
            postId,
            source.featured_image,
            `${ MEDIA_KEY_PREFIX }featured_`,
            language,
            provider
        );
        if ( Object.keys( featuredImage ).length > 0 ) payload.featured_image = featuredImage;
    }

    const contentMedia = buildAttachmentList( postId, source.content_media, language, provider );
    if ( contentMedia.length > 0 ) payload.content_media = contentMedia;

    const elementorMedia = buildAttachmentList( postId, source.elementor_media, language, provider );
    if ( elementorMedia.length > 0 ) payload.elementor_media = elementorMedia;

    return payload;
};
