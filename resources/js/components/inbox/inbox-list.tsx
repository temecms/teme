import { Link } from '@inertiajs/react';
import InboxController from '@/actions/App/Http/Admin/InboxController';
import { EmptyState } from '@/components/list/empty-state';
import { Panel } from '@/components/ui/panel';
import { TextButton } from '@/components/ui/text-button';
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

    const list = (
        <ul aria-label="Inbox items" className="divide-line divide-y">
            {rows.map((row) => (
                <li
                    key={row.id}
                    data-test="inbox-item"
                    data-read={row.read ? 'true' : 'false'}
                    className="text-body flex gap-3 px-4 py-2"
                >
                    <span className="flex h-(--ctl-h-sm) shrink-0 items-center">
                        <span
                            aria-hidden="true"
                            className={cn(
                                'size-2 rounded-full',
                                !row.read && 'bg-ink',
                            )}
                        />
                    </span>
                    <div className="grid min-w-0 flex-1">
                        <span className="flex min-h-(--ctl-h-sm) items-center font-medium">
                            <span>
                                {row.href !== null ? (
                                    // A POST that marks the item read, then follows its link (I-221).
                                    <TextButton
                                        asChild
                                        className="min-h-(--ctl-h-sm) cursor-pointer text-left"
                                    >
                                        <Link
                                            href={InboxController.open.url({
                                                item: row.id,
                                            })}
                                            method="post"
                                            as="button"
                                        >
                                            {row.title}
                                        </Link>
                                    </TextButton>
                                ) : (
                                    row.title
                                )}
                                {!row.read && (
                                    <span className="sr-only"> (unread)</span>
                                )}
                            </span>
                        </span>
                        {row.text !== null && (
                            <span className="text-ink-2 text-small">
                                {row.text}
                            </span>
                        )}
                        <span className="text-ink-2 text-small">
                            {formatInZone(row.at, siteTimezone)}
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );

    return inPanel ? list : <Panel className="overflow-hidden">{list}</Panel>;
}
