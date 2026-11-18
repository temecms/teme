import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { InPageNav } from '@/components/patterns/in-page-nav';
import { ListPage } from '@/components/patterns/list-page';
import { DeleteDialog } from '@/components/people/delete-dialog';
import { InviteDialog } from '@/components/people/invite-dialog';
import { PeopleCards } from '@/components/people/people-cards';
import { PersonCell } from '@/components/people/person-cell';
import { RoleDialog } from '@/components/people/role-dialog';
import {
    accountActions,
    accountMoreActions,
    invitationActions,
    invitationMoreActions,
    MoreActions,
} from '@/components/people/row-actions';
import type { Managing } from '@/components/people/row-actions';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import { useShellWidth } from '@/hooks/use-shell-width';
import { __, __n } from '@/lib/i18n';
import { index as usersIndex } from '@/routes/users';
import type { RoleOption, UserRow, UsersIndexPage } from '@/types';
import { formatDate, formatDateTime } from '@/lib/dates';

/** A role's label, as the role choices name it; "—" for none. */
function roleLabel(roles: RoleOption[], role: string | null): string {
    return roles.find((option) => option.value === role)?.label ?? '—';
}

/** A person's state: active or suspended, and two-factor sign-in when on. */
function AccountPills({ user }: { user: UserRow }) {
    return (
        <>
            {user.status === 'suspended' ? (
                <StatusPill tone="danger">{__('Suspended')}</StatusPill>
            ) : (
                <StatusPill tone="ok">{__('Active')}</StatusPill>
            )}
            {user.twoFactor && (
                <StatusPill tone="neutral" flat>
                    {__('Two-factor')}
                </StatusPill>
            )}
        </>
    );
}

/** An invitation's state: until when its link works, and what went wrong. */
function InvitationPills({ user }: { user: UserRow }) {
    return (
        <>
            {user.linkWorksUntil ? (
                <span className="text-ink-2">
                    {__('Works until :date', {
                        date: formatDate(user.linkWorksUntil),
                    })}
                </span>
            ) : (
                <StatusPill tone="danger">{__('Expired')}</StatusPill>
            )}
            {user.invitationFailed && (
                <StatusPill tone="danger">
                    {__('Invitation email failed')}
                </StatusPill>
            )}
            {user.status === 'suspended' && (
                <StatusPill tone="danger">{__('Suspended')}</StatusPill>
            )}
        </>
    );
}

/**
 * People: everyone who can sign in, by view, with their pictures; cards on
 * a phone; inviting, changing a role and deleting each in a dialog that
 * asks for the password in place.
 */
