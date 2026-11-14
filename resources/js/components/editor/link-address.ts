// What a link may point to, as the server stores it (DocumentSchema and
// DocumentNormaliser, I-452). The editor's link, its paste cleaning and its
// renderer all use these rules, so the editor makes and shows only what the
// server keeps.

/** The kinds a link by ID may lead to (DocumentSchema::LINK_TARGETS, I-1702). */
export type LinkKind = 'content' | 'term' | 'user' | 'media';

/** What a link by ID leads to, its ID, and the place in it, `#…` or '' for none. */
export type LinkTarget = { kind: LinkKind; id: string; place: string };

/**
 * Each kind's ID as its table keeps it (I-418): an article's and a term's
 * lowercase, a library file's uppercase, a person's a whole number.
 */
const LINK_TARGETS: Record<LinkKind, string> = {
    content: '[0-9a-hjkmnp-tv-z]{26}',
    term: '[0-9a-hjkmnp-tv-z]{26}',
    user: '[1-9][0-9]{0,17}',
    media: '[0-9A-HJKMNP-TV-Z]{26}',
};

/** A place after a link by ID: `#` and the characters a fragment holds. */
const PLACE = "#[A-Za-z0-9_.~!$&'()*+,;=:@/?%-]+";

/** A place on the same page: `#` and the characters RFC 3986 allows in a fragment. */
const FRAGMENT = /^#[A-Za-z0-9_.~!$&'()*+,;=:@/?%-]+$/;

