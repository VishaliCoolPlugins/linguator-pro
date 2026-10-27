import { isTechnicalAttribute, isTechnicalAttributeKey } from '../technical-value/index.js';

/**
 * Returns the attribute rule groups of a block rule, leaving out the XPath list.
 *
 * @param {Object} blockRule Block rule, e.g. { attributes: {...}, xpaths: [...] }.
 * @return {Array} Attribute rule objects.
 */
export const getRuleAttributeGroups = ( blockRule ) => {
	if ( ! blockRule || 'object' !== typeof blockRule ) {
		return [];
	}

	return Object.keys( blockRule )
		.filter( ( key ) => 'xpaths' !== key )
		.map( ( key ) => blockRule[ key ] )
		.filter( ( group ) => group && 'object' === typeof group );
};

/**
 * Checks whether an attribute path is already handled by the attribute rules of a block.
 *
 * Array rules (wildcards) match any array index and apply their inner rule to
 * object values, mirroring how attribute rules are walked.
 *
 * @param {Array} path   Attribute path, an array of keys.
 * @param {Array} groups Attribute rule objects.
 * @return {boolean} True when an attribute rule covers the path.
 */
const isPathCoveredByRules = ( path, groups ) =>
	groups.some( ( group ) => {
		let rule = group;

		for ( const key of path ) {
			if ( true === rule ) {
				return true;
			}

			if ( Array.isArray( rule ) ) {
				rule = rule[ 0 ];

				if ( 'number' === typeof key ) {
					continue;
				}
			}

			if (
				! rule ||
				'object' !== typeof rule ||
				! Object.prototype.hasOwnProperty.call( rule, key )
			) {
				return false;
			}

			rule = rule[ key ];
		}

		return true === rule;
	} );

/**
 * Returns the attribute paths selected by the XPath rules of a block that are not
 * already handled by its attribute rules.
 *
 * @param {string} html      Serialized block markup.
 * @param {Object} blockRule Block rule, e.g. { attributes: {...}, xpaths: [...] }.
 * @param {Object} attributes Block attributes.
 * @param {Object} win       Window providing DOMParser, XPathResult and Node.
 * @return {Array} List of attribute paths, each an array of keys.
 */
export const getXpathRuleAttributePaths = (
	html,
	blockRule,
	attributes,
	win = window
) => {
	if (
		! blockRule ||
		! Array.isArray( blockRule.xpaths ) ||
		0 === blockRule.xpaths.length
	) {
		return [];
	}

	const groups = getRuleAttributeGroups( blockRule );

	return getXpathAttributePaths(
		html,
		blockRule.xpaths,
		attributes,
		win
	).filter( ( path ) => ! isPathCoveredByRules( path, groups ) );
};

/**
 * Converts an HTML fragment to its plain text with entities decoded and whitespace collapsed.
 *
 * @param {string}   html Markup or text.
 * @param {Document} doc  Inert document used to decode the markup.
 * @return {string} Normalized text.
 */
const toPlainText = ( html, doc ) => {
	const container = doc.createElement( 'div' );
	container.innerHTML = html;

	return ( container.textContent || '' ).replace( /\s+/g, ' ' ).trim();
};

/**
 * Returns the string value of an attribute, reading rich text values as HTML.
 *
 * @param {*} value Attribute value.
 * @return {string|null} String value, or null when the value is not textual.
 */
const getStringValue = ( value ) => {
	if ( 'string' === typeof value ) {
		return value;
	}

	if (
		value &&
		'object' === typeof value &&
		'string' === typeof value.originalHTML
	) {
		return value.originalHTML;
	}

	return null;
};

/**
 * Finds the attribute paths of a block whose text matches the nodes selected by XPath rules.
 *
 * The block markup is evaluated against each XPath; every non-empty matched text
 * (element text or attribute value) is compared with the decoded text of each
 * string attribute. All matching attribute paths are returned, so repeated texts
 * in different attributes are each picked up.
 *
 * @param {string} html       Serialized block markup.
 * @param {Array}  xpaths     XPath selectors.
 * @param {Object} attributes Block attributes.
 * @param {Object} win        Window providing DOMParser, XPathResult and Node.
 * @return {Array} List of attribute paths, each an array of keys.
 */
export const getXpathAttributePaths = (
	html,
	xpaths,
	attributes,
	win = window
) => {
	const paths = [];

	if (
		! html ||
		'string' !== typeof html ||
		! Array.isArray( xpaths ) ||
		! attributes ||
		'object' !== typeof attributes
	) {
		return paths;
	}

	const doc = new win.DOMParser().parseFromString( html, 'text/html' );
	const matchedTexts = new Set();

	xpaths.forEach( ( xpath ) => {
		let result = null;

		try {
			result = doc.evaluate(
				xpath,
				doc,
				null,
				win.XPathResult.ORDERED_NODE_SNAPSHOT_TYPE,
				null
			);
		} catch ( error ) {
			return;
		}

		for ( let index = 0; index < result.snapshotLength; index++ ) {
			const node = result.snapshotItem( index );
			const rawText =
				win.Node.ATTRIBUTE_NODE === node.nodeType
					? node.value
					: node.textContent;
			const text = ( rawText || '' ).replace( /\s+/g, ' ' ).trim();

			if ( '' !== text ) {
				matchedTexts.add( text );
			}
		}
	} );

	if ( 0 === matchedTexts.size ) {
		return paths;
	}

	const walk = ( value, currentPath ) => {
		const stringValue = getStringValue( value );

		if ( null !== stringValue ) {
			if (
				'' !== stringValue.trim() &&
				! isTechnicalAttribute( currentPath, stringValue ) &&
				matchedTexts.has( toPlainText( stringValue, doc ) )
			) {
				paths.push( currentPath );
			}
			return;
		}

		if ( ! value || 'object' !== typeof value ) {
			return;
		}

		Object.keys( value ).forEach( ( key ) => {
			if ( isTechnicalAttributeKey( key ) ) {
				return;
			}

			const childKey = Array.isArray( value ) ? Number( key ) : key;
			walk( value[ key ], [ ...currentPath, childKey ] );
		} );
	};

	walk( attributes, [] );

	return paths;
};
