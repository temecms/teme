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
                    size="icon"
                    data-test="inbox-bell"
                    aria-label={
                        unread > 0
                            ? `Inbox, ${unread >= 100 ? '99+' : unread} unread`
                            : 'Inbox'
                    }
                    className="relative max-md:size-11"
                >
                    <Bell aria-hidden="true" />
                    {unread > 0 && (
                        <span
                            aria-hidden="true"
                            className="bg-foreground text-card absolute top-[3px] right-[2px] h-4 min-w-4 rounded-lg px-1 font-mono text-[10px] leading-4"
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
            {failed && (
                <p
                    role="status"
                    className="text-muted-foreground p-3.5 text-[13px]"
                >
                    Your inbox couldn’t be loaded. Try again in a moment.
                </p>
            )}
            {!failed && latest === null && (
                <div className="grid gap-2 p-3.5" role="status">
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
        </HeaderPanel>
    );
}
