// Prints, for one JSON file of { rules, base, urls }, how Node's URLPattern,
// the matcher browsers use for href_matches, reads a page's speculation
// rules, each pattern resolved against the site's address: which URLs each
// pattern matches, and which the prerender rule as a whole would prerender.
// For DocumentShellTest (I-160).
import { readFileSync } from 'node:fs';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const urls = input.urls.map((url) => new URL(url, input.base).href);

const patterns = [];
const matches = (predicate, url) => {
    if ('and' in predicate) {
        return predicate.and.every((part) => matches(part, url));
    }

    if ('or' in predicate) {
        return predicate.or.some((part) => matches(part, url));
    }

    if ('not' in predicate) {
        return !matches(predicate.not, url);
    }

    if ('href_matches' in predicate) {
        return new URLPattern(predicate.href_matches, input.base).test(url);
    }

    throw new Error(`An unknown predicate: ${JSON.stringify(predicate)}`);
};
const collect = (predicate) => {
    for (const part of [...(predicate.and ?? []), ...(predicate.or ?? []), ...(predicate.not ? [predicate.not] : [])]) {
        collect(part);
    }

    if ('href_matches' in predicate) {
        patterns.push(predicate.href_matches);
    }
};

const where = input.rules.prerender[0].where;
collect(where);

process.stdout.write(JSON.stringify({
    patterns: Object.fromEntries(patterns.map((pattern) => [pattern, Object.fromEntries(input.urls.map((url, i) => [url, new URLPattern(pattern, input.base).test(urls[i])]))])),
    prerendered: Object.fromEntries(input.urls.map((url, i) => [url, matches(where, urls[i])])),
}));
