/**
 * Attribute keys that always hold technical values and are never translated.
 */
const NON_TRANSLATABLE_KEYS = [
	'url',
	'href',
	'src',
	'id',
	'type',
	'tag',
	'link',
	'target',
	'rel',
	'class',
	'anchor',
	'className',
	'providerNameSlug',
	'linkDestination',
	'sizeSlug',
	'redirect',
	'language',
];

/**
 * Key suffixes that mark technical values, e.g. ctaType, headingTag, customUrl, ctaLink.
 */
const NON_TRANSLATABLE_KEY_SUFFIX = /[a-z0-9](Type|Tag|Url|URL|Link|Href|Id|ID|Class|ClassName|Target|Rel|Slug|View|Color|Align|Position)$/;

/**
 * Key fragments that mark human readable text, which wins over a technical suffix, e.g. imageAltTag, linkText.
 */
const TRANSLATABLE_KEY_FRAGMENT = /(Text|Title|Label|Alt|Caption|Desc|Content|Message)/;

/**
 * Key fragments that mark human readable text in any letter case, e.g. content, text, value, citation, headingTitle.
 * Values of these keys are never skipped based on their value alone.
 */
const TEXT_KEY_FRAGMENT = /(text|title|label|alt|caption|desc|content|message|value|citation|placeholder|heading|body|quote|summary|excerpt|name)/i;

/**
 * Value patterns that are never translated: URLs, paths and anchors, emails, colors, numbers
 * with optional CSS units, heading tags and hyphenated or underscored lowercase slugs.
 */
const NON_TRANSLATABLE_VALUE_PATTERNS = [
	/^(https?:)?\/\/\S+$/i,
	/^(mailto|tel):\S+$/i,
	/^www\.\S+$/i,
	/^[#/?]\S*$/,
	/^[^\s@]+@[^\s@]+\.[^\s@]+$/,
	/^#[0-9a-f]{3,8}$/i,
	/^(rgba?|hsla?|var)\([^)]*\)$/i,
	/^-?\d+(\.\d+)?(px|em|rem|%|vh|vw|vmin|vmax|pt|s|ms|deg|fr)?$/i,
	/^h[1-6]$/i,
	/^[a-z0-9]+([-_][a-z0-9]+)+$/,
];

/**
 * Checks whether an attribute key names a technical value.
 *
 * @param {string|number} key Attribute key.
 * @return {boolean} True when the key holds a technical value.
 */
export const isTechnicalAttributeKey = ( key ) => {
	if ( 'string' !== typeof key || '' === key ) {
		return false;
	}

	if ( NON_TRANSLATABLE_KEYS.includes( key ) ) {
		return true;
	}

	return (
		NON_TRANSLATABLE_KEY_SUFFIX.test( key ) &&
		! TRANSLATABLE_KEY_FRAGMENT.test( key )
	);
};

/**
 * Checks whether a string value is a technical value, such as a URL, email, color, number or slug.
 *
 * @param {*} value Attribute value.
 * @return {boolean} True when the value is technical.
 */
export const isTechnicalValue = ( value ) => {
	if ( 'string' !== typeof value ) {
		return false;
	}

	const trimmed = value.trim();

	if ( '' === trimmed ) {
		return false;
	}

	return NON_TRANSLATABLE_VALUE_PATTERNS.some( ( pattern ) =>
		pattern.test( trimmed )
	);
};

/**
 * Checks whether an attribute must be left untranslated, based on the last named key of its path and its value.
 *
 * @param {Array} path  Attribute path, an array of keys.
 * @param {*}     value Attribute value.
 * @return {boolean} True when the attribute is technical.
 */
export const isTechnicalAttribute = ( path, value ) => {
	const keys = Array.isArray( path ) ? path : [ path ];
	const lastNamedKey = [ ...keys ]
		.reverse()
		.find( ( key ) => 'string' === typeof key && ! /^\d+$/.test( key ) );

	if ( isTechnicalAttributeKey( lastNamedKey ) ) {
		return true;
	}

	if ( 'string' === typeof lastNamedKey && TEXT_KEY_FRAGMENT.test( lastNamedKey ) ) {
		return false;
	}

	return isTechnicalValue( value );
};

/**
 * Checks whether an attribute path is set by a rule the user added for the block.
 *
 * Array levels of the rule apply to every item, so numeric indices in the path follow the first rule entry.
 *
 * @param {Object} userRules User-added rules keyed by block name, e.g. { 'my/block': { attributes: { type: true } } }.
 * @param {string} blockName Block name.
 * @param {Array}  path      Attribute path, an array of keys.
 * @return {boolean} True when the user rule marks the path as translatable.
 */
export const isUserRuleAttribute = ( userRules, blockName, path ) => {
	let rule = userRules?.[ blockName ]?.attributes;

	for ( const key of Array.isArray( path ) ? path : [ path ] ) {
		if ( Array.isArray( rule ) ) {
			rule = rule[ 0 ];
		} else if ( rule && 'object' === typeof rule ) {
			rule = rule[ key ];
		} else {
			return false;
		}
	}

	return true === rule;
};
