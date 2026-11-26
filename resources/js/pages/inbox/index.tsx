import { Form, Head, setLayoutProps } from '@inertiajs/react';
import InboxController from '@/actions/App/Http/Admin/InboxController';
import { InboxList } from '@/components/inbox/inbox-list';
import { Pager } from '@/components/list/pager';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { adminLocale } from '@/lib/dates';
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
    setLayoutProps({ breadcrumbs: [{ title: __('Inbox'), href: index() }] });

    return (
        <ListPage
            title={__('Inbox')}
            description={__(
                'Results of background work you started, such as imports, and notices from plugins. Each is kept for 90 days.',
            )}
            actions={
                unread > 0 && (
                    <>
                        <span
                            data-test="inbox-unread"
                            className="text-ink-2 text-small"
                        >
                            {unread >= 100
                                ? '99+'
                                : unread.toLocaleString(adminLocale())}{' '}
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
                <InboxList rows={rows} siteTimezone={siteTimezone} />

                {rows.length > 0 && <Pager newer={newer} older={older} />}
            </div>
        </ListPage>
    );
}
