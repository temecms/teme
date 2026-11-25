import { Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import { Eye, Search } from 'lucide-react';
import { EmptyState } from '@/components/list/empty-state';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { __ } from '@/lib/i18n';
import { dashboard } from '@/routes';

type Props = {
    status: 403 | 404;
    /** The site's name, for "ask an administrator of …". */
    site: string | null;
    /** Your role's label, for a refusal. */
    role: string | null;
    /** A policy's own reason for refusing, when it gave one. */
    reason: string | null;
    /** A short name for the page that was refused. */
    page?: string | null;
    /** The permission the route asked for, when the route names one. */
    permission?: string | null;
};

/**
 * A refused or missing admin page drawn inside the admin, the menu and top
 * bar kept: the title and what happened, then a panel with the way on.
 */
export default function InAdminError({
    status,
    site,
    role,
    reason,
    page = null,
    permission = null,
}: Props) {
    const refused = status === 403;
    const title = refused
        ? page === null
            ? __('You can’t open this page')
            : __('You can’t open :page', { page })
        : __('We can’t find that page');
    const description = refused
        ? (reason ??
          (permission !== null
              ? site === null
                  ? __(
                        'This page needs the :permission permission. If you need it, ask an administrator to change your role.',
                        { permission },
                    )
                  : __(
                        'This page needs the :permission permission. If you need it, ask an administrator of :site to change your role.',
                        { permission, site },
                    )
              : site === null
                ? __(
                      'Your account doesn’t have permission to do that. If you need it, ask an administrator to change your role.',
                  )
                : __(
                      'Your account doesn’t have permission to do that. If you need it, ask an administrator of :site to change your role.',
                      { site },
                  )))
        : __(
              'The address may be mistyped, or the item may have moved to the Trash. Search the admin to find it.',
          );
    const label = refused ? __('Error 403') : __('Error 404');

    const { url } = usePage();

    setLayoutProps({ breadcrumbs: [{ title: label, href: url }] });

    const home = (
        <Button asChild>
            <Link href={dashboard()}>{__('Back to Home')}</Link>
        </Button>
    );
    const second = refused ? (
        <Button
            type="button"
            variant="neutral"
            data-test="error-go-back"
            onClick={() => window.history.back()}
        >
            {__('Go back')}
        </Button>
    ) : (
        <Button
            type="button"
            variant="neutral"
            data-test="error-search"
            onClick={() => window.dispatchEvent(new Event('teme:palette'))}
        >
            {__('Search the admin')}
        </Button>
    );

    return (
        <>
            <Head title={label} />
            <ListPage title={title} description={description} showTitle>
                <Panel data-test={`error-${status}-in-admin`}>
                    <EmptyState
                        kind="nothing-yet"
                        icon={refused ? Eye : Search}
                        title={label}
                        description={
                            refused
                                ? role === null
                                    ? undefined
                                    : __('Your role is :role.', { role })
                                : __('Nothing is at this address.')
                        }
                        action={
                            <div className="flex flex-wrap justify-center gap-2">
                                {home}
                                {second}
                            </div>
                        }
                    />
                </Panel>
            </ListPage>
        </>
    );
}
