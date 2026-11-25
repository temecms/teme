import { Form, Head } from '@inertiajs/react';
import InboxController from '@/actions/App/Http/Admin/InboxController';
import { InboxList } from '@/components/inbox/inbox-list';
import { Pager } from '@/components/list/pager';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
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
    const fromPlugins = rows.some((row) => row.plugin !== null);

    return (
        <ListPage
            title={__('Inbox')}
            description={__(
                'Results of background work you started, such as imports. Each is kept for 90 days.',
            )}
            actions={
                unread > 0 && (
                    <>
                        <span
                            data-test="inbox-unread"
                            className="text-ink-2 text-small"
                        >
                            {unread >= 100 ? '99+' : unread.toLocaleString()}{' '}
                            {__('unread')}
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
                                    {__('Mark all as read')}
                                </Button>
                            )}
                        </Form>
                    </>
                )
            }
        >
            <Head title={__('Inbox')} />

            <div className="grid gap-4">
                {fromPlugins && (
                    <Notice tone="info" data-test="inbox-plugin-notice">
                        {__(
                            'Notices from plugins also arrive here, marked with the plugin’s name.',
                        )}
                    </Notice>
                )}
                <InboxList rows={rows} siteTimezone={siteTimezone} />

                {rows.length > 0 && <Pager newer={newer} older={older} />}
            </div>
        </ListPage>
    );
}

Inbox.layout = {
    breadcrumbs: [{ title: 'Inbox', href: index() }],
};
