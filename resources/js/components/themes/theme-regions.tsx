import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { edit as editContent } from '@/routes/content';
import { index as regionsIndex } from '@/routes/regions';
import type { RegionState, RegionsIndexPage } from '@/types';

/** A region's state in Content › Regions' words, as its screen says them. */
const STATES: Record<RegionState, string> = {
    empty: 'Empty',
    draft: 'Draft',
    published: 'Published',
    changed: 'Published, with unpublished changes',
};

/**
 * A theme's regions on Site › Themes, as an overview (I-395): each region
 * with the cards it takes, the most blocks it holds and its state, linked to
 * where it is edited; then the regions it leaves unplaced, never hidden.
 * Nothing is edited here.
 */
export function ThemeRegions({
    regions,
    level,
    intro,
    unplacedLine,
    canEdit,
    idPrefix,
}: {
    regions: RegionsIndexPage;
    /** 2 on the page, 3 inside the preview panel. */
    level: 2 | 3;
    /** A line under the heading, as the preview panel's. */
    intro?: string;
    unplacedLine: string;
    /** Whether each region links to where it is edited: never in a preview. */
    canEdit: boolean;
    idPrefix: string;
}) {
    const Heading = level === 2 ? 'h2' : 'h3';
    const Part = level === 2 ? 'h3' : 'h4';
    const theme = regions.theme;
    const count = regions.regions.length;

    return (
        <section
            aria-labelledby={`${idPrefix}-heading`}
            data-test={idPrefix}
            className="grid gap-4"
        >
            <div className="grid gap-1">
                <Heading
                    id={`${idPrefix}-heading`}
                    className="text-lead font-semibold"
                >
                    {theme === null ? 'Regions' : `Regions in ${theme.name}`}
                </Heading>
                {intro !== undefined && (
                    <p className="text-ink-2 text-small">{intro}</p>
                )}
            </div>

            {theme === null ? (
                <p className="text-ink-2 text-small">
                    No theme is in use, so no region is drawn.
                </p>
            ) : (
                <div className="grid gap-2">
                    <div className="grid gap-1">
                        <Part className="text-small font-semibold">
                            {count === 1 ? '1 region' : `${count} regions`}
                        </Part>
                        <p className="text-ink-2 text-small">
                            The places {theme.name} puts the site’s own content,
                            such as a box after each article.
                        </p>
                    </div>
                    {count === 0 ? (
                        <p className="text-ink-2 text-small">
                            {theme.name} declares no regions.
                        </p>
                    ) : (
                        <ul className="border-line divide-line text-small divide-y rounded-md border">
                            {regions.regions.map((region) => (
                                <li
                                    key={region.key}
                                    data-test="theme-region"
                                    className="flex flex-wrap items-center gap-3 px-3 py-2"
                                >
                                    <div className="grid min-w-0 flex-1 gap-1">
                                        <span className="font-semibold">
                                            {region.label}
                                        </span>
                                        <span className="text-ink-2 text-caption">
                                            {region.cardLabels.length === 0
                                                ? 'Text only'
                                                : region.cardLabels.join(
                                                      ', ',
                                                  )}{' '}
                                            · {region.limit}
                                        </span>
                                    </div>
                                    <span
                                        className={
                                            region.state === 'empty'
                                                ? 'text-ink-2'
                                                : undefined
                                        }
                                    >
                                        {STATES[region.state]}
                                    </span>
                                    {canEdit && (
                                        <Button
                                            variant="neutral"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={
                                                    region.contentId === null
                                                        ? regionsIndex().url
                                                        : editContent(
                                                              region.contentId,
                                                          ).url
                                                }
                                            >
                                                Edit in Regions
                                                <span className="sr-only">
                                                    : {region.label}
                                                </span>
                                            </Link>
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            <div className="grid gap-2" data-test={`${idPrefix}-unplaced`}>
                <div className="grid gap-1">
                    <Part className="text-small font-semibold">
                        From other themes
                        {regions.others.length > 0 && (
                            <span className="text-ink-2 tabular-nums">
                                {' '}
                                {regions.others.length}
                            </span>
                        )}
                    </Part>
                    <p className="text-ink-2 text-small">{unplacedLine}</p>
                </div>
                {regions.others.length === 0 ? (
                    <p className="text-ink-2 text-small">
                        Nothing from other themes.
                    </p>
                ) : (
                    <ul className="border-line divide-line text-small divide-y rounded-md border">
                        {regions.others.map((row) => (
                            <li
                                key={row.slug}
                                data-test="theme-unplaced-row"
                                className="grid gap-1 px-3 py-2"
                            >
                                <span className="font-semibold">
                                    {row.label}
                                </span>
                                <span className="text-ink-2">
                                    {row.themeName === null
                                        ? 'Theme no longer installed'
                                        : `From ${row.themeName}`}{' '}
                                    · {STATES[row.state]}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
