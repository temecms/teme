import { Link } from '@inertiajs/react';
import {
    CheckCircle2Icon,
    CircleAlertIcon,
    InboxIcon,
    OctagonAlertIcon,
} from 'lucide-react';
import InboxController from '@/actions/App/Http/Admin/InboxController';
import { EmptyState } from '@/components/list/empty-state';
import { Panel } from '@/components/ui/panel';
import { PluginMark } from '@/components/ui/plugin-mark';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { formatRecent } from '@/lib/dates';
import { cn } from '@/lib/utils';
import type { InboxRow } from '@/types/jobs';

/** A kind icon from how the title is worded, until the inbox sends a kind. */
function KindIcon({ title }: { title: string }) {
    const lower = title.toLowerCase();
    const className = 'size-5 shrink-0';

    if (lower.startsWith('failed:') || lower.includes('failed')) {
        return (
            <OctagonAlertIcon
                aria-hidden="true"
                className={cn(className, 'text-danger-ink')}
            />
        );
    }

    if (lower.startsWith('finished:') || lower.includes('finished')) {
        return (
            <CheckCircle2Icon
                aria-hidden="true"
                className={cn(className, 'text-ok-ink')}
            />
        );
    }

    if (lower.includes('warning') || lower.includes('needs')) {
        return (
            <CircleAlertIcon
                aria-hidden="true"
                className={cn(className, 'text-warn-ink')}
            />
        );
    }

    return (
        <InboxIcon aria-hidden="true" className={cn(className, 'text-ink-3')} />
    );
}

/**
 * A person's inbox items, newest first: a coloured kind icon, an accent
 * unread dot, the title with the plugin mark after it, a Failed pill when
 * the result failed, Mark as read at the end, and when it arrived.
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
                title={__('Nothing new')}
                description={__(
                    'Results of imports and other background work arrive here.',
                )}
            />
        );
    }

    const list = (
        <ul aria-label={__('Inbox items')} className="divide-line divide-y">
            {rows.map((row) => {
                const failed =
                    row.title.toLowerCase().startsWith('failed:') ||
                    row.title.toLowerCase().includes(' failed');
                const when = formatRecent(
                    row.at,
                    {
                        today: (time) => __('Today, :time', { time }),
                        yesterday: (time) => __('Yesterday, :time', { time }),
                    },
                    Date.now(),
                    siteTimezone,
                );

                return (
                    <li
                        key={row.id}
                        data-test="inbox-item"
                        data-read={row.read ? 'true' : 'false'}
                        className="text-body flex gap-3 px-4 py-3"
                    >
                        <span className="flex h-(--ctl-h-sm) shrink-0 items-center gap-2">
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2 rounded-full',
                                    !row.read && 'bg-accent',
                                )}
                            />
                            <KindIcon title={row.title} />
                        </span>
                        <div className="grid min-w-0 flex-1 gap-1">
                            <span className="flex min-h-(--ctl-h-sm) flex-wrap items-center gap-2 font-medium">
                                <span className="min-w-0">
                                    {row.href !== null ? (
                                        // A POST that marks the item read, then follows its link.
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
                                        <span className="sr-only">
                                            {' '}
                                            ({__('unread')})
                                        </span>
                                    )}
                                </span>
                                {row.plugin !== null && (
                                    <PluginMark
                                        data-test="inbox-plugin"
                                        name={row.plugin}
                                    />
                                )}
                                {failed && (
                                    <StatusPill tone="danger">
                                        {__('Failed')}
                                    </StatusPill>
                                )}
                            </span>
                            {row.text !== null && (
                                <span className="text-ink-2 text-small">
                                    {row.text}
                                </span>
                            )}
                            <span className="text-ink-3 text-small">
                                {when}
                            </span>
                        </div>
                        {!row.read && (
                            <div className="flex shrink-0 items-start pt-1">
                                <TextButton asChild className="text-small">
                                    <Link
                                        href={InboxController.read.url({
                                            item: row.id,
                                        })}
                                        method="post"
                                        as="button"
                                        data-test="inbox-mark-read"
                                        preserveScroll
                                    >
                                        {__('Mark as read')}
                                    </Link>
                                </TextButton>
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );

    return inPanel ? list : <Panel className="overflow-hidden">{list}</Panel>;
}
