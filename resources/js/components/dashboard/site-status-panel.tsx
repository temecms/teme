import { Link, usePage } from '@inertiajs/react';
import { Check, OctagonAlert, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type { StatusBanner } from '@/types/status';

type Row = {
    key: string;
    ok: string;
    labels: string[];
};

/** The three summary lines Home shows, matched to top-bar site status labels. */
const ROWS: Row[] = [
    {
        key: 'all',
        ok: __('All checks pass'),
        labels: [],
    },
    {
        key: 'backups',
        ok: __('Backups are current'),
        labels: ['Backups'],
    },
    {
        key: 'cache',
        ok: __('Page cache is on'),
        labels: ['Page cache'],
    },
];

/** The worst banner among those whose label is in the list, or null. */
function match(banners: StatusBanner[], labels: string[]): StatusBanner | null {
    if (labels.length === 0) {
        return banners[0] ?? null;
    }

    const found = banners.filter((banner) => labels.includes(banner.label));

    return (
        found.find((banner) => banner.level === 'error') ??
        found.find((banner) => banner.level === 'warning') ??
        found[0] ??
        null
    );
}

/**
 * Home's Site status panel: the same checks as the top-bar site status,
 * summarised as three lines with a tick or a problem and link.
 */
export function SiteStatusPanel() {
    const { siteStatus } = usePage().props;

    return (
        <ul className="grid gap-2" data-test="home-site-status">
            {ROWS.map((row) => {
                const problem = match(siteStatus, row.labels);
                const ok = problem === null;
                const Icon = ok
                    ? Check
                    : problem.level === 'error'
                      ? OctagonAlert
                      : TriangleAlert;

                return (
                    <li
                        key={row.key}
                        className="flex items-start gap-2"
                        data-test={`home-site-status-${row.key}`}
                    >
                        <Icon
                            aria-hidden="true"
                            className={
                                ok
                                    ? 'text-ok mt-0.5 size-4 shrink-0'
                                    : problem.level === 'error'
                                      ? 'text-danger-ink mt-0.5 size-4 shrink-0'
                                      : 'text-warn-ink mt-0.5 size-4 shrink-0'
                            }
                        />
                        <div className="grid min-w-0 flex-1 gap-1">
                            <p className="text-body text-ink">
                                {ok ? row.ok : problem.message}
                            </p>
                            {!ok &&
                                problem.actionUrl !== null &&
                                problem.actionLabel !== null && (
                                    <Button
                                        variant="neutral"
                                        size="sm"
                                        className="justify-self-start"
                                        asChild
                                    >
                                        <Link href={problem.actionUrl}>
                                            {problem.actionLabel}
                                        </Link>
                                    </Button>
                                )}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
