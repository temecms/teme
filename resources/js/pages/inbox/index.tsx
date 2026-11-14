import { Form, Head } from '@inertiajs/react';
import InboxController from '@/actions/App/Http/Admin/InboxController';
import { InboxList } from '@/components/inbox/inbox-list';
import { PageHeader } from '@/components/list/page-header';
import { Pager } from '@/components/list/pager';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/inbox';
import type { InboxPage } from '@/types/jobs';

/** The inbox (I-221): the signed-in person's background results, read and unread. */
export default function Inbox({
    rows,
    newer,
    older,
    unread,
    siteTimezone,
}: InboxPage) {
    return (
        <>
            <Head title="Inbox" />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader
                    title="Inbox"
                    description="Results of background work you started, such as imports. Each is kept for 90 days."
                    action={
                        unread > 0 && (
                            <div className="flex items-center gap-3">
                                <span
                                    data-test="inbox-unread"
                                    className="text-muted-foreground text-[13px]"
                                >
                                    {unread >= 100
                                        ? '99+'
                                        : unread.toLocaleString()}{' '}
                                    unread
                                </span>
                                <Form
                                    {...InboxController.readAll.form()}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            data-test="inbox-read-all"
                                            disabled={processing}
                                        >
                                            Mark all as read
                                        </Button>
                                    )}
                                </Form>
                            </div>
                        )
                    }
                />

                <InboxList rows={rows} siteTimezone={siteTimezone} />

                {rows.length > 0 && <Pager newer={newer} older={older} />}
            </div>
        </>
    );
}

Inbox.layout = {
    breadcrumbs: [{ title: 'Inbox', href: index() }],
};
