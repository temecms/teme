import { useMemo } from 'react';
import {
    coreChecks,
    featuredMissingAlt,
    imageCardCount,
    internalLinkCount,
} from '@/components/editor/rail/checks';
import type {
    CoreCheck,
    CoreChecksInput,
    DocumentChecks,
    WithheldNode,
} from '@/components/editor/rail/checks';
import {
    kickerName,
    listing,
    searchDescription,
    searchTitle,
    socialCard,
} from '@/lib/search-preview';
import type { Listing, PreviewInput } from '@/lib/search-preview';
import { textWidth } from '@/lib/text-width';
import { fillTitle } from '@/lib/title-template';
import type { CardMedia, EditorSeo, EditorSession } from '@/types/editor';

/** What every place that shows the item's search result, card or checks reads. */
export type SearchPreview = {
    title: { text: string; fromTemplate: boolean; width: number };
    description: {
        text: string | null;
        from: 'meta' | 'excerpt' | 'tagline' | null;
        width: number;
    };
    card: ReturnType<typeof socialCard>;
    listing: Listing;
    checks: CoreCheck[];
    /** The same list, worded for the confirmation before publishing. */
    publishingChecks: CoreCheck[];
};

/**
 * The search result, the social card and core's checks for the item as it is
 * on the screen now, made once a render so the section, the rail and the
 * confirmation never disagree (I-880).
 */
export function useSearchPreview({
    contentId,
    title: titleText,
    values,
    document,
    media,
    seo,
    taxonomies,
    terms,
    hasExcerptField,
    hasFeaturedImageField,
    documentChecks,
    published,
    noun,
    plural,
    withheld,
}: {
    contentId: string;
    title: string;
    values: Record<string, unknown>;
    document: unknown;
    media: Record<string, CardMedia>;
    seo: EditorSeo;
    taxonomies: EditorSession['taxonomies'];
    terms: { id: string; name: string; taxonomy: string }[];
    hasExcerptField: boolean;
    hasFeaturedImageField: boolean;
    documentChecks: DocumentChecks;
    published: boolean;
    noun: string;
    plural: string;
    /** What the gate leaves out of the page (useStructuredData), null until it answers. */
    withheld: WithheldNode[] | 'unknown' | null;
}): SearchPreview {
    return useMemo(() => {
        const preview: PreviewInput = {
            title: titleText.trim() || 'Untitled',
            fields: values,
            document,
            seo,
            term: kickerName(taxonomies, terms, values),
        };
        const title = searchTitle(preview, fillTitle);
        const titleWidth = textWidth(title.text, 20);
        const description = searchDescription(preview);
        const listed = listing({ fields: values, seo });
        const input: CoreChecksInput = {
            document: documentChecks,
            imageCards: imageCardCount(document),
            featuredMissingAlt: featuredMissingAlt(values, media),
            hasExcerptField,
            hasFeaturedImageField,
            description,
            titleWidth,
            internalLinks: internalLinkCount(document, contentId),
            listing: listed,
            published,
            publishing: false,
            noun,
            plural,
            withheld,
        };

        return {
            title: { ...title, width: titleWidth },
            description: {
                ...description,
                width:
                    description.text === null
                        ? 0
                        : textWidth(description.text, 14),
            },
            card: socialCard(preview),
            listing: listed,
            checks: coreChecks(input),
            publishingChecks: coreChecks({ ...input, publishing: true }),
        };
    }, [
        contentId,
        titleText,
        values,
        document,
        media,
        seo,
        taxonomies,
        terms,
        hasExcerptField,
        hasFeaturedImageField,
        documentChecks,
        published,
        noun,
        plural,
        withheld,
    ]);
}
