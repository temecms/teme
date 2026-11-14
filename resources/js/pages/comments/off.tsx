import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/list/page-header';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { discussion } from '@/routes/settings';

type Props = {
    /** Whether this person may switch comments on (settings.modules.manage). */
    canSwitch: boolean;
};

/**
 * What the Comments screen answers while comments are switched off (1.8c's
 * D15, v9's commentsOff): the route answers as if it weren't there, with
 * this page. It links to the switch for whoever may change it (1.8d's D15).
 */
export default function CommentsOff({ canSwitch }: Props) {
    return (
        <div className="space-y-4 p-6">
            <Head title="Comments are switched off" />
            <PageHeader
                title="Comments are switched off"
                description="This site takes no comments, so there’s nothing to moderate. Comments already stored are kept."
                action={
                    <Button asChild>
                        <Link href={canSwitch ? discussion() : dashboard()}>
                            {canSwitch ? 'Switch comments on' : 'Go to Home'}
                        </Link>
                    </Button>
                }
            />
        </div>
    );
}
