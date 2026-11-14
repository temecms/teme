import { Form, Head } from '@inertiajs/react';
import InboxController from '@/actions/App/Http/Admin/InboxController';
import { InboxList } from '@/components/inbox/inbox-list';
import { Pager } from '@/components/list/pager';
import { ListPage } from '@/components/patterns/list-page';
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
        <ListPage
            title="Inbox"
            description="Results of background work you started, such as imports. Each is kept for 90 days."
            actions={
                unread > 0 && (
                    <>
                        <span
                            data-test="inbox-unread"
                            className="text-ink-2 text-small"
                        >
                            {unread >= 100 ? '99+' : unread.toLocaleString()}{' '}
                            unread
                        </span>
                        <Form
                            {...InboxController.readAll.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="neutral"
                                    data-test="inbox-read-all"
                                    disabled={processing}
                                >
                                    Mark all as read
                                </Button>
                            )}
                        </Form>
                    </>
                )
            }
        >
            <Head title="Inbox" />

            <div className="grid gap-4">
                <InboxList rows={rows} siteTimezone={siteTimezone} />

                {rows.length > 0 && <Pager newer={newer} older={older} />}
            </div>
        </ListPage>
    );
}

Inbox.layout = {
    breadcrumbs: [{ title: 'Inbox', href: index() }],
};
