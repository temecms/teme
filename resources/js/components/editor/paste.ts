import type { EmbedProvider } from '@/types/editor';
import { normaliseHref } from './link-address';

/** An embed card's attributes made from a pasted address, before its `id`. */
export type EmbedPasteAttrs = {
    url: string;
    kind: string;
    provider: string;
    title: null;
    html: null;
    thumbnailMediaId: null;
    caption: null;
};

/** The attributes an element may keep, by tag; every other attribute goes. */
const KEPT: Record<string, string[]> = {
    a: ['href'],
    img: ['src', 'alt'],
    ol: ['start'],
    // A cell's span and alignment, which the editor's table reads (I-452).
    td: ['colspan', 'rowspan', 'align'],
    th: ['colspan', 'rowspan', 'align'],
};

/**
 * The most a cell may span, as browsers clamp it (I-452): a pasted colspan of
 * millions would make the editor's table map millions of columns wide.
 */
const CELL_SPANS: [string, number][] = [
    ['colspan', 1000],
    ['rowspan', 65534],
];

/** Elements that stay even when they hold no text. */
const MAY_BE_EMPTY = new Set(['br', 'img', 'td', 'th', 'hr']);

/** A placeholder a lazy loader leaves in src until the image is in view. */
const PLACEHOLDER = /^(?:data:|about:blank$|$)/i;

/** An address another site can be asked for: http, https, or one without its scheme. */
const ABSOLUTE = /^(?:https?:)?\/\//i;

/**
 * The srcset candidate with the largest width (`2048w`), or null when none
 * gives a width.
 */
function widestCandidate(srcset: string): string | null {
    let widest: { address: string; width: number } | null = null;

    for (const candidate of srcset.split(',')) {
        const [address, descriptor] = candidate.trim().split(/\s+/);
        const width = Number(
            /^(\d+)w$/i.exec(descriptor ?? '')?.[1] ?? Number.NaN,
        );

        if (
            address &&
            !Number.isNaN(width) &&
            (widest === null || width > widest.width)
        ) {
            widest = { address, width };
        }
    }

    return widest?.address ?? null;
}

/**
 * The address to import a pasted image from (I-453): Jetpack's original,
 * else the widest size its srcset lists, else a lazy loader's held-back
 * address while src is a placeholder, else src. Only an absolute address
 * replaces src, and one without its scheme gains https:. Null for none.
 */
function imageAddress(attributes: Map<string, string>): string | null {
    const src = (attributes.get('src') ?? '').trim();
    const better = [
        attributes.get('data-orig-file'),
        widestCandidate(attributes.get('srcset') ?? ''),
        PLACEHOLDER.test(src)
            ? (attributes.get('data-lazy-src') ?? attributes.get('data-src'))
            : undefined,
    ]
        .map((candidate) => (candidate ?? '').trim())
        .find((candidate) => ABSOLUTE.test(candidate));
    const chosen = better ?? src;

    if (chosen === '') {
        return null;
    }

    return chosen.startsWith('//') ? `https:${chosen}` : chosen;
}

