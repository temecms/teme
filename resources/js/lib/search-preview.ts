import type { EditorSeo, EditorSession, LinkedContent } from '@/types/editor';

/** The built-in fields the previews read (BuiltInFields). Their keys never change. */
export const FIELD = {
    seoTitle: '01K5Z0F1ED00000000000000S1',
    metaDescription: '01K5Z0F1ED00000000000000D2',
    excerpt: '01K5Z0F1ED00000000000000E3',
    canonical: '01K5Z0F1ED00000000000000C4',
    noindex: '01K5Z0F1ED00000000000000N5',
    featuredImage: '01K5Z0F1ED00000000000000F6',
    socialTitle: '01K5Z0F1ED00000000000000ST',
    socialDescription: '01K5Z0F1ED00000000000000SD',
    socialImage: '01K5Z0F1ED00000000000000SG',
    primaryCategory: '01K5Z0F1ED00000000000000PC',
} as const;

/** A title template filler: fillTitle() from @/lib/title-template, passed in so this file imports nothing. */
export type Fill = (
    template: string,
    tokens: Record<string, string>,
    separator: string,
) => string;

/** What the previews read: the item as it is on the screen now, and what the server gave. */
export type PreviewInput = {
    title: string;
    fields: Record<string, unknown>;
    document: unknown;
    seo: Pick<
        EditorSeo,
        'kind' | 'template' | 'separator' | 'tokens' | 'defaultImage'
    >;
    /** The name the page gives {term}: kickerName()'s. */
    term: string;
};

/** Whether search engines may list the item, and if not, why: RobotsMeta's order (I-291). */
export type Listing = 'listed' | 'hidden' | 'type-hidden' | 'discouraged';

type Node = {
    type?: string;
    attrs?: Record<string, unknown>;
    content?: Node[];
};

/** Trimmed text, or null for none, as PageHead::text() reads a field. */
const text = (value: unknown): string | null =>
    typeof value === 'string' && value.trim() !== '' ? value.trim() : null;

/** A media field's image ID, or null. */
const imageOf = (value: unknown): string | null => {
    const id = (value as { id?: unknown } | null | undefined)?.id;

    return typeof id === 'string' && id !== '' ? id : null;
};

/**
 * The title search engines get (I-290): the SEO title word for word, else
 * the row's template filled. The home page's title token is the site's
 * name, from the server; anywhere else it is the item's own title.
 */
export function searchTitle(
    input: PreviewInput,
    fill: Fill,
): { text: string; fromTemplate: boolean } {
    const own = text(input.fields[FIELD.seoTitle]);

    if (own !== null) {
        return { text: own, fromTemplate: false };
    }

    const tokens: Record<string, string> = { ...input.seo.tokens };

    if (input.seo.kind !== 'home') {
        tokens.title = input.title;
    }

    if (input.seo.kind === 'item') {
        tokens.term = input.term;
        tokens.excerpt = text(input.fields[FIELD.excerpt]) ?? '';
    }

    return {
        text: fill(input.seo.template, tokens, input.seo.separator),
        fromTemplate: true,
    };
}

/** The meta description (I-290): its own, then the excerpt, then on the home page the tagline, then none. */
export function searchDescription(input: PreviewInput): {
    text: string | null;
    from: 'meta' | 'excerpt' | 'tagline' | null;
} {
    const meta = text(input.fields[FIELD.metaDescription]);

    if (meta !== null) {
        return { text: meta, from: 'meta' };
    }

    const excerpt = text(input.fields[FIELD.excerpt]);

    if (excerpt !== null) {
        return { text: excerpt, from: 'excerpt' };
    }

    const tagline =
        input.seo.kind === 'home' ? text(input.seo.tokens.tagline) : null;

    return tagline === null
        ? { text: null, from: null }
        : { text: tagline, from: 'tagline' };
}

/** The media ID of the first image card at the body's top level, as PageHead's JSON path finds it; null when it has none or names none. */
export function firstImageId(document: unknown): string | null {
    for (const node of (document as Node | null)?.content ?? []) {
        if (
            node.type === 'image' &&
            node.attrs !== undefined &&
            'mediaId' in node.attrs
        ) {
            return typeof node.attrs.mediaId === 'string' &&
                node.attrs.mediaId !== ''
                ? node.attrs.mediaId
                : null;
        }
    }

    return null;
}

/**
 * The social card (I-293): each part falls back in turn, as the page's Open Graph tags do.
 * The home page's title falls back to the title its tab shows (searchTitle's), not the page's own name.
 */
