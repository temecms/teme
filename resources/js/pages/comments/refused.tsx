import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/list/page-header';
import { Button } from '@/components/ui/button';
import type { CommentsRefusedPage } from '@/types';

/**
 * What the Comments screen answers for an item, or a comment on one, the
 * person doesn't look after (1.8c's D15, v9's commentsNotYours): who does,
 * and a way back to the comments they do look after.
 */
export default function CommentsRefused({
    message,
    back,
}: CommentsRefusedPage) {
    return (
        <div className="space-y-4 p-6">
            <Head title="Comments" />
            <PageHeader
                title="Comments"
                description={message}
                action={
                    <Button asChild>
                        <Link href={back.href}>{back.label}</Link>
                    </Button>
                }
            />
        </div>
    );
}