export default function UsersIndex({
    view,
    tabs,
    users,
    roles,
    reassignOptions,
    invitationDays,
    currentUserId,
}: UsersIndexPage) {
    const [inviting, setInviting] = useState(false);
    // Holds the row a dialog is for, and keeps it after the dialog closes, so
    // its words stay as it fades, even once the view's list has lost the row.
    const [managing, setManaging] = useState<Managing | null>(null);
    const [open, setOpen] = useState(false);
    const manage = (next: Managing) => {
        setManaging(next);
        setOpen(true);
    };
    const close = () => setOpen(false);
    const small = useShellWidth() === 'small';
    const invited = view === 'invited';
    const tabLabel = tabs.find((tab) => tab.key === view)?.label ?? '';
    const inviteButton = (
        <Button type="button" onClick={() => setInviting(true)}>
            {__('Invite someone')}
        </Button>
    );
    const isYou = (user: UserRow) => user.id === currentUserId;
    const lineActions = (user: UserRow) =>
        invited ? invitationActions(user) : accountActions(user, manage);
    // Your own row has no menu: each of its actions is refused for yourself.
    const moreActions = (user: UserRow) =>
        isYou(user)
            ? []
            : invited
              ? invitationMoreActions(user, manage)
              : accountMoreActions(user, manage);

    const empty = (() => {
        if (invited) {
            return (
                <EmptyState
                    kind="nothing-yet"
                    title={__('No pending invitations')}
                    description={__(
                        'People you invite get an email with a link that works for :days.',
                        { days: __n(':count day|:count days', invitationDays) },
                    )}
                    action={inviteButton}
                />
            );
        }

        if (view === 'suspended') {
            return (
                <EmptyState
                    kind="nothing-yet"
                    title={__('Nobody is suspended')}
                    description={__(
                        'Someone you suspend can’t sign in, and everything they wrote stays as it is.',
                    )}
                />
            );
        }

        return (
            <EmptyState
                kind="nothing-yet"
                title={__('No :role yet', { role: tabLabel.toLowerCase() })}
                description={__('Invite someone and give them this role.')}
                action={inviteButton}
            />
        );
    })();

    const nameColumn: Column<UserRow> = {
        id: 'name',
        owner: 'core',
        header: __('Name'),
        cell: (user) => (
            <PersonCell
                user={user}
                isYou={isYou(user)}
                actions={lineActions(user)}
            />
        ),
    };
    const roleColumn: Column<UserRow> = {
        id: 'role',
        owner: 'core',
        header: __('Role'),
        cell: (user) => roleLabel(roles, user.role),
    };
    const moreColumn: Column<UserRow> = {
        id: 'more',
        owner: 'core',
        header: <span className="sr-only">{__('More actions')}</span>,
        label: __('More actions'),
        cell: (user) => {
            const items = moreActions(user);

            return items.length > 0 ? (
                <MoreActions user={user} items={items} />
            ) : null;
        },
    };

    const accountColumns: Column<UserRow>[] = [
        nameColumn,
        roleColumn,
        {
            id: 'status',
            owner: 'core',
            header: __('Status'),
            cell: (user) => (
                <div className="flex flex-wrap gap-1">
                    <AccountPills user={user} />
                </div>
            ),
        },
        {
            id: 'last-sign-in',
            owner: 'core',
            header: __('Last sign-in'),
            cell: (user) => (
                <span className="text-ink-2">
                    {user.lastSignInAt
                        ? formatDateTime(user.lastSignInAt)
                        : __('Never')}
                </span>
            ),
        },
        moreColumn,
    ];

    const invitationColumns: Column<UserRow>[] = [
        nameColumn,
        roleColumn,
        {
            id: 'invited',
            owner: 'core',
            header: __('Invited'),
            cell: (user) => (
                <span className="grid">
                    <span>
                        {user.invitedAt ? formatDate(user.invitedAt) : '—'}
                    </span>
                    {user.invitedBy && (
                        <span className="text-caption text-ink-2">
                            {__('by :name', { name: user.invitedBy })}
                        </span>
                    )}
                </span>
            ),
        },
        {
            id: 'link',
            owner: 'core',
            header: __('Link'),
            cell: (user) => (
                <div className="flex flex-wrap items-center gap-1">
                    <InvitationPills user={user} />
                </div>
            ),
        },
        moreColumn,
    ];

    const caption = invited ? __('Pending invitations') : __('People');
    let list: ReactNode;

    if (small) {
        list = (
            <PeopleCards
                label={caption}
                users={users}
                currentUserId={currentUserId}
                roleLabel={(user) => roleLabel(roles, user.role)}
                pills={(user) =>
                    invited ? (
                        <InvitationPills user={user} />
                    ) : (
                        <AccountPills user={user} />
                    )
                }
                lineActions={lineActions}
                moreActions={moreActions}
                empty={empty}
            />
        );
    } else {
        list = (
            <DataTable
                columns={invited ? invitationColumns : accountColumns}
                rows={users}
                rowKey={(user) => user.id}
                caption={caption}
                empty={empty}
            />
        );
    }

    return (
        <>
            <Head title={__('People')} />

            <ListPage
                title={__('People')}
                description={__(
                    'Everyone who can sign in to this site. People you invite choose their own password.',
                )}
                actions={
                    <Button
                        type="button"
                        data-test="people-invite"
                        onClick={() => setInviting(true)}
                    >
                        {__('Invite someone')}
                    </Button>
                }
            >
                <InPageNav
                    label={__('Views')}
                    items={tabs.map((tab) => ({
                        label: tab.label,
                        count: tab.count,
                        current: tab.key === view,
                        href:
                            tab.key === 'all'
                                ? usersIndex().url
                                : usersIndex({ query: { view: tab.key } }).url,
                    }))}
                />

                {list}

                {/* Each dialog draws in its own layer, so where it sits here changes nothing on the page. */}
                <InviteDialog
                    open={inviting}
                    onOpenChange={setInviting}
                    roles={roles}
                    days={invitationDays}
                />
                <RoleDialog
                    open={open && managing?.task === 'role'}
                    person={
                        managing?.task === 'role' ? managing.user : undefined
                    }
                    roles={roles}
                    onClose={close}
                />
                <DeleteDialog
                    open={open && managing?.task === 'delete'}
                    person={
                        managing?.task === 'delete' ? managing.user : undefined
                    }
                    reassignOptions={reassignOptions}
                    onClose={close}
                />
            </ListPage>
        </>
    );
}
