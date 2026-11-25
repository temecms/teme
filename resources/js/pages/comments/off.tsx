import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import { EmptyState } from '@/components/list/empty-state';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index as comments } from '@/routes/comments';
import { discussion } from '@/routes/settings';

type Props = {
    /** Whether this person may switch comments on (settings.modules.manage). */
    canSwitch: boolean;
};

/**
 * What the Comments screen answers while comments are switched off: the
 * route answers as if it weren't there, with this page. It links to the
 * switch for whoever may change it.
 */
export default function CommentsOff({ canSwitch }: Props) {
    const title = __('Comments are switched off');
    const contentCrumb = useSectionCrumb('content');

    setLayoutProps({
        breadcrumbs: [
            contentCrumb,
            { title: __('Comments'), href: comments() },
        ],
    });

    return (
        <ListPage
            title={title}
            showTitle
            description={__(
                'This site takes no comments, so there’s nothing to moderate. Comments already stored are kept.',
            )}
            actions={
                <Button asChild variant="primary">
                    <Link href={canSwitch ? discussion() : dashboard()}>
                        {canSwitch
                            ? __('Switch comments on')
                            : __('Go to Home')}
                    </Link>
                </Button>
            }
        >
            <Head title={title} />
            <Panel>
                <EmptyState
                    kind="nothing-yet"
                    icon={MessageSquare}
                    title={__('No comment form on the site')}
                    description={
                        canSwitch
                            ? __(
                                  'Switch comments on under Settings › Discussion. The queue, the views and the keys come back with them.',
                              )
                            : __(
                                  'Someone who may change the site’s settings can switch them on. Until then, nothing is waiting for you here.',
                              )
                    }
                />
            </Panel>
        </ListPage>
    );
}
