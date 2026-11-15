import { Link } from '@inertiajs/react';
import type { DashboardRow } from '@/types';
import { shownTitle } from '@/lib/titles';
import { __ } from '@/lib/i18n';

/**
 * The rows body of a Home panel: up to five items, each its title as a link
 * and a line in words. Rows wrap; nothing is cut off.
 */
export function DashboardPanelRows({
    rows,
    seeAll,
    label,
}: {
    rows: DashboardRow[];
    seeAll: string | null;
    label: string;
}) {
    if (rows.length === 0) {
        return (
            <p className="text-small text-ink-2">
                {__('Nothing here right now.')}
            </p>
        );
    }

    return (
        <>
            <ul className="grid gap-0">
                {rows.map((row) => (
                    <li key={row.id} className="grid gap-0.5 py-2 first:pt-0">
                        <Link
                            href={row.url}
                            className="text-body text-ink font-medium break-words hover:underline"
                        >
                            {shownTitle(row.title)}
                        </Link>
                        <span className="text-small text-ink-2 break-words">
                            {row.subtitle}
                        </span>
                    </li>
                ))}
            </ul>
            {seeAll !== null && (
                <Link
                    href={seeAll}
                    className="text-small text-accent-ink font-medium underline-offset-2 hover:underline"
                >
                    {__('See all')}
                    <span className="sr-only"> {label}</span>
                </Link>
            )}
        </>
    );
}
