import { Info } from 'lucide-react';
import { descriptionSource } from '@/components/editor/rail/checks';
import type { SearchPreview } from '@/components/editor/use-search-preview';
import { largest, variant } from '@/lib/media-urls';
import { fitWidth } from '@/lib/text-width';
import type { CardMedia, EditorSeo } from '@/types/editor';

/** "a", "a and b", "a, b and c". */
function joined(parts: string[]): string {
    return parts.length < 2
        ? (parts[0] ?? '')
        : `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
}

/** An address as a result shows it: no scheme, its parts joined by "›". */
function crumbs(address: string): string {
    return address
        .replace(/^[a-z]+:\/\//i, '')
        .split('/')
        .filter((part) => part !== '')
        .join(' › ');
}

/**
 * How the item will look in a search result and as a shared card (I-290,
 * I-293), made by the page's own rules; text is cut by width, as results cut
 * it.
 */
export function SearchPreviews({
    search,
    address,
    canonicalNote,
    media,
    loading,
    seo,
    noun,
    plural,
    descriptionFrom,
}: {
    search: SearchPreview;
    address: string;
    canonicalNote: string | null;
    media: Record<string, CardMedia>;
    loading: string[];
    seo: EditorSeo;
    noun: string;
    plural: string;
    descriptionFrom: SearchPreview['description']['from'];
}) {
    const { card } = search;
    const note =
        search.listing === 'discouraged'
            ? 'Search engines are asked to stay away from the whole site, so it won’t appear in results.'
            : search.listing === 'hidden'
              ? 'Hidden from search engines, so it won’t appear in results.'
              : search.listing === 'type-hidden'
                ? `Search and social hides ${plural} from search engines, so it won’t appear in results.`
                : canonicalNote;
    const image =
        card.imageFrom === 'default'
            ? seo.defaultImage?.media
            : card.imageId === null
              ? undefined
              : media[card.imageId];
    const waiting =
        image === undefined &&
        card.imageId !== null &&
        loading.includes(card.imageId);
    const source =
        image === undefined
            ? null
            : (variant(image.variants, 'social') ?? largest(image.variants));
    const host = address
        .replace(/^[a-z]+:\/\//i, '')
        .split('/')[0]
        .toUpperCase();
    const description =
        card.description !== null && card.description.length > 110
            ? `${card.description.slice(0, 109)}…`
            : card.description;

    const made = [
        {
            social: 'the social title',
            seo: 'the SEO title',
            title: `the ${noun}’s title`,
        }[card.titleFrom],
    ];
    const lacks: string[] = [];

    if (card.descriptionFrom === null) {
        lacks.push('no description');
    } else {
        made.push(
            {
                social: 'the social description',
                meta: 'the meta description',
                excerpt: 'the excerpt',
                tagline: 'the site’s tagline',
            }[card.descriptionFrom],
        );
    }

    if (card.imageFrom !== null && (source !== null || waiting)) {
        made.push(
            {
                social: 'the social image',
                featured: 'the featured image',
                body: 'the first image in the body',
                default: 'the site’s default social image',
            }[card.imageFrom],
        );
    } else {
        lacks.push('no image');
    }

    return (
        <div
            role="group"
            aria-label="Previews"
            className="grid grid-cols-[minmax(0,1fr)] gap-5 @2xl:grid-cols-[minmax(0,632px)_minmax(0,280px)] @2xl:justify-start"
        >
            <div className="grid content-start gap-2">
                <h3 className="text-muted-foreground text-[11px] font-medium">
                    Search result
                </h3>
                {note !== null && (
                    <p
                        role="note"
                        data-test="serp-note"
                        className="text-muted-foreground flex gap-1.5 text-xs"
                    >
                        <Info aria-hidden className="mt-px size-3.5 shrink-0" />
                        <span>{note}</span>
                    </p>
                )}
                <div
                    data-test="serp"
                    className="border-border grid w-full gap-0.5 rounded-md border p-4 font-[Arial,Helvetica,sans-serif]"
                >
                    <p className="text-muted-foreground truncate text-xs">
                        {crumbs(address)}
                    </p>
                    <p
                        data-test="serp-title"
                        className="text-link text-[20px] leading-snug"
                    >
                        {fitWidth(search.title.text, 20, 580)}
                    </p>
                    <p className="text-[14px] leading-[1.58]">
                        {search.description.text === null
                            ? 'No description. Search engines will pick text from the page.'
                            : fitWidth(search.description.text, 14, 920)}
                    </p>
                </div>
                <p
                    data-test="description-from"
                    className="text-muted-foreground text-xs"
                >
                    {descriptionSource(descriptionFrom)}
                </p>
            </div>
            <div className="grid content-start gap-2">
                <h3 className="text-muted-foreground text-[11px] font-medium">
                    Social card
                </h3>
                <div
                    data-test="social-card"
                    className="border-border grid grid-cols-[132px_minmax(0,1fr)] overflow-hidden rounded-md border"
                >
                    {source !== null ? (
                        <img
                            src={source}
                            alt=""
                            className="h-full min-h-[72px] w-full object-cover"
                        />
                    ) : waiting ? (
                        <div
                            aria-busy="true"
                            className="bg-muted min-h-[72px] w-full animate-pulse"
                        />
                    ) : (
                        <div className="border-border text-muted-foreground m-2 flex min-h-[56px] items-center justify-center rounded-sm border border-dashed text-xs">
                            No image
                        </div>
                    )}
                    <div className="grid content-start gap-0.5 px-3 py-2">
                        <p className="text-muted-foreground text-[11px] uppercase">
                            {host}
                        </p>
                        <p className="line-clamp-2 text-xs font-semibold">
                            {card.title}
                        </p>
                        {description !== null && (
                            <p className="text-muted-foreground line-clamp-2 text-[11px]">
                                {description}
                            </p>
                        )}
                    </div>
                </div>
                <p
                    data-test="social-from"
                    className="text-muted-foreground text-xs"
                >
                    Made from {joined(made)}
                    {lacks.length > 0 ? `, with ${joined(lacks)}.` : '.'}
                </p>
            </div>
        </div>
    );
}
