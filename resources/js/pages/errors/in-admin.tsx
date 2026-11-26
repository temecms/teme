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
    /** Your name, for a refusal that names who is signed in. */
    name?: string | null;
    /** Your role's label, for a refusal. */
    role: string | null;
    /** The role's key (author, editor…), for a/an in the signed-in line. */
    roleKey?: string | null;
    /** A policy's own reason for refusing, when it gave one. */
    reason: string | null;
    /** A short name for the page that was refused. */
    page?: string | null;
    /** The permission the route asked for, in plain words. */
    permission?: string | null;
    /** The roles that hold that permission, in words. */
    holders?: string | null;
};

/** "You’re signed in as …, an author." — a/an baked into each role's phrase. */
function signedInAs(name: string, roleKey: string): string {
    switch (roleKey) {
        case 'administrator':
            return __('You’re signed in as :name, an administrator.', { name });
        case 'editor':
            return __('You’re signed in as :name, an editor.', { name });
        case 'author':
            return __('You’re signed in as :name, an author.', { name });
        case 'contributor':
            return __('You’re signed in as :name, a contributor.', { name });
        default:
            return __('You’re signed in as :name, a :role.', {
                name,
                role: roleKey,
            });
    }
}

/**
 * A refused or missing admin page drawn inside the admin, the menu and top
 * bar kept: the title and what happened, then a panel with the way on.
 */
export default function InAdminError({
    status,
    site,
    name = null,
    role: _role,
    roleKey = null,
    reason,
    page = null,
    permission = null,
    holders = null,
}: Props) {
    const refused = status === 403;
    const title = refused
        ? page === null
            ? __('You can’t open this page')
            : __('You can’t open :page', { page })
        : __('We can’t find that page');
    const description = refused
        ? (reason ??
          (page !== null &&
          holders !== null &&
          name !== null &&
          roleKey !== null
              ? [
                    __(':page is for :roles.', { page, roles: holders }),
                    signedInAs(name, roleKey),
                    permission !== null
                        ? __('It needs the :permission permission.', {
                              permission,
                          })
                        : null,
                ]
                    .filter((line): line is string => line !== null)
                    .join(' ')
              : permission !== null
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
                                ? undefined
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
