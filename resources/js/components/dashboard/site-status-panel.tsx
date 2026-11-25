import { Link, usePage } from '@inertiajs/react';
import { Check, OctagonAlert, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type { StatusBanner } from '@/types/status';

type Row = {
    key: string;
    ok: string;
    /** Check keys this row owns; empty means every other problem. */
    keys: string[];
};

/** Rows whose keys are claimed by a dedicated summary line. */
const CLAIMED = ['backups', 'page-cache'] as const;

/** The worst banner among those whose key is in the list, or null. */
function matchKeys(
    banners: StatusBanner[],
    keys: string[],
): StatusBanner | null {
    const found = banners.filter((banner) => keys.includes(banner.key));

    return (
        found.find((banner) => banner.level === 'error') ??
        found.find((banner) => banner.level === 'warning') ??
        found[0] ??
        null
    );
}

/** Problems that are not already covered by Backups or Page cache. */
function matchOthers(banners: StatusBanner[]): StatusBanner | null {
    const found = banners.filter(
        (banner) => !CLAIMED.includes(banner.key as (typeof CLAIMED)[number]),
    );

    return (
        found.find((banner) => banner.level === 'error') ??
        found.find((banner) => banner.level === 'warning') ??
        found[0] ??
        null
    );
}

/**
 * Home's Site status panel: the same checks as the top-bar site status,
 * summarised as three lines with a tick or a problem and link. People who
 * cannot act on any check see one quiet line instead of false claims.
 */
export function SiteStatusPanel() {
    const { siteStatus, siteStatusCanAct } = usePage().props;

    // Built while drawing so __() runs after the page's words arrive.
    const rows: Row[] = [
        {
            key: 'all',
            ok: __('All checks pass'),
            keys: [],
        },
        {
            key: 'backups',
            ok: __('Backups are current'),
            keys: ['backups'],
        },
        {
            key: 'cache',
            ok: __('Page cache is on'),
            keys: ['page-cache'],
        },
    ];

    if (!siteStatusCanAct) {
        return (
            <p className="text-body text-ink-2" data-test="home-site-status">
                {__('Nothing here for you to act on')}
            </p>
        );
    }

    return (
        <ul className="grid gap-2" data-test="home-site-status">
            {rows.map((row) => {
                const problem =
                    row.key === 'all'
                        ? matchOthers(siteStatus)
                        : matchKeys(siteStatus, row.keys);
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
