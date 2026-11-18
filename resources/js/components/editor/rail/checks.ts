import type { Listing } from '@/lib/search-preview';
import type { CardMedia, LinkedContent } from '@/types/editor';

/**
 * The excerpt field's key (BuiltInFields::EXCERPT). Built-in field keys never
 * change, and a test holds this one to the PHP constant.
 */
export const EXCERPT_FIELD = '01K5Z0F1ED00000000000000E3';

/**
 * The featured image field's key (BuiltInFields::FEATURED_IMAGE). A test
 * holds it to the PHP constant.
 */
export const FEATURED_IMAGE_FIELD = '01K5Z0F1ED00000000000000F6';

// DocumentSchema::INTERNAL_LINK, restated because schema.ts imports Tiptap and
// this file runs in Node too; LinkEditorTest holds the two together.
const CONTENT_LINK =
    /^teme:content\/([0-9a-hjkmnp-tv-z]{26})(?:#[A-Za-z0-9_.~!$&'()*+,;=:@/?%-]+)?$/;

type Node = {
    type?: string;
    attrs?: Record<string, unknown>;
    content?: Node[];
    marks?: { type?: string; attrs?: Record<string, unknown> }[];
};

/** What the checks read: the article as it is in the editor now. */
export type ChecksInput = {
    title: string;
    fields: Record<string, unknown>;
    document: unknown;
    media: Record<string, CardMedia>;
    /** The states of the articles the document links to, as far as known. */
    linked: Record<string, LinkedContent>;
};

/** A link to an article a reader cannot reach. */
export type BadLink = {
    id: string;
    title: string | null;
    state: 'unpublished' | 'trashed' | 'missing';
};

export type DocumentChecks = {
    hasTitle: boolean;
    missingAlt: number;
    hasExcerpt: boolean;
    hasFeaturedImage: boolean;
    badLinks: BadLink[];
};

const text = (value: unknown): string =>
    typeof value === 'string' ? value.trim() : '';

/**
 * Whether an image card needs alt text: its own is empty, the library's is
 * empty too, and it is not decorative. The server's image card falls back
 * to the library's alt, so an image described there is not missing any.
 */
export function isMissingAlt(
    attrs: { alt?: unknown; decorative?: unknown },
    item: CardMedia | undefined,
): boolean {
    return (
        attrs.decorative !== true &&
        text(attrs.alt) === '' &&
        text(item?.alt) === ''
    );
}

/**
 * The IDs of the cards with an image that has no alt text, in document
 * order: an image card by the rule above, and a gallery once when any of
 * its images has no alt in the library.
 */
export function cardsMissingAlt(
    document: unknown,
    media: Record<string, CardMedia>,
): string[] {
    const found: string[] = [];

    const walk = (node: Node) => {
        const attrs = node.attrs ?? {};
        const id = typeof attrs.id === 'string' ? attrs.id : '';

        if (node.type === 'image') {
            if (isMissingAlt(attrs, media[text(attrs.mediaId)])) {
                found.push(id);
            }

            return;
        }

        if (node.type === 'gallery') {
            const ids = Array.isArray(attrs.mediaIds) ? attrs.mediaIds : [];

            if (ids.some((each) => text(media[text(each)]?.alt) === '')) {
                found.push(id);
            }

            return;
        }

        (node.content ?? []).forEach(walk);
    };

    walk(document as Node);

    return found;
}

/**
 * The articles the document links to, in document order, each once: a link
 * mark's `teme:content/<id>`, and a button card's contentId, as the server's
 * References::contentIds() finds them.
 */
export function linkedContentIds(document: unknown): string[] {
    const found: string[] = [];
    const add = (id: string) => {
        if (id !== '' && !found.includes(id)) {
            found.push(id);
        }
    };

    const walk = (node: Node) => {
        if (node.type === 'button') {
            add(text(node.attrs?.contentId));
        }

        for (const mark of node.marks ?? []) {
            const href = mark.type === 'link' ? text(mark.attrs?.href) : '';
            const match = CONTENT_LINK.exec(href);

            if (match !== null) {
                add(match[1]);
            }
        }

        (node.content ?? []).forEach(walk);
    };

    walk(document as Node);

    return found;
}

/**
 * The rail's checks, worked out in the browser from what is being written:
 * nothing is stored, and nothing waits for a save.
 */
export function documentChecks({
    title,
    fields,
    document,
    media,
    linked,
}: ChecksInput): DocumentChecks {
    return {
        hasTitle: text(title) !== '',
        missingAlt: cardsMissingAlt(document, media).length,
        hasExcerpt: text(fields[EXCERPT_FIELD]) !== '',
        hasFeaturedImage:
            text(
                (
                    fields[FEATURED_IMAGE_FIELD] as
                        | { id?: unknown }
                        | null
                        | undefined
                )?.id,
            ) !== '',
        badLinks: linkedContentIds(document).flatMap((id) => {
            const found = linked[id];

            // Not asked yet, or fine: nothing to say.
            return found === undefined || found.state === 'published'
                ? []
                : [{ id, title: found.title, state: found.state }];
        }),
    };
}

/** A check's name; the rail can put the cursor on most of them. */
export type CheckId =
    | 'title'
    | 'description'
    | 'title-width'
    | 'excerpt'
    | 'featured'
    | 'alt'
    | 'internal-links'
    | 'links'
    | 'listing'
    | 'structured-data';

/** One of core's checks (I-880): a plain fact, never a score; a warning never blocks. */
export type CoreCheck = {
    id: CheckId;
    /** Who made it (I-200): core's say 'core'. */
    owner: 'core';
    state: 'ok' | 'warn';
    label: string;
    message: string;
    /** Shown in Search and social as well as in the rail and the confirmation. */
    search: boolean;
    /** For the links check: each article readers can't reach. */
    badLinks: BadLink[];
};

/** A node the gate left out (the server's Withheld), as the structured-data endpoint gives it. */
export type WithheldNode = { id: string; types: string[]; missing: string[][] };

export type CoreChecksInput = {
    document: DocumentChecks;
    imageCards: number;
    featuredMissingAlt: boolean;
    hasExcerptField: boolean;
    hasFeaturedImageField: boolean;
    description: {
        text: string | null;
        from: 'meta' | 'excerpt' | 'tagline' | null;
    };
    titleWidth: number;
    internalLinks: number;
    listing: Listing;
    published: boolean;
    /** Worded for the confirmation before publishing. */
    publishing: boolean;
    noun: string;
    plural: string;
    /** Null until the structured-data endpoint answers (no check yet); then the nodes left out, [] for none; 'unknown' when it couldn't answer. */
    withheld: WithheldNode[] | 'unknown' | null;
};

/** The schema.org types the gate knows, in words (I-703). */
const TYPE_WORDS: Record<string, string> = {
    Recipe: 'Recipe',
    Event: 'Event',
    VideoObject: 'Video',
    LocalBusiness: 'Local business',
    Store: 'Store',
    Restaurant: 'Restaurant',
    LegalService: 'Legal service',
    AccountingService: 'Accounting service',
    MedicalBusiness: 'Medical business',
    Product: 'Product',
    ProfilePage: 'Profile page',
    ImageObject: 'Image',
};

/** The properties the gate requires, in words. */
const PROPERTY_WORDS: Record<string, string> = {
    name: 'name',
    image: 'image',
    startDate: 'start date',
    'location.address': 'address for its place',
    thumbnailUrl: 'thumbnail',
    uploadDate: 'upload date',
    address: 'address',
    offers: 'offer',
    review: 'review',
    aggregateRating: 'rating',
    'mainEntity.name': 'name of who it’s about',
    contentUrl: 'file',
};

/** "a"; "a and b"; "a, b and c" (or "or"). */
const joined = (words: string[], last: 'and' | 'or'): string =>
    words.length < 2
        ? (words[0] ?? '')
        : `${words.slice(0, -1).join(', ')} ${last} ${words[words.length - 1]}`;

/** A word from a table, or the name as written; never a name the object already has, such as "constructor". */
const word = (table: Record<string, string>, name: string): string =>
    Object.hasOwn(table, name) ? table[name] : name;

/**
 * One node the gate left out, in plain words (I-703): "Recipe
 * markup left out: no image." Its type is the first of its types the table
 * names, else its last; each requirement is its properties, any one of which
 * would meet it.
 */
export function withheldMessage(node: WithheldNode): string {
    const type =
        node.types.find((name) => Object.hasOwn(TYPE_WORDS, name)) ??
        node.types[node.types.length - 1] ??
        '';
    const lacks = node.missing.map(
        (alternatives) =>
            `no ${joined(
                alternatives.map((property) => word(PROPERTY_WORDS, property)),
                'or',
            )}`,
    );

    return `${word(TYPE_WORDS, type)} markup left out: ${joined(lacks, 'and')}.`;
}

/** "1 image", "3 images". */
const count = (n: number, one: string, many: string): string =>
    `${n} ${n === 1 ? one : many}`;

/** The image and gallery cards anywhere in the document. */
export function imageCardCount(document: unknown): number {
    let found = 0;

    const walk = (node: Node) => {
        if (node.type === 'image' || node.type === 'gallery') {
            found += 1;

            return;
        }

        (node.content ?? []).forEach(walk);
    };

    walk(document as Node);

    return found;
}

/** The distinct items on this site the document links to, this one left out. */
export function internalLinkCount(document: unknown, ownId: string): number {
    return linkedContentIds(document).filter((id) => id !== ownId).length;
}

/** Whether the featured image has no alt text here or in the library; false while its media is still loading. */
export function featuredMissingAlt(
    fields: Record<string, unknown>,
    media: Record<string, CardMedia>,
): boolean {
    const featured = fields[FEATURED_IMAGE_FIELD] as
        | { id?: unknown; alt?: unknown }
        | null
        | undefined;
    const id = text(featured?.id);

    return (
        id !== '' &&
        media[id] !== undefined &&
        isMissingAlt({ alt: featured?.alt, decorative: false }, media[id])
    );
}

function altMessage(
    body: number,
    featured: boolean,
    cards: number,
    hasFeatured: boolean,
): string {
    if (featured && body > 0) {
        return `The featured image and ${count(body, 'image', 'images')} in the body have no alt text.`;
    }

    if (body > 0) {
        return `${count(body, 'image in the body has', 'images in the body have')} no alt text.`;
    }

    if (featured) {
        return 'The featured image has no alt text.';
    }

    return cards === 0 && !hasFeatured
        ? 'No images.'
        : 'Every image has alt text.';
}

function listingMessage(input: CoreChecksInput): string {
    switch (input.listing) {
        case 'discouraged':
            return 'Search engines are asked to stay away from the whole site.';
        case 'type-hidden':
            return `Search and social hides ${input.plural} from search engines.`;
        case 'hidden':
            return input.publishing
                ? 'It will be published hidden from search engines.'
                : input.published
                  ? 'Hidden from search engines, though it’s published.'
                  : 'Hidden from search engines when it’s published.';
        default:
            return 'They may list it.';
    }
}

/**
 * Core's checks on the item as it is now (I-880): one list, in one
 * order, for the section, the rail and the confirmation before publishing.
 * Each is a plain fact; a warning never blocks.
 */
export function coreChecks(input: CoreChecksInput): CoreCheck[] {
    const check = (
        id: CheckId,
        ok: boolean,
        label: string,
        message: string,
        search: boolean,
        badLinks: BadLink[] = [],
    ): CoreCheck => ({
        id,
        owner: 'core',
        state: ok ? 'ok' : 'warn',
        label,
        message,
        search,
        badLinks,
    });
    const { document } = input;
    const described = input.description.text !== null;
    const width = Math.round(input.titleWidth);
    const list: CoreCheck[] = [
        check(
            'title',
            document.hasTitle,
            'Title',
            document.hasTitle ? 'Set.' : 'No title yet.',
            false,
        ),
        check(
            'description',
            described,
            'Description',
            described
                ? {
                      meta: 'From the meta description.',
                      excerpt: 'From the excerpt.',
                      tagline: 'From the site’s tagline.',
                  }[input.description.from ?? 'meta']
                : input.hasExcerptField
                  ? 'None, and no excerpt. Search engines will choose text from the page.'
                  : 'None. Search engines will choose text from the page.',
            true,
        ),
        check(
            'title-width',
            width <= 580,
            'Title in results',
            width <= 580
                ? 'It fits.'
                : `Too long: about ${width} pixels, where results show about 580.`,
            true,
        ),
    ];

    if (input.hasExcerptField) {
        list.push(
            check(
                'excerpt',
                document.hasExcerpt,
                'Excerpt',
                document.hasExcerpt ? 'Written.' : 'No excerpt yet.',
                false,
            ),
        );
    }

    if (input.hasFeaturedImageField) {
        list.push(
            check(
                'featured',
                document.hasFeaturedImage,
                'Featured image',
                document.hasFeaturedImage
                    ? 'Chosen.'
                    : 'None. Social cards use the first image in the body, or the site’s default.',
                false,
            ),
        );
    }

    list.push(
        check(
            'alt',
            document.missingAlt === 0 && !input.featuredMissingAlt,
            'Alt text',
            altMessage(
                document.missingAlt,
                input.featuredMissingAlt,
                input.imageCards,
                document.hasFeaturedImage,
            ),
            true,
        ),
        check(
            'internal-links',
            input.internalLinks > 0,
            'Internal links',
            input.internalLinks > 0
                ? `${count(input.internalLinks, 'link', 'links')} to other items on this site.`
                : 'None to other items on this site.',
            true,
        ),
        check(
            'links',
            document.badLinks.length === 0,
            'Links',
            document.badLinks.length === 0
                ? 'None lead to a deleted or unpublished item.'
                : 'Some lead to an item readers can’t reach:',
            false,
            document.badLinks,
        ),
        check(
            'listing',
            input.listing === 'listed',
            'Search engines',
            listingMessage(input),
            true,
        ),
    );

    if (input.withheld !== null) {
        const known = input.withheld !== 'unknown';

        list.push(
            check(
                'structured-data',
                known && input.withheld.length === 0,
                'Structured data',
                input.withheld === 'unknown'
                    ? 'Couldn’t be checked just now.'
                    : input.withheld.length === 0
                      ? 'Nothing left out.'
                      : input.withheld.map(withheldMessage).join(' '),
                true,
            ),
        );
    }

    return list;
}

/** The search checks as one line (I-3124): each warning by name, then how many of the rest pass. */
export type SearchCheckLine = {
    warnings: { id: CheckId; label: string; message: string }[];
    summary: string;
};

/**
 * Search and social's one line for its search checks (I-3124): every
 * warning, in the list's order, then how many of the others pass. The list
 * is the one the rail and the confirmation read.
 */
export function searchCheckLine(checks: CoreCheck[]): SearchCheckLine {
    const warnings = checks
        .filter((check) => check.state === 'warn')
        .map(({ id, label, message }) => ({ id, label, message }));
    const passing = checks.length - warnings.length;

    return {
        warnings,
        summary:
            warnings.length === 0
                ? `All ${checks.length} search checks pass.`
                : passing >= 2
                  ? `The other ${passing} search checks pass.`
                  : passing === 1
                    ? 'The other search check passes.'
                    : '',
    };
}

/** Where the page's description comes from, as its head makes it (I-3124). */
export function descriptionSource(
    from: 'meta' | 'excerpt' | 'tagline' | null,
): string {
    switch (from) {
        case 'meta':
            return 'Description: the meta description.';
        case 'excerpt':
            return 'Description: from the excerpt.';
        case 'tagline':
            return 'Description: from the site’s tagline.';
        default:
            return 'Description: none. Search engines will choose text from the page.';
    }
}

export type RegionChecksInput = {
    document: unknown;
    media: Record<string, CardMedia>;
    /** The most top-level blocks the region holds. */
    max: number;
    /** The cards the region takes, by name. */
    allowed: string[];
    /** Every card's label, by name: what tells a card from text. */
    cardLabels: Record<string, string>;
};

/** What a theme region's checks read (issue 529); the rail words them. */
export type RegionFacts = {
    /** Its top-level blocks; an editor holding only an empty line holds none. */
    blocks: number;
    max: number;
    /** The labels of the cards it holds but doesn't take, each once. */
    refused: string[];
    /** The labels of the cards it takes. */
    takes: string[];
    /** Its cards with an image that has no alt text. */
    noAlt: number;
};

/** Whether a top-level block is an empty paragraph, as an empty editor holds. */
const isEmptyParagraph = (node: Node): boolean =>
    node.type === 'paragraph' && (node.content ?? []).length === 0;

/**
 * A theme region's own facts (issue 529), in place of a post's search
 * checks, none of which a region has: its blocks against its limit, the
 * cards it holds but doesn't take, and its images without alt text.
 */
export function regionFacts(input: RegionChecksInput): RegionFacts {
    const top = ((input.document as Node | null)?.content ?? []).filter(
        (node) => typeof node === 'object' && node !== null,
    );
    const refused: string[] = [];
    const walk = (node: Node) => {
        const type = node.type ?? '';

        if (
            Object.hasOwn(input.cardLabels, type) &&
            !input.allowed.includes(type) &&
            !refused.includes(type)
        ) {
            refused.push(type);
        }

        (node.content ?? []).forEach(walk);
    };
    top.forEach(walk);

    const labelOf = (name: string): string =>
        Object.hasOwn(input.cardLabels, name) ? input.cardLabels[name] : name;

    return {
        blocks: top.every(isEmptyParagraph) ? 0 : top.length,
        max: input.max,
        refused: refused.map(labelOf),
        takes: input.allowed.map(labelOf),
        noAlt: cardsMissingAlt(input.document, input.media).length,
    };
}
