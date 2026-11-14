import { Link } from '@inertiajs/react';
import InboxController from '@/actions/App/Http/Admin/InboxController';
import { EmptyState } from '@/components/list/empty-state';
import { cn } from '@/lib/utils';
import { formatInZone } from '@/lib/zoned-time';
import type { InboxRow } from '@/types/jobs';

/**
 * A person's inbox items, newest first: an unread dot, the title (opening it
 * marks it read and follows its link), its text and when it arrived.
 */
export function InboxList({
    rows,
    siteTimezone,
    inPanel = false,
}: {
    rows: InboxRow[];
    siteTimezone: string;
    /** Drawn inside the header's panel, which has a border already. */
    inPanel?: boolean;
}) {
    if (rows.length === 0) {
        return (
            <EmptyState
                kind="nothing-yet"
                title="Nothing new"
                description="Results of imports and other background work arrive here."
            />
        );
    }

    return (
        <ul
            aria-label="Inbox items"
            className={cn(
                'divide-line divide-y',
                !inPanel && 'border-line rounded-md border',
            )}
        >
            {rows.map((row) => (
                <li
                    key={row.id}
                    data-test="inbox-item"
                    data-read={row.read ? 'true' : 'false'}
                    className="grid grid-cols-[8px_minmax(0,1fr)] gap-x-2.5 gap-y-0.5 px-3.5 py-2.5 text-[13px]"
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'mt-1.5 size-2 rounded-full',
                            !row.read && 'bg-ink',
                        )}
                    />
                    <span className="font-medium">
                        {row.href !== null ? (
                            // A POST that marks the item read, then follows its link (I-221).
                            <Link
                                href={InboxController.open.url({
                                    item: row.id,
                                })}
                                method="post"
                                as="button"
                                className="focus-visible:ring-ring min-h-6 cursor-pointer text-left underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                            >
                                {row.title}
                            </Link>
                        ) : (
                            row.title
                        )}
                        {!row.read && (
                            <span className="sr-only"> (unread)</span>
                        )}
                    </span>
                    {row.text !== null && (
                        <span className="text-ink-2 col-start-2 text-xs">
                            {row.text}
                        </span>
                    )}
                    <span className="text-ink-2 col-start-2 text-xs">
                        {formatInZone(row.at, siteTimezone)}
                    </span>
                </li>
            ))}
        </ul>
    );
}
