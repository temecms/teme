import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { EmptyState } from '@/components/list/empty-state';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import { index as comments } from '@/routes/comments';
import type { CommentsRefusedPage } from '@/types';

/**
 * What the Comments screen answers for an item, or a comment on one, the
 * person doesn't look after: who does, a panel saying whose they are, and a
 * way back to the comments they do look after.
 */
export default function CommentsRefused({
    message,
    heading,
    line,
    back,
}: CommentsRefusedPage) {
    const title = __('Comments');
    const contentCrumb = useSectionCrumb('content');

    setLayoutProps({
        breadcrumbs: [contentCrumb, { title, href: comments() }],
    });

    return (
        <ListPage
            title={title}
            description={message}
            actions={
                <Button asChild variant="primary">
                    <Link href={back.href}>{back.label}</Link>
                </Button>
            }
        >
            <Head title={title} />
            <Panel>
                <EmptyState
                    kind="nothing-yet"
                    icon={Users}
                    title={heading}
                    description={line}
                />
            </Panel>
        </ListPage>
    );
}