/** A character a fragment may not hold as it is, and is percent-encoded. */
const NOT_IN_FRAGMENT = /[^A-Za-z0-9_.~!$&'()*+,;=:@/?%-]/gu;

/** A path on this site with its query and place (I-1669). */
const SITE_PATH = /^\/(?!\/)[A-Za-z0-9_.~!$&'()*+,;=:@/?#%-]*$/;

/** A character a site path may not hold as it is, and is percent-encoded. */
const NOT_IN_SITE_PATH = /[^A-Za-z0-9_.~!$&'()*+,;=:@/?#%-]/gu;

/** An address's scheme, such as `https` in `https://…`. */
const SCHEME = /^([a-z][a-z0-9+.-]*):/i;

/** The schemes an external link may use (DocumentSchema::PROTOCOLS). */
const PROTOCOLS = ['http', 'https', 'mailto', 'tel'];

/** The longest address the server stores (DocumentSchema::linkAttributes). */
const MAX_LENGTH = 2048;

/** Whether an address is a place on the same page. */
export function isFragment(href: string): boolean {
    return FRAGMENT.test(href);
}

/** Whether an address is a path on this site. */
export function isSitePath(href: string): boolean {
    return SITE_PATH.test(href);
}

/** What a link by ID leads to and the place in it, or null (DocumentSchema::linkTarget). */
export function linkTarget(href: string): LinkTarget | null {
    for (const [kind, id] of Object.entries(LINK_TARGETS) as [
        LinkKind,
        string,
    ][]) {
        const link = new RegExp(`^teme:${kind}/(${id})(${PLACE})?$`).exec(href);

        if (link !== null) {
            return { kind, id: link[1], place: link[2] ?? '' };
        }
    }

    return null;
}

/** Whether the server stores a link to this address as it is. */
export function allowsHref(href: string): boolean {
    // PHP's parse_url() reads no scheme from `https:///…`, so the server
    // refuses it.
    if (/^[a-z][a-z0-9+.-]*:\/\/\//i.test(href)) {
        return false;
    }

    const scheme = SCHEME.exec(href)?.[1]?.toLowerCase();

    return (
        linkTarget(href) !== null ||
        SITE_PATH.test(href) ||
        FRAGMENT.test(href) ||
        (scheme !== undefined && PROTOCOLS.includes(scheme))
    );
}

/** The text with each character the pattern finds percent-encoded, or null. */
function encoded(text: string, pattern: RegExp): string | null {
    try {
        return text.replace(pattern, (character) =>
            encodeURIComponent(character),
        );
    } catch {
        // A lone surrogate, which no address can hold.
        return null;
    }
}

/** A place with its other characters percent-encoded, or null when it names nothing. */
function fragment(href: string): string | null {
    const place = encoded(href.slice(1), NOT_IN_FRAGMENT);

    return place === null || place === '' ? null : `#${place}`;
}

/** A host as this site compares it: lower-cased, without a leading `www.`. */
function hostOf(host: string): string {
    return host.toLowerCase().replace(/^www\./, '');
}

/**
 * The path, query and place of an address on this site written in full, or
 * null for any other address, or when no site is given
 * (DocumentNormaliser::pathOnThisSite). URL leaves out a scheme's own port.
 */
function pathOnThisSite(href: string, site: string | null): string | null {
    if (site === null || !/^https?:\/\//i.test(href)) {
        return null;
    }

    try {
        const address = new URL(href);
        const own = new URL(site);

        return address.username === '' &&
            hostOf(address.hostname) === hostOf(own.hostname) &&
            address.port === own.port
            ? `${address.pathname}${address.search}${address.hash}`
            : null;
    } catch {
        // Not an address URL can read, such as `https:///x`.
        return null;
    }
}

/**
 * A link by ID as the server repairs it (DocumentNormaliser::byId): its
 * kind lower-cased, its ID in its table's case, its place encoded; null
 * when it names no kind and ID a link may have, or a query follows.
 */
function byId(href: string): string | null {
    const link = /^teme:([a-z]+)\/([0-9a-z]+)(#[\s\S]*)?$/i.exec(href);

    if (link === null) {
        return null;
    }

    const kind = link[1].toLowerCase();
    const id = kind === 'media' ? link[2].toUpperCase() : link[2].toLowerCase();
    const place = link[3] === undefined ? '' : (fragment(link[3]) ?? '');
    const kept = `teme:${kind}/${id}${place}`;

    return linkTarget(kept) === null ? null : kept;
}

/**
 * The address a link keeps, or null when the link goes and its words stay,
 * by DocumentNormaliser's rules: a link by ID in its table's case with its
 * place encoded, a place on the page encoded, `//host/…` given https:, an
 * http, https, mailto or tel address as it is. A path on this site, or,
 * given `site`, this site's own address written in full, is kept as its
 * path, encoded, for the server, which stores the path of an article, a
 * term, an author or a library file as its ID and keeps any other path as
 * it is (rule 9).
 */
export function normaliseHref(
    href: string,
    site: string | null = null,
): string | null {
    let trimmed = href.trim();

    // A browser reads `//host/…` as an address on another site.
    if (/^\/\/[^/\s]/.test(trimmed)) {
        trimmed = `https:${trimmed}`;
    }

    trimmed = pathOnThisSite(trimmed, site) ?? trimmed;

    let stored: string | null = trimmed;

    if (/^teme:/i.test(trimmed)) {
        stored = byId(trimmed);
    } else if (trimmed.startsWith('#')) {
        stored = fragment(trimmed);
    } else if (trimmed.startsWith('/')) {
        // A browser reads `/\host` and `///host` as another site.
        stored = /^\/[/\\]/.test(trimmed)
            ? null
            : encoded(trimmed, NOT_IN_SITE_PATH);
    }

    return stored !== null && stored.length <= MAX_LENGTH && allowsHref(stored)
        ? stored
        : null;
}

/**
 * What the link box makes of an address typed by hand: the address to
 * store, or why there is none. A bare host such as example.org still gets
 * https://, and an address on this site becomes its path (rule 9).
 */
export function typedAddress(
    input: string,
    site: string | null = null,
): { href: string } | { error: string } {
    const trimmed = input.trim();

    if (trimmed === '') {
        return { error: 'Type an address first.' };
    }

    const href = normaliseHref(
        /^(?:[a-z][a-z0-9+.-]*:|[#/])/i.test(trimmed)
            ? trimmed
            : `https://${trimmed}`,
        site,
    );

    if (href !== null) {
        return { href };
    }

    return {
        error: trimmed.startsWith('#')
            ? 'Write the name of the place after the #.'
            : 'A link can go to an http or https address, an email (mailto:), a phone number (tel:), a page on this site (/…) or a place on this page (#…).',
    };
}
