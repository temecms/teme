import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import { index as regionsIndex } from '@/routes/regions';
import type { RegionsIndexPage } from '@/types';

/**
 * A theme's regions on Site › Themes as chips and a link to Content ›
 * Regions (issue 714): nothing is edited here, and the long list lives on
 * Regions itself.
 */
export function ThemeRegions({
    regions,
    level,
    intro,
    canEdit,
    idPrefix,
}: {
    regions: RegionsIndexPage;
    /** 2 on the page, 3 inside the preview panel. */
    level: 2 | 3;
    /** A line under the heading. */
    intro?: string;
    /** Whether Open Regions is offered. */
    canEdit: boolean;
    idPrefix: string;
}) {
    const Heading = level === 2 ? 'h2' : 'h3';
    const theme = regions.theme;
    const count = regions.regions.length;

    return (
        <section
            aria-labelledby={`${idPrefix}-heading`}
            data-test={idPrefix}
            className="grid gap-3"
        >
            <div className="grid gap-1">
                <Heading
                    id={`${idPrefix}-heading`}
                    className="text-lead font-semibold"
                >
                    {theme === null
                        ? __('Regions')
                        : __('Regions in :name', { name: theme.name })}
                </Heading>
                {intro !== undefined && (
                    <p className="text-ink-2 text-small">{intro}</p>
                )}
            </div>

            {theme === null ? (
                <p className="text-ink-2 text-small">
                    {__('No theme is in use, so no region is drawn.')}
                </p>
            ) : count === 0 ? (
                <p className="text-ink-2 text-small" data-test="theme-region">
                    {__(':name declares no regions.', { name: theme.name })}
                </p>
            ) : (
                <div className="flex flex-wrap items-center gap-2">
                    <ul className="flex flex-wrap gap-2">
                        {regions.regions.map((region) => (
                            <li key={region.key} data-test="theme-region">
                                <span className="bg-surface-3 text-ink-2 text-small inline-flex h-6 max-w-33 items-center truncate rounded-sm px-2">
                                    {region.label}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {canEdit && (
                        <Button variant="neutral" size="sm" asChild>
                            <Link
                                href={regionsIndex().url}
                                data-test="theme-open-regions"
                            >
                                {__('Open Regions')}
                            </Link>
                        </Button>
                    )}
                </div>
            )}

            {regions.others.length > 0 && (
                <p
                    className="text-ink-2 text-small"
                    data-test={`${idPrefix}-unplaced`}
                >
                    {__(
                        'Content kept from other themes stays in Content › Regions.',
                    )}
                </p>
            )}
        </section>
    );
}
