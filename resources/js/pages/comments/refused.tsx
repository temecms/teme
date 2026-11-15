import { Head, Link } from '@inertiajs/react';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type { CommentsRefusedPage } from '@/types';

/**
 * What the Comments screen answers for an item, or a comment on one, the
 * person doesn't look after: who does, and a way back to the comments they
 * do look after.
 */
export default function CommentsRefused({
    message,
    back,
}: CommentsRefusedPage) {
    const title = __('Comments');

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
        </ListPage>
    );
}
