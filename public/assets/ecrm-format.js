/* Energy CRM — turning stored values into what a person reads.
 *
 * Pure functions, every one: same input, same output, no DOM, no network, no
 * state. That is the whole entry requirement for this module, and it is what
 * makes them safe to pull out of the app shell ahead of the views that use
 * them — nothing here can behave differently for having moved.
 *
 * Anything that touches the document, the clipboard or the network stays in
 * the view that owns it.
 */

/** Greek uppercase without the accents, which uppercase Greek does not take. */
export function up(str) {
	try {
		return String(str).toLocaleUpperCase('el').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
	} catch (e) {
		return String(str).toUpperCase();
	}
}

export function energyLabel(t) {
	return t === 'gas' ? 'Φυσικό Αέριο' : (t === 'mobile' ? 'Κινητή Τηλεφωνία' : 'Ηλεκτρισμός');
}

/**
 * Stored timestamps are UTC without a zone marker, so the Z is appended before
 * parsing. Without it the browser reads them as local time and every date is
 * wrong by the offset — two or three hours in Greece, which is enough to move
 * a contract to the previous day.
 */
export function fmtDate(iso) {
	if (!iso) return '';

	var d = new Date(iso.replace(' ', 'T') + 'Z');
	var p = function (n) { return (n < 10 ? '0' : '') + n; };

	return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear();
}

/** Same UTC caveat as fmtDate. */
export function timeAgo(iso) {
	if (!iso) return '';

	var d = new Date(iso.replace(' ', 'T') + 'Z'), s = (Date.now() - d.getTime()) / 1000;

	if (s < 60) return 'μόλις τώρα';
	if (s < 3600) return Math.floor(s / 60) + 'λ πριν';
	if (s < 86400) return Math.floor(s / 3600) + 'ω πριν';

	return Math.floor(s / 86400) + 'μ πριν';
}

/** Two letters for the avatar circle. */
export function initials(name) {
	var parts = (name || '').trim().split(/\s+/).filter(Boolean);
	var s = (parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '');

	return (s || (name || '?')[0] || '?').toUpperCase();
}

/**
 * A stable hue from a name, so the same customer keeps the same colour
 * everywhere without anyone storing a colour.
 */
export function tint(str) {
	var h = 0;
	str = str || '';

	for (var i = 0; i < str.length; i++) {
		h = (h * 31 + str.charCodeAt(i)) % 360;
	}

	return h;
}

/**
 * Inline SVG, so an icon costs no request and inherits currentColor.
 *
 * The paths are ours and contain no interpolation — the only reason this
 * returns markup rather than text.
 */
export function svgIcon(name) {
	var p = {
		phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.7a2 2 0 0 1-.5 2.1L8 9.6a16 16 0 0 0 6 6l1.1-1.1a2 2 0 0 1 2.1-.5c.9.3 1.8.5 2.7.6a2 2 0 0 1 1.7 2z"/>',
		viber: '<path d="M12 3c4.5 0 8 3.2 8 7.4 0 4.2-3.5 7.4-8 7.4-.7 0-1.4-.1-2-.2L5 19l1-3.2C4.7 14.5 4 12.6 4 10.4 4 6.2 7.5 3 12 3z"/><path d="M9.5 8c.6 1.8 2 3.2 3.8 3.8"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
		edit: '<path d="M11 4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2v-6"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
		trash: '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
	};

	return '<svg class="ecrm-bico" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
		+ 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
		+ (p[name] || '') + '</svg>';
}
