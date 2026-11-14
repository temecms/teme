import { usePage } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { InboxPanelBody, useInboxLatest } from '@/components/header/inbox-bell';
import { JobsPanelBody } from '@/components/header/jobs-button';
import { SiteStatusBody } from '@/components/header/site-status';
import type { useHeaderActivity } from '@/components/header/use-header-activity';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { __, __n } from '@/lib/i18n';

/** One of the sheet's three sections, under its heading. */
function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="border-line grid border-t py-2">
            <h3 className="text-caption text-ink-3 px-4 py-2 font-semibold uppercase">
                {title}
            </h3>
            {children}
        </section>
    );
}

/**
 * The small screens' one status button and the sheet it opens from the end:
 * the person's jobs, the site status and their notifications, each the same
 * content as its panel at full width. Its count is what wants a look plus
 * what is unread.
 */
export function StatusSheet({
    activity,
}: {
    activity: ReturnType<typeof useHeaderActivity>;
}) {
    const { siteStatus } = usePage().props;
    const [open, setOpen] = useState(false);
    const inbox = useInboxLatest();
    const count = siteStatus.length + (activity.counts?.unread ?? 0);
    // As in the wide top bar: jobs once the person has had one, the inbox once
    // the verified routes behind both will answer.
    const hasJobs = activity.counts?.hasJobs === true;
    const hasInbox = activity.counts !== null;

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next && hasJobs) {
                    activity.loadJobs();
                }

                if (next && hasInbox) {
                    inbox.load();
                }
            }}
        >
            <SheetTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    data-test="status-button"
                    aria-label={__n(
                        'Activity and status: :count new|Activity and status: :count new',
                        count,
                    )}
                    className="relative"
                >
                    <Activity aria-hidden="true" />
                    {count > 0 && (
                        <span
                            aria-hidden="true"
                            className="bg-ink text-page text-caption pointer-events-none absolute end-0 top-0 inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 font-mono"
                        >
                            {count >= 100 ? '99+' : count}
                        </span>
                    )}
                </Button>
            </SheetTrigger>
            <SheetContent
                side="right"
                data-test="status-sheet"
                aria-describedby={undefined}
                className="gap-0"
            >
                <SheetHeader>
                    <SheetTitle>{__('Activity and status')}</SheetTitle>
                </SheetHeader>
                <ScrollArea className="min-h-0 flex-1">
                    {hasJobs && (
                        <Section title={__('Jobs')}>
                            <JobsPanelBody activity={activity} />
                        </Section>
                    )}
                    <Section title={__('Site status')}>
                        <SiteStatusBody banners={siteStatus} />
                    </Section>
                    {hasInbox && (
                        <Section title={__('Notifications')}>
                            <InboxPanelBody inbox={inbox} />
                        </Section>
                    )}
                </ScrollArea>
            </SheetContent>
        </Sheet>
    );
}