export function socialCard(
    input: PreviewInput,
    searchTitleText: string | null = null,
): {
    title: string;
    titleFrom: 'social' | 'seo' | 'title';
    description: string | null;
    descriptionFrom: 'social' | 'meta' | 'excerpt' | 'tagline' | null;
    imageId: string | null;
    imageFrom: 'social' | 'featured' | 'body' | 'default' | null;
} {
    const socialTitle = text(input.fields[FIELD.socialTitle]);
    const seoTitle = text(input.fields[FIELD.seoTitle]);
    const socialDescription = text(input.fields[FIELD.socialDescription]);
    const description = searchDescription(input);
    const social = imageOf(input.fields[FIELD.socialImage]);
    const featured = imageOf(input.fields[FIELD.featuredImage]);
    const body =
        social === null && featured === null
            ? firstImageId(input.document)
            : null;
    const fallback = input.seo.defaultImage?.id ?? null;

    return {
        title:
            socialTitle ??
            (input.seo.kind === 'home' && searchTitleText !== null
                ? searchTitleText
                : (seoTitle ?? input.title)),
        titleFrom:
            socialTitle !== null
                ? 'social'
                : seoTitle !== null
                  ? 'seo'
                  : 'title',
        description: socialDescription ?? description.text,
        descriptionFrom:
            socialDescription !== null ? 'social' : description.from,
        imageId: social ?? featured ?? body ?? fallback,
        imageFrom:
            social !== null
                ? 'social'
                : featured !== null
                  ? 'featured'
                  : body !== null
                    ? 'body'
                    : fallback !== null
                      ? 'default'
                      : null,
    };
}

/**
 * The term that stands for the item (I-869): the primary category while it is
 * one of the ticked terms of the type's first hierarchical taxonomy, else the
 * first of those, as PrimaryCategory::pick(); null when none is ticked.
 */
export function pickedCategory(
    taxonomies: Pick<
        EditorSession['taxonomies'][number],
        'key' | 'hierarchical'
    >[],
    terms: { id: string; name: string; taxonomy: string }[],
    fields: Record<string, unknown>,
): { id: string; name: string } | null {
    const first = taxonomies.find((taxonomy) => taxonomy.hierarchical)?.key;
    const sections = terms.filter((term) => term.taxonomy === first);
    const picked =
        sections.find((term) => term.id === fields[FIELD.primaryCategory]) ??
        sections[0];

    return picked === undefined ? null : { id: picked.id, name: picked.name };
}

/** The name the page gives {term} and its section label (I-869). */
export function kickerName(
    taxonomies: Pick<
        EditorSession['taxonomies'][number],
        'key' | 'hierarchical'
    >[],
    terms: { id: string; name: string; taxonomy: string }[],
    fields: Record<string, unknown>,
): string {
    return pickedCategory(taxonomies, terms, fields)?.name ?? '';
}

/** The trail a theme draws to the item (I-295): home, the category's parents and the category, then the item, as Breadcrumbs::toItem() names them. */
export function trailOf(
    terms: { id: string; name: string; parentId: string | null }[],
    categoryId: string | null,
    title: string,
): string[] {
    const chain: string[] = [];
    const seen = new Set<string>();

    for (let id = categoryId; id !== null && !seen.has(id);) {
        const term = terms.find((each) => each.id === id);

        if (term === undefined) {
            break;
        }

        seen.add(id);
        chain.unshift(term.name);
        id = term.parentId;
    }

    return ['Home', ...chain, title];
}

/** Whether search engines may list the item: the site discouraged first, then its own box, then its type's row, which the home page doesn't follow. */
export function listing({
    fields,
    seo,
}: {
    fields: Record<string, unknown>;
    seo: Pick<EditorSeo, 'discouraged' | 'typeHidden' | 'kind'>;
}): Listing {
    if (seo.discouraged) {
        return 'discouraged';
    }

    if (fields[FIELD.noindex] === true) {
        return 'hidden';
    }

    return seo.typeHidden && seo.kind !== 'home' ? 'type-hidden' : 'listed';
}

/**
 * Why an outside canonical can't be sent yet, in the server's words, or null
 * when it may be (CanonicalChoice). Stricter than the server where PHP and the
 * browser read addresses differently: an ASCII host, and only the characters
 * an address may hold, so the browser never sends what the server refuses.
 */
export function canonicalAddressProblem(
    value: string,
    siteHost: string,
): string | null {
    const address = value.trim();
    const whole =
        /^https?:\/\/([a-z0-9.-]+)(?::\d{1,5})?(?:[/?#][A-Za-z0-9\-._~!$&()*+,;=:@/?#%]*)?$/i.exec(
            address,
        );

    if (whole === null || address.length > 2048) {
        return 'Enter the whole address, starting https://.';
    }

    if (whole[1].toLowerCase() === siteHost.toLowerCase()) {
        return 'That address is on this site. Choose its item instead, so the link survives a move.';
    }

    return null;
}

/**
 * The fields the editor opens on, without a canonical that names an item in
 * the trash or gone: the server refuses to save one, which would stop the
 * whole article saving (I-292).
 */
export function withoutGoneCanonical(
    fields: Record<string, unknown>,
    chosen: { state: LinkedContent['state'] } | null,
): Record<string, unknown> {
    if (
        chosen === null ||
        (chosen.state !== 'trashed' && chosen.state !== 'missing')
    ) {
        return fields;
    }

    const kept = { ...fields };
    delete kept[FIELD.canonical];

    return kept;
}
