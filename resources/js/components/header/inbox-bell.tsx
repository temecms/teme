import { Link, router, useHttp } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useCallback, useState } from 'react';
import InboxController from '@/actions/App/Http/Admin/InboxController';
import { HeaderPanel } from '@/components/header/header-panel';
import { InboxList } from '@/components/inbox/inbox-list';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { HeaderCounts, InboxLatestAnswer } from '@/types/jobs';

/**
 * The header's bell (I-221): it counts the inbox items a person hasn't read,
 * "99+" from 100, and opens the latest ten with Mark all as read and a way to
 * the inbox.
 */
export function InboxBell({ counts }: { counts: HeaderCounts | null }) {
    const [open, setOpen] = useState(false);
    const inbox = useInboxLatest();
    const { latest, load } = inbox;

    if (counts === null) {
        return null;
    }

    const unread = counts.unread;

    return (
        <HeaderPanel
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    load();
                }
            }}
            title="Inbox"
            testId="inbox-panel"
            trigger={
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-test="inbox-bell"
                    aria-label={
                        unread > 0
                            ? `Inbox, ${unread >= 100 ? '99+' : unread} unread`
                            : 'Inbox'
                    }
                    className="gap-1"
                >
                    <Bell aria-hidden="true" />
                    {unread > 0 && (
                        <span
                            aria-hidden="true"
                            className="text-small font-semibold tabular-nums"
                        >
                            {unread >= 100 ? '99+' : unread}
                        </span>
                    )}
                </Button>
            }
            action={
                unread > 0 ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-test="inbox-mark-all"
                        onClick={() =>
                            router.post(
                                InboxController.readAll.url(),
                                {},
                                { preserveScroll: true, onSuccess: load },
                            )
                        }
                    >
                        Mark all as read
                    </Button>
                ) : undefined
            }
            footer={
                <Link
                    href={latest?.inboxHref ?? '/admin/inbox'}
                    className="underline-offset-4 hover:underline"
                >
                    Open your inbox
                </Link>
            }
        >
            <InboxPanelBody inbox={inbox} />
        </HeaderPanel>
    );
}

/** The latest ten inbox items, loaded on demand, as the panel and the status sheet show them. */
export function useInboxLatest(): {
    latest: InboxLatestAnswer | null;
    failed: boolean;
    load: () => void;
} {
    const [latest, setLatest] = useState<InboxLatestAnswer | null>(null);
    const [failed, setFailed] = useState(false);
    const { submit } = useHttp();

    const load = useCallback(() => {
        void (submit(InboxController.latest()) as Promise<InboxLatestAnswer>)
            .then((answer) => {
                setLatest(answer);
                setFailed(false);
            })
            .catch(() => setFailed(true));
    }, [submit]);

    return { latest, failed, load };
}

/** The inbox panel's content, also drawn in the small screens' status sheet. */
export function InboxPanelBody({
    inbox: { latest, failed },
}: {
    inbox: ReturnType<typeof useInboxLatest>;
}) {
    return (
        <>
            {failed && (
                <p role="status" className="text-ink-2 text-small px-4 py-3">
                    Your inbox couldn’t be loaded. Try again in a moment.
                </p>
            )}
            {!failed && latest === null && (
                <div className="grid gap-2 px-4 py-3" role="status">
                    <Skeleton className="h-10 motion-safe:animate-pulse" />
                    <Skeleton className="h-10 motion-safe:animate-pulse" />
                </div>
            )}
            {!failed && latest !== null && (
                <InboxList
                    rows={latest.rows}
                    siteTimezone={latest.siteTimezone}
                    inPanel
                />
            )}
        </>
    );
}
