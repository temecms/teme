import { Info } from 'lucide-react';
import { descriptionSource } from '@/components/editor/rail/checks';
import type { SearchPreview } from '@/components/editor/use-search-preview';
import { __ } from '@/lib/i18n';
import { largest, variant } from '@/lib/media-urls';
import { fitWidth } from '@/lib/text-width';
import type { CardMedia, EditorSeo } from '@/types/editor';

/** "a", "a and b", "a, b and c". */
function joined(parts: string[]): string {
    return parts.length < 2
        ? (parts[0] ?? '')
        : __(':first and :last', {
              first: parts.slice(0, -1).join(', '),
              last: parts[parts.length - 1],
          });
}

/** An address as a result shows it: no scheme, its parts joined by "›". */
function crumbs(address: string): string {
    return address
        .replace(/^[a-z]+:\/\//i, '')
        .split('/')
        .filter((part) => part !== '')
        .join(' › ');
}

/** How wide the result is drawn: as a desktop's results column, or a phone's. */
export type ResultSize = 'desktop' | 'mobile';

/**
 * How the item will look in a search result (I-290), at true size: 600
 * pixels of text on a desktop, 400 on a phone, made by the page's own
 * rules; text is cut by width, as results cut it.
 */
export function SearchResult({
    search,
    address,
    canonicalNote,
    plural,
    descriptionFrom,
    size = 'desktop',
}: {
    search: SearchPreview;
    address: string;
    canonicalNote: string | null;
    plural: string;
    descriptionFrom: SearchPreview['description']['from'];
    size?: ResultSize;
}) {
    const note =
        search.listing === 'discouraged'
            ? __(
                  'Search engines are asked to stay away from the whole site, so it won’t appear in results.',
              )
            : search.listing === 'hidden'
              ? __('Hidden from search engines, so it won’t appear in results.')
              : search.listing === 'type-hidden'
                ? __(
                      'Search and social hides :plural from search engines, so it won’t appear in results.',
                      { plural },
                  )
                : canonicalNote;

    return (
        <div className="grid grid-cols-1 content-start gap-2">
            <h3 className="text-ink-2 text-caption font-medium">
                {__('Search result')}
            </h3>
            {note !== null && (
                <p
                    role="note"
                    data-test="serp-note"
                    className="text-ink-2 text-caption flex gap-2"
                >
                    <Info aria-hidden className="mt-px size-3.5 shrink-0" />
                    <span>{note}</span>
                </p>
            )}
            <div
                data-test="serp"
                data-size={size}
                className={`ring-line grid max-w-full gap-0.5 rounded-md p-4 font-[Arial,Helvetica,sans-serif] ring-1 ${size === 'mobile' ? 'w-108' : 'w-158'}`}
            >
                <p className="text-ink-2 text-caption truncate">
                    {crumbs(address)}
                </p>
                <p
                    data-test="serp-title"
                    className="text-accent-ink text-[20px] leading-snug"
                >
                    {fitWidth(
                        search.title.text,
                        20,
                        size === 'mobile' ? 400 : 580,
                    )}
                </p>
                <p className="text-[14px] leading-[1.58]">
                    {search.description.text === null
                        ? __(
                              'No description. Search engines will pick text from the page.',
                          )
                        : fitWidth(search.description.text, 14, 920)}
                </p>
            </div>
            <p data-test="description-from" className="text-ink-2 text-caption">
                {descriptionSource(descriptionFrom)}
            </p>
        </div>
    );
}

/**
 * How the item will look as a shared card (I-293), made by the page's own
 * rules, and what it was made from.
 */
export function SocialCard({
    search,
    address,
    media,
    loading,
    seo,
    noun,
}: {
    search: SearchPreview;
    address: string;
    media: Record<string, CardMedia>;
    loading: string[];
    seo: EditorSeo;
    noun: string;
}) {
    const { card } = search;
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
            social: __('the social title'),
            seo: __('the SEO title'),
            title: __('the :noun’s title', { noun }),
        }[card.titleFrom],
    ];
    const lacks: string[] = [];

    if (card.descriptionFrom === null) {
        lacks.push(__('no description'));
    } else {
        made.push(
            {
                social: __('the social description'),
                meta: __('the meta description'),
                excerpt: __('the excerpt'),
                tagline: __('the site’s tagline'),
            }[card.descriptionFrom],
        );
    }

    if (card.imageFrom !== null && (source !== null || waiting)) {
        made.push(
            {
                social: __('the social image'),
                featured: __('the featured image'),
                body: __('the first image in the body'),
                default: __('the site’s default social image'),
            }[card.imageFrom],
        );
    } else {
        lacks.push(__('no image'));
    }

    return (
        <div className="grid w-70 max-w-full content-start gap-2">
            <h3 className="text-ink-2 text-caption font-medium">
                {__('Social card')}
            </h3>
            <div
                data-test="social-card"
                className="border-line grid grid-cols-[132px_minmax(0,1fr)] overflow-hidden rounded-md border"
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
                        className="bg-surface-2 min-h-[72px] w-full motion-safe:animate-pulse"
                    />
                ) : (
                    <div className="border-line text-ink-2 text-caption m-2 flex min-h-[56px] items-center justify-center rounded-sm border border-dashed">
                        {__('No image')}
                    </div>
                )}
                <div className="grid content-start gap-0.5 px-3 py-2">
                    <p className="text-ink-2 text-caption uppercase">{host}</p>
                    <p className="text-caption line-clamp-2 font-semibold">
                        {card.title}
                    </p>
                    {description !== null && (
                        <p className="text-ink-2 text-caption line-clamp-2">
                            {description}
                        </p>
                    )}
                </div>
            </div>
            <p data-test="social-from" className="text-ink-2 text-caption">
                {lacks.length > 0
                    ? __('Made from :made, with :lacks.', {
                          made: joined(made),
                          lacks: joined(lacks),
                      })
                    : __('Made from :made.', { made: joined(made) })}
            </p>
        </div>
    );
}
