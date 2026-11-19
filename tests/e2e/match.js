'use strict';
/**
 * Pure: which library service does a request URL belong to?
 *
 * Same host rule as CMP_Services::host_matches(): the URL's host equals the
 * pattern host or is a subdomain of it, and, when the pattern has a path
 * ('www.google.com/recaptcha'), the URL path starts with it. An embed's own
 * 'path' ('/maps') is appended to each of its hosts, as embed_service() does.
 *
 * Unlike the plugin (first match in library order), a request is attributed
 * to the MOST SPECIFIC rule: a host + path rule wins over a bare host, then a
 * longer host wins. So www.google.com/recaptcha/api.js is reCAPTCHA (not Maps)
 * and googletagmanager.com/gtag/js is Google Analytics (not GTM).
 */

/** 'host/path' -> { host, path } (lowercased host; path '' when none). */
function splitPattern(pattern) {
	const p = String(pattern || '').toLowerCase();
	const slash = p.indexOf('/');
	return slash === -1 ? { host: p, path: '' } : { host: p.slice(0, slash), path: p.slice(slash) };
}

/** Does `url` match 'host' or 'host/path' (exact host or subdomain, path prefix)? */
function hostMatches(url, pattern) {
	let u;
	try {
		u = new URL(String(url));
	} catch (e) {
		return false;
	}
	const { host, path } = splitPattern(pattern);
	const h = u.hostname.toLowerCase();
	if (!h || !host) {
		return false;
	}
	if (h !== host && !h.endsWith('.' + host)) {
		return false;
	}
	return path === '' || u.pathname.startsWith(path);
}

/** The match patterns of a service: hosts, with the embed 'path' appended. */
function patternsOf(service) {
	const extra = service && service.type === 'embed' && service.path ? String(service.path) : '';
	return ((service && service.hosts) || []).map((h) => String(h) + extra);
}

/** id of the service a URL belongs to (most specific rule), or null. */
function serviceFor(url, services) {
	let best = null;
	let bestScore = -1;
	for (const s of services || []) {
		for (const pattern of patternsOf(s)) {
			if (!hostMatches(url, pattern)) {
				continue;
			}
			const { host, path } = splitPattern(pattern);
			// Path rules first (by path length), then the longer host.
			const score = (path ? 1e6 + path.length * 1e3 : 0) + host.length;
			if (score > bestScore) {
				best = s.id;
				bestScore = score;
			}
		}
	}
	return best;
}

module.exports = { serviceFor, hostMatches, patternsOf, splitPattern };
