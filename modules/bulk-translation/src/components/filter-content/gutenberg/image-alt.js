/** PHP-parsed image blocks keep their sourced alt attribute in the HTML. */
export const extractImageAlt = ( block ) => {
    if ( block.blockName !== 'core/image' ) return;
    const container = document.createElement( 'div' );
    container.innerHTML = block.innerHTML || '';
    const image = container.querySelector( 'img' );
    if ( image && image.hasAttribute( 'alt' ) ) {
        block.attrs = { ...block.attrs, alt: image.getAttribute( 'alt' ) };
    }
};

/** Update the HTML Gutenberg reads, preserving captions and nested-block slots. */
export const updateImageAlt = ( block, alt ) => {
    if ( block.blockName !== 'core/image' || typeof alt !== 'string' ) return;
    const container = document.createElement( 'div' );
    container.textContent = alt;
    const escapedAlt = container.innerHTML.replace( /"/g, '&quot;' );
    const updateHtml = ( html ) => typeof html === 'string'
        ? html.replace( /<img\b[^>]*>/gi, ( tag ) => {
            const attribute = /\salt\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/i;
            return attribute.test( tag )
                ? tag.replace( attribute, () => ` alt="${ escapedAlt }"` )
                : tag.replace( /\s*\/?>$/, ( ending ) => ` alt="${ escapedAlt }"${ ending }` );
        } )
        : html;
    block.attrs = { ...block.attrs, alt };
    block.innerHTML = updateHtml( block.innerHTML );
    if ( Array.isArray( block.innerContent ) ) {
        block.innerContent = block.innerContent.map( updateHtml );
    }
};