const ATTRIBUTE =
    /([^\s"'=<>/]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;

/** An element's attributes, whether double-quoted, single-quoted or bare. */
function attributesOf(source: string): Map<string, string> {
    const found = new Map<string, string>();

    for (const match of source.matchAll(ATTRIBUTE)) {
        found.set(
            match[1].toLowerCase(),
            match[2] ?? match[3] ?? match[4] ?? '',
        );
    }

    return found;
}

/**
 * Word's list paragraphs (Word): a run of <p … mso-list:…> becomes one list,
 * numbered when its first marker is a number or letter followed by `.` or
 * `)`. The marker sits in a downlevel conditional, so this runs before those
 * are removed.
 */
function wordLists(html: string): string {
    // Later (I-426): Word's nested list levels. They are flattened to one.
    return html.replace(
        /(?:<p\b[^>]*mso-list:[^>]*>[\s\S]*?<\/p>\s*)+/gi,
        (run) => {
            const items = [...run.matchAll(/<p\b[^>]*>([\s\S]*?)<\/p>/gi)].map(
                (match) => match[1],
            );
            const marker = (
                /<!\[if !supportLists\]>([\s\S]*?)<!\[endif\]>/i.exec(
                    items[0] ?? '',
                )?.[1] ?? ''
            )
                .replace(/<[^>]+>/g, '')
                .replace(/&nbsp;/g, ' ')
                .trim();
            const tag = /^([0-9]+|[a-z]+)[.)]/i.test(marker) ? 'ol' : 'ul';
            const lis = items
                .map(
                    (item) =>
                        `<li>${item.replace(/<!\[if !supportLists\]>[\s\S]*?<!\[endif\]>/gi, '').trim()}</li>`,
                )
                .join('');

            return `<${tag}>${lis}</${tag}>`;
        },
    );
}

/**
 * Google Docs: its <b id="docs-internal-guid-…"> wrapper is not bold (it says
 * font-weight:normal), so it is unwrapped; then its bold and italic spans
 * become marks. Underline is left, because Docs underlines every link.
 */
function googleDocs(html: string): string {
    let cleaned = html;
    const wrapper = /<b\b[^>]*id\s*=\s*["']?docs-internal-guid-[^>]*>/i;

    if (wrapper.test(cleaned)) {
        cleaned = cleaned.replace(wrapper, '');
        const last = cleaned.toLowerCase().lastIndexOf('</b>');

        if (last !== -1) {
            cleaned = cleaned.slice(0, last) + cleaned.slice(last + 4);
        }
    }

    return cleaned.replace(
        /<span\b([^>]*)>([\s\S]*?)<\/span>/gi,
        (whole, attributes: string, content: string) => {
            const style = attributesOf(attributes).get('style') ?? '';
            const weight = /font-weight\s*:\s*(\d+|bold)/i.exec(style)?.[1];
            const bold =
                weight !== undefined &&
                (weight.toLowerCase() === 'bold' || Number(weight) >= 600);
            const italic = /font-style\s*:\s*italic/i.test(style);

            if (!bold && !italic) {
                return whole;
            }

            let marked = content;
            marked = italic ? `<em>${marked}</em>` : marked;
            marked = bold ? `<strong>${marked}</strong>` : marked;

            return marked;
        },
    );
}

/**
 * The clipboard's HTML without Word's and Google Docs' markup, before
 * ProseMirror parses it against Teme's schema. It works on the string, never
 * on a DOM, so it runs the same in Node's tests. The server still normalises
 * and validates whatever arrives: a paste this misses can make a messy
 * document, never an invalid one. A pasted h1 becomes an h2, and an h5 or h6
 * an h4, the levels the body has. `site` is the site's address, so a link
 * to the site itself becomes its path (rule 9). An image keeps the best
 * address to import it from: its original, its widest size, or the address a
 * lazy loader held back (I-453).
 */
export function cleanPastedHtml(
    html: string,
    site: string | null = null,
): string {
    // The article's title is its h1 and the body has h2 to h4 (schema.ts),
    // so an h1 becomes an h2 and an h5 or h6 an h4, rather than a paragraph.
    // Before the clipboard check below: another ProseMirror app's clipboard
    // carries its headings too (I-426).
    const headings = html
        .replace(/<(\/?)h1\b/gi, '<$1h2')
        .replace(/<(\/?)h[56]\b/gi, '<$1h4');

    // The editor's own clipboard: its cards travel in data-* attributes that
    // cleaning would strip.
    if (headings.includes('data-pm-slice')) {
        return headings;
    }

    let cleaned = wordLists(headings);

    // Comments (and Word's <!--[if …]> … <![endif]--> with its <xml>), Word's
    // downlevel conditionals, and elements that carry no content.
    cleaned = cleaned
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<!\[if[^\]]*\]>[\s\S]*?<!\[endif\]>/gi, '')
        .replace(/<(head|style|script)\b[\s\S]*?<\/\1\s*>/gi, '')
        .replace(/<meta\b[^>]*>/gi, '');

    // Office's namespaced tags, such as <o:p>: the tags go, their words stay.
    cleaned = cleaned.replace(/<\/?\w+:\w+\b[^>]*>/g, '');

    cleaned = googleDocs(cleaned);

    // Every <font> and <span>, unwrapped.
    cleaned = cleaned.replace(/<\/?(font|span)\b[^>]*>/gi, '');

    // Attributes: an allow-list, not a deny-list.
    cleaned = cleaned.replace(
        /<([a-z][a-z0-9]*)\b([^>]*)>/gi,
        (_whole, name: string, attributes: string) => {
            const tag = name.toLowerCase();
            const keep = KEPT[tag] ?? [];
            const found = attributesOf(attributes);

            if (tag === 'img') {
                // The best address to import the image from (I-453).
                const address = imageAddress(found);

                if (address === null) {
                    found.delete('src');
                } else {
                    found.set('src', address);
                }
            }

            if (tag === 'td' || tag === 'th') {
                // Word and Google Docs align a cell with its style, which
                // goes below; the editor reads align (I-452).
                const align = /text-align\s*:\s*(left|center|right)\b/i.exec(
                    found.get('style') ?? '',
                )?.[1];

                if (!found.has('align') && align !== undefined) {
                    found.set('align', align.toLowerCase());
                }

                // A span as browsers read it: its leading whole number, up to
                // their limit. None, or less than 1, leaves the editor's 1.
                for (const [span, most] of CELL_SPANS) {
                    const count = Number.parseInt(found.get(span) ?? '', 10);

                    if (Number.isNaN(count) || count < 1) {
                        found.delete(span);
                    } else {
                        found.set(span, String(Math.min(count, most)));
                    }
                }
            }

            const kept = keep
                .filter((attribute) => found.has(attribute))
                .map(
                    (attribute) =>
                        ` ${attribute}="${(found.get(attribute) ?? '').replace(/"/g, '&quot;')}"`,
                )
                .join('');

            return `<${tag}${kept}>`;
        },
    );

    // Links, as the server keeps them (I-452): `//host` gains https:, a
    // place or a path is percent-encoded, and a link the site can't store is
    // unwrapped, its words kept. This site's full address becomes its path;
    // the server stores an article's path as its ID and keeps any other path
    // as it is.
    cleaned = cleaned.replace(
        /<a href="([^"]*)">([\s\S]*?)<\/a>/gi,
        (_whole, href: string, words: string) => {
            const kept = normaliseHref(href, site);

            return kept === null
                ? words
                : `<a href="${kept.replace(/"/g, '&quot;')}">${words}</a>`;
        },
    );

    // Elements left with no text, other than those that are meant to be.
    let before: string;

    do {
        before = cleaned;
        cleaned = cleaned.replace(
            /<([a-z][a-z0-9]*)>\s*<\/\1>/gi,
            (whole, name: string) =>
                MAY_BE_EMPTY.has(name.toLowerCase()) ? whole : '',
        );
    } while (cleaned !== before);

    return cleaned;
}

/**
 * Embed card attributes, without an `id`, when the pasted text is exactly one
 * http or https address that a provider on the site's list matches; null
 * otherwise, and the address stays a link.
 */
export function embedFromPastedText(
    text: string,
    providers: EmbedProvider[],
): EmbedPasteAttrs | null {
    const url = text.trim();

    if (url === '' || /\s/.test(url) || !/^https?:\/\//i.test(url)) {
        return null;
    }

    const provider = providers.find((candidate) =>
        new RegExp(candidate.match).test(url),
    );

    return provider === undefined
        ? null
        : {
              url,
              kind: provider.kind,
              provider: provider.name,
              title: null,
              html: null,
              thumbnailMediaId: null,
              caption: null,
          };
}
