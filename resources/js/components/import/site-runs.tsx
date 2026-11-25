import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import type { ImportRunRow } from '@/types/import';

const TONES: Record<ImportRunRow['state'], 'ok' | 'warn' | 'info'> = {
    finished: 'ok',
    stopped: 'warn',
    running: 'info',
};

/**
 * A site's past imports on the History tab (issue 699): date, state pill,
 * one-line result, and Report — newest first.
 */
export function SiteRuns({
    runs,
    shownId,
    siteTimezone,
}: {
    runs: ImportRunRow[];
    shownId: string | null;
    siteTimezone: string;
}) {
    if (runs.length === 0) {
        return (
            <p className="text-ink-2" data-test="import-history-empty">
                {__('No past imports yet.')}
            </p>
        );
    }

    return (
        <ul
            aria-label={__('Past imports of this site')}
            data-test="import-history"
            className="grid gap-1"
        >
            {runs.map((row) => {
                const line =
                    row.id === shownId
                        ? __('The one shown above')
                        : __(':created created, :failed failed · :kind', {
                              created: row.counts.created.toLocaleString(),
                              failed: row.counts.failed.toLocaleString(),
                              kind: row.kindLabel,
                          });

                return (
                    <li
                        key={row.id}
                        className="hover:bg-surface-2 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md px-3 py-3"
                    >
                        <time
                            dateTime={row.startedAt}
                            className="text-body min-w-40 font-semibold"
                        >
                            {formatInZone(row.startedAt, siteTimezone)}
                        </time>
                        <StatusPill tone={TONES[row.state]}>
                            {row.stateLabel}
                        </StatusPill>
                        <span className="text-small text-ink-2 min-w-0 flex-1">
                            {line}
                        </span>
                        {row.id !== shownId && (
                            <Button size="sm" variant="outline" asChild>
                                <Link
                                    href={row.href}
                                    aria-label={__(
                                        'Show the report of the import started :date',
                                        {
                                            date: formatInZone(
                                                row.startedAt,
                                                siteTimezone,
                                            ),
                                        },
                                    )}
                                >
                                    {__('Report')}
                                </Link>
                            </Button>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
