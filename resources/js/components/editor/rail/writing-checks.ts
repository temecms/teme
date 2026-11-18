import { __, __n } from '@/lib/i18n';
import type { BadLink, DocumentChecks } from './checks';

type Node = {
    type?: string;
    attrs?: Record<string, unknown>;
    content?: Node[];
};

/** One of the rail's writing checks: a plain fact about the writing, never a score. */
export type WritingCheck = {
    id: 'title' | 'excerpt' | 'featured' | 'alt' | 'headings' | 'links';
    ok: boolean;
    message: string;
    /** For the links check: each article readers can't reach. */
    badLinks: BadLink[];
    /** Whether "Show" can put the cursor on it. */
    canShow: boolean;
};

/** Whether the document's headings go down one level at a time, from the subheading under the title. */
export function headingsInOrder(document: unknown): boolean {
    let previous = 1;
    let ordered = true;

    const walk = (node: Node) => {
        if (node.type === 'heading') {
            const level = Number(node.attrs?.level ?? 2);

            if (level > previous + 1) {
                ordered = false;
            }

            previous = level;

            return;
        }

        (node.content ?? []).forEach(walk);
    };

    walk(document as Node);

    return ordered;
}

/**
 * The rail's checks on the writing (docs/architecture/design-system.md,
 * 4.39): the title's length, the excerpt, the featured image's alt text, the
 * body's images' alt text and the headings' order; then, only while one
 * fails, links to items readers can't reach. The search checks stay in
 * Search and social, which the rail counts.
 */
export function writingChecks(input: {
    title: string;
    document: unknown;
    checks: DocumentChecks;
    featuredMissingAlt: boolean;
    hasExcerptField: boolean;
    hasFeaturedImageField: boolean;
    noun: string;
}): WritingCheck[] {
    const { checks } = input;
    const length = input.title.trim().length;
    const ordered = headingsInOrder(input.document);
    const list: WritingCheck[] = [
        {
            id: 'title',
            ok: length > 0 && length <= 70,
            message:
                length === 0
                    ? __('The :noun has no title', { noun: input.noun })
                    : __n(
                          'The title is :count character|The title is :count characters',
                          length,
                      ),
            badLinks: [],
            canShow: true,
        },
    ];

    if (input.hasExcerptField) {
        list.push({
            id: 'excerpt',
            ok: checks.hasExcerpt,
            message: checks.hasExcerpt
                ? __('The excerpt is written')
                : __(
                      'No excerpt yet: lists and search results will use the first lines',
                  ),
            badLinks: [],
            canShow: true,
        });
    }

    if (input.hasFeaturedImageField) {
        list.push({
            id: 'featured',
            ok: checks.hasFeaturedImage && !input.featuredMissingAlt,
            message: !checks.hasFeaturedImage
                ? __('No featured image')
                : input.featuredMissingAlt
                  ? __('The featured image has no alt text')
                  : __('The featured image has alt text'),
            badLinks: [],
            canShow: true,
        });
    }

    list.push(
        {
            id: 'alt',
            ok: checks.missingAlt === 0,
            message:
                checks.missingAlt === 0
                    ? __('Every image has alt text')
                    : __n(
                          ':count image in the body has no alt text|:count images in the body have no alt text',
                          checks.missingAlt,
                      ),
            badLinks: [],
            canShow: true,
        },
        {
            id: 'headings',
            ok: ordered,
            message: ordered
                ? __('Headings are in order')
                : __('A heading skips a level'),
            badLinks: [],
            canShow: false,
        },
    );

    if (checks.badLinks.length > 0) {
        list.push({
            id: 'links',
            ok: false,
            message: __('Some links lead to an item readers can’t reach:'),
            badLinks: checks.badLinks,
            canShow: true,
        });
    }

    return list;
}
