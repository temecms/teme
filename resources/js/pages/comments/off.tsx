import { Head, Link } from '@inertiajs/react';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import { dashboard } from '@/routes';
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

    return (
        <ListPage
            title={title}
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
        </ListPage>
    );
}
