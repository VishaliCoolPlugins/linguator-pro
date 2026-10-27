/**
 * Turn literal \n, \r, \t (backslash + letter) into real control characters after DOM / API reads.
 * Matches server-side `Linguator\Modules\REST\V1\Bulk_Translation::ai_normalize_translation_string()`.
 * Repeats until stable (max 5 passes) so doubled escapes from the model (e.g. "\\n") collapse correctly.
 * Also removes up to three layers of stray leading/trailing ASCII `"` some models add per value.
 *
 * @param {string} value
 * @returns {string}
 */
export default function normalizeBulkTranslationEscapes(value) {
	if (typeof value !== "string" || value === "") {
		return value;
	}
	let out = value;
	for (let i = 0; i < 5; i++) {
		const next = out.replace(/\\n/g, "\n").replace(/\\r/g, "\r").replace(/\\t/g, "\t");
		if (next === out) {
			break;
		}
		out = next;
	}
	// Model sometimes returns values with an extra JSON-like quote wrapper after decode.
	for (let j = 0; j < 3; j++) {
		const t = out.trim();
		if (t.length >= 2 && t.startsWith('"') && t.endsWith('"')) {
			out = t.slice(1, -1);
		} else {
			break;
		}
	}
	return out;
}
