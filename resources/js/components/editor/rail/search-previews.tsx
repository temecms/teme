import { Info } from 'lucide-react';
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
 * pixels wide on a desktop, 400 on a phone, as the drawing gives them. The
 * site's name and its round mark, the path from the item's own address, the
 * title cut by width as an engine cuts it, and the date before the
 * description.
 */
export function SearchResult({
    search,
    address,
    siteName,
    date,
    canonicalNote,
    plural,
    size = 'desktop',
}: {
    search: SearchPreview;
    address: string;
    /** The site's name, as the title template fills it. */
    siteName: string;
    /** The date a result shows, in the site's format; null when it shows none. */
    date: string | null;
    canonicalNote: string | null;
    plural: string;
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
    const mobile = size === 'mobile';

    return (
        <div className="bg-surface-2 grid min-w-0 grid-cols-1 content-start gap-3 rounded-lg p-4">
            {note !== null && (
                <p
                    role="note"
                    data-test="serp-note"
                    className="text-ink-2 text-small flex items-center gap-2"
                >
                    <Info aria-hidden className="size-3.5 shrink-0" />
                    <span>{note}</span>
                </p>
            )}
            <div
                data-test="serp"
                data-size={size}
                className={`bg-surface shadow-raised grid max-w-full gap-1 rounded-lg ${mobile ? 'w-100 p-4' : 'w-150 px-5 py-4'} ${search.listing === 'listed' ? '' : 'opacity-45'}`}
            >
                <div className="mb-1 flex min-w-0 items-center gap-3">
                    <span
                        aria-hidden
                        data-test="serp-mark"
                        className="bg-surface-3 text-small grid size-7 shrink-0 place-items-center rounded-full font-serif leading-none font-semibold"
                    >
                        {Array.from(siteName)[0] ?? ''}
                    </span>
                    <span className="grid min-w-0">
                        <span data-test="serp-site" className="text-small">
                            {siteName}
                        </span>
                        <span
                            data-test="serp-path"
                            className="text-ink-2 text-caption wrap-anywhere"
                        >
                            {crumbs(address)}
                        </span>
                    </span>
                </div>
                <p
                    data-test="serp-title"
                    className="text-lead text-info-ink wrap-anywhere"
                >
                    {/* An engine cuts the title by width: 580 pixels of 20px Arial on a desktop, about three such lines on a phone. */}
                    {fitWidth(search.title.text, 20, mobile ? 1104 : 580)}
                </p>
                <p
                    data-test="serp-description"
                    className="text-ink-2 text-body wrap-anywhere"
                >
                    {date !== null && (
                        <span data-test="serp-date" className="text-ink-3">
                            {`${date} — `}
                        </span>
                    )}
                    {search.description.text === null
                        ? __('Search engines will choose text from the page.')
                        : fitWidth(search.description.text, 14, 920)}
                </p>
            </div>
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
                className="border-line flex overflow-hidden rounded-md border"
            >
                {source !== null ? (
                    <img
                        src={source}
                        alt=""
                        className="min-h-18 w-33 shrink-0 object-cover"
                    />
                ) : waiting ? (
                    <div
                        aria-busy="true"
                        className="bg-surface-2 min-h-18 w-33 shrink-0 motion-safe:animate-pulse"
                    />
                ) : (
                    <div className="border-line text-ink-2 text-caption m-2 flex min-h-14 w-29 shrink-0 items-center justify-center rounded-sm border border-dashed">
                        {__('No image')}
                    </div>
                )}
                <div className="grid min-w-0 flex-1 content-start gap-0.5 px-3 py-2">
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
