import { Form, Head, Link } from '@inertiajs/react';
import type { FormComponentRef } from '@inertiajs/core';
import { Lock } from 'lucide-react';
import { useRef, useState } from 'react';
import UserController from '@/actions/App/Http/Admin/Users/UserController';
import { ConfirmAction } from '@/components/confirm-action';
import { post } from '@/components/editor/editor-http';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { PageHeader } from '@/components/list/page-header';
import { RowLinks } from '@/components/list/row-links';
import type { RowAction } from '@/components/list/row-actions';
import { TabStrip } from '@/components/list/tab-strip';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import PasswordInput from '@/components/password-input';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { edit as editProfile } from '@/routes/profile';
import { store as confirmPassword } from '@/routes/password/confirm';
import { index as usersIndex } from '@/routes/users';
import type {
    ReassignOption,
    RoleOption,
    UserRow,
    UsersIndexPage,
} from '@/types';

const DESCRIPTION =
    'Everyone who can sign in to this site. People you invite choose their own password.';
const OWN_ROLE_REASON =
    'You cannot change your own role. Ask another administrator.';
const ROLE_HINT_PERSON =
    'They’re signed out everywhere, so their next sign-in uses the new role.';
const ROLE_HINT_INVITATION =
    'They’ll have this role once they accept the invitation.';
const REVOKE_DESCRIPTION =
    'The link in their email stops working, and the account waiting for them is removed. You can invite them again.';
const SENDING_MAY_ASK = 'Sending may ask for your password first.';
const CHANGING_MAY_ASK = 'Changing a role may ask for your password first.';
const DELETING_MAY_ASK = 'Deleting may ask for your password first.';

/**
 * Which person a dialog is open for, and what for. The row itself is kept,
 * not its ID, so the dialog keeps its words while it closes, even after the
 * view's list no longer holds the person.
 */
type Managing = { user: UserRow; task: 'role' | 'delete' };

/** A role's label, as the role choices name it; "—" for none. */
function roleLabel(roles: RoleOption[], role: string | null): string {
    return roles.find((option) => option.value === role)?.label ?? '—';
}

/** A date as the reader's browser writes one. */
function shortDate(at: string): string {
    return new Date(at).toLocaleDateString();
}

/** "7 days", or "1 day". */
function daysWords(days: number): string {
    return days === 1 ? '1 day' : `${days} days`;
}

/**
 * Your own row: nothing on it can change you, so the action people look for
 * is drawn locked, with the reason beside it and the way to your account.
 * It stays focusable, so a screen reader reaches the reason.
 */
function OwnRowLock() {
    return (
        <div className="grid justify-items-end gap-1">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-disabled="true"
                aria-describedby="own-role-reason"
                data-test="own-row-lock"
                className="text-muted-foreground -my-1 cursor-not-allowed"
                onClick={(event) => event.preventDefault()}
            >
                <Lock aria-hidden="true" />
                Change role…
            </Button>
            <p
                id="own-role-reason"
                className="text-muted-foreground w-56 text-right text-xs"
            >
                {OWN_ROLE_REASON}
            </p>
            <Link
                href={editProfile()}
                className="inline-flex min-h-6 items-center text-xs underline-offset-2 hover:underline"
            >
                Manage yourself in Account
            </Link>
        </div>
    );
}

/** A person's menu: their role, their access, and deleting them. */
function accountActions(
    user: UserRow,
    manage: (managing: Managing) => void,
): RowAction[] {
    return [
        {
            id: 'role',
            owner: 'core',
            label: 'Change role…',
            onSelect: () => manage({ user, task: 'role' }),
        },
        user.status === 'suspended'
            ? {
                  id: 'reactivate',
                  owner: 'core',
                  label: 'Reactivate',
                  form: UserController.reactivate.form({ user: user.id }),
              }
            : {
                  id: 'suspend',
                  owner: 'core',
                  label: 'Suspend',
                  form: UserController.suspend.form({ user: user.id }),
              },
        {
            id: 'delete',
            owner: 'core',
            label: 'Delete…',
            destructive: true,
            onSelect: () => manage({ user, task: 'delete' }),
        },
    ];
}

/** An invitation's menu: a new link, another role, or withdrawing it. */
function invitationActions(
    user: UserRow,
    manage: (managing: Managing) => void,
): RowAction[] {
    return [
        {
            id: 'resend',
            owner: 'core',
            label: 'Send again',
            form: UserController.resendInvitation.form({ user: user.id }),
        },
        {
            id: 'role',
            owner: 'core',
            label: 'Change role…',
            onSelect: () => manage({ user, task: 'role' }),
        },
        {
            id: 'revoke',
            owner: 'core',
            label: 'Revoke invitation',
            destructive: true,
            form: UserController.revokeInvitation.form({ user: user.id }),
            wrap: (button) => (
                <ConfirmAction
                    title={`Revoke the invitation to ${user.email}?`}
                    description={REVOKE_DESCRIPTION}
                    confirmLabel="Revoke invitation"
                >
                    {button}
                </ConfirmAction>
            ),
        },
    ];
}

function InviteDialog({
    open,
    onOpenChange,
    roles,
    days,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roles: RoleOption[];
    days: number;
}) {
    const form = useRef<FormComponentRef | null>(null);
    const [askPassword, setAskPassword] = useState(false);
    const [password, setPassword] = useState('');
    const [passwordError, setPasswordError] = useState('');
    const [confirming, setConfirming] = useState(false);

    // The password is asked for here, so the invitation is sent once it is
    // given and what was typed stays; a page of its own would lose both.
    const confirmThenSend = async () => {
        setConfirming(true);
        setPasswordError('');

        try {
            const reply = await post(confirmPassword.url(), { password });

            if (reply.status === 422) {
                const errors = reply.body.errors as
                    | Record<string, string[]>
                    | undefined;
                setPasswordError(
                    errors?.password?.[0] ??
                        (reply.body.message as string | undefined) ??
                        '',
                );

                return;
            }

            setAskPassword(false);
            setPassword('');
            form.current?.submit();
        } finally {
            setConfirming(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setAskPassword(false);
                    setPassword('');
                    setPasswordError('');
                }

                onOpenChange(next);
            }}
        >
            <DialogContent data-test="invite-dialog">
                <DialogHeader>
                    <DialogTitle>Invite someone</DialogTitle>
                    <DialogDescription>
                        {`They get an email with a link that works for ${daysWords(days)}, where they choose their own password. You can send it again or revoke it under Invited.`}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...UserController.store.form()}
                    ref={form}
                    headers={{ 'X-Teme-Ask-In-Place': '1' }}
                    onHttpException={(response) => {
                        if (response.status === 423) {
                            setAskPassword(true);

                            return false;
                        }
                    }}
                    resetOnSuccess
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="invite-name">Name</Label>
                                <Input
                                    id="invite-name"
                                    aria-describedby="invite-name-error"
                                    name="name"
                                    required
                                    autoComplete="off"
                                />
                                <InputError
                                    id="invite-name-error"
                                    message={errors.name}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="invite-email">
                                    Email address
                                </Label>
                                <Input
                                    id="invite-email"
                                    aria-describedby="invite-email-error"
                                    name="email"
                                    type="email"
                                    required
                                    autoComplete="off"
                                />
                                <InputError
                                    id="invite-email-error"
                                    message={errors.email}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="invite-role">Role</Label>
                                {/* The least access unless chosen otherwise; the roles are listed most powerful first, as everywhere. */}
                                <SelectField
                                    id="invite-role"
                                    aria-describedby="invite-role-error"
                                    name="role"
                                    defaultValue={
                                        roles[roles.length - 1]?.value
                                    }
                                    options={roles}
                                />
                                <InputError
                                    id="invite-role-error"
                                    message={errors.role}
                                />
                            </div>
                            <p className="text-muted-foreground text-xs">
                                {SENDING_MAY_ASK}
                            </p>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Send invitation
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
                {askPassword && (
                    <div className="border-border space-y-3 rounded-md border p-4">
                        <p className="text-sm">
                            Type your password to carry on. What you typed stays
                            here.
                        </p>
                        <div className="grid gap-2">
                            <Label htmlFor="invite-password">Password</Label>
                            <PasswordInput
                                id="invite-password"
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                autoComplete="current-password"
                                aria-describedby="invite-password-error"
                            />
                            <InputError
                                id="invite-password-error"
                                message={passwordError}
                            />
                        </div>
                        <Button
                            type="button"
                            disabled={confirming}
                            onClick={() => void confirmThenSend()}
                            data-test="invite-password-confirm"
                        >
                            Confirm password
                        </Button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function RoleDialog({
    open,
    person,
    roles,
    onClose,
}: {
    open: boolean;
    person: UserRow | undefined;
    roles: RoleOption[];
    onClose: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent data-test="role-dialog">
                {person && (
                    <>
                        <DialogHeader>
                            <DialogTitle>{`Change the role of ${person.name}`}</DialogTitle>
                            <DialogDescription>
                                {person.email}
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            key={person.id}
                            {...UserController.updateRole.form({
                                user: person.id,
                            })}
                            onSuccess={onClose}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="change-role">
                                            Role
                                        </Label>
                                        <SelectField
                                            id="change-role"
                                            aria-describedby="change-role-hint change-role-error"
                                            name="role"
                                            defaultValue={
                                                person.role ?? undefined
                                            }
                                            options={roles}
                                        />
                                        <p
                                            id="change-role-hint"
                                            className="text-muted-foreground text-xs"
                                        >
                                            {person.invitedAt === null
                                                ? ROLE_HINT_PERSON
                                                : ROLE_HINT_INVITATION}
                                        </p>
                                        <InputError
                                            id="change-role-error"
                                            message={errors.role ?? errors.user}
                                        />
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        {CHANGING_MAY_ASK}
                                    </p>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="outline"
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Change role
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({
    open,
    person,
    reassignOptions,
    onClose,
}: {
    open: boolean;
    person: UserRow | undefined;
    reassignOptions: ReassignOption[];
    onClose: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent data-test="delete-dialog">
                {person && (
                    <>
                        <DialogHeader>
                            <DialogTitle>{`Delete ${person.name}`}</DialogTitle>
                            <DialogDescription>
                                {person.email}
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            key={person.id}
                            {...UserController.destroy.form({
                                user: person.id,
                            })}
                            onSuccess={onClose}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`reassign-${person.id}`}
                                        >
                                            {__('Give their content to')}
                                        </Label>
                                        {/* Everyone with an account, from the server, whatever the view lists. */}
                                        <SelectField
                                            id={`reassign-${person.id}`}
                                            aria-describedby={`reassign-${person.id}-hint reassign-${person.id}-error`}
                                            name="reassign_to"
                                            defaultValue=""
                                            emptyLabel="No one — they wrote nothing"
                                            options={reassignOptions.filter(
                                                (option) =>
                                                    option.value !== person.id,
                                            )}
                                        />
                                        <p
                                            id={`reassign-${person.id}-hint`}
                                            className="text-muted-foreground text-xs"
                                        >
                                            {__(
                                                'Their name stays on what they wrote.',
                                            )}
                                        </p>
                                        <InputError
                                            id={`reassign-${person.id}-error`}
                                            message={
                                                errors.reassign_to ??
                                                errors.user
                                            }
                                        />
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        {DELETING_MAY_ASK}
                                    </p>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="outline"
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            {`Delete ${person.name}`}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

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
    const tabLabel = tabs.find((tab) => tab.key === view)?.label ?? '';
    const inviteButton = (
        <Button type="button" onClick={() => setInviting(true)}>
            Invite someone
        </Button>
    );

    const accountColumns: Column<UserRow>[] = [
        {
            id: 'name',
            owner: 'core',
            header: 'Name',
            cell: (user) => (
                <>
                    <div className="flex items-center gap-2 font-semibold">
                        {user.name}
                        {user.id === currentUserId && (
                            <Badge variant="outline">You</Badge>
                        )}
                    </div>
                    <div className="text-muted-foreground">{user.email}</div>
                    {/* Each of your own row's actions is refused for yourself, so it has the lock, not links. */}
                    {user.id === currentUserId ? (
                        <OwnRowLock />
                    ) : (
                        <RowLinks
                            label={`Actions for ${user.name}`}
                            more={`More actions for ${user.name}`}
                            items={accountActions(user, manage)}
                        />
                    )}
                </>
            ),
        },
        {
            id: 'role',
            owner: 'core',
            header: 'Role',
            cell: (user) => roleLabel(roles, user.role),
        },
        {
            id: 'status',
            owner: 'core',
            header: 'Status',
            cell: (user) => (
                <div className="flex flex-wrap gap-1">
                    {user.status === 'suspended' ? (
                        <Badge variant="destructive">Suspended</Badge>
                    ) : (
                        <Badge variant="outline">Active</Badge>
                    )}
                    {user.twoFactor && (
                        <Badge variant="outline">Two-factor</Badge>
                    )}
                </div>
            ),
        },
        {
            id: 'last-sign-in',
            owner: 'core',
            header: 'Last sign-in',
            cell: (user) => (
                <span className="text-muted-foreground">
                    {user.lastSignInAt
                        ? new Date(user.lastSignInAt).toLocaleString()
                        : 'Never'}
                </span>
            ),
        },
    ];

    const invitationColumns: Column<UserRow>[] = [
        {
            id: 'name',
            owner: 'core',
            header: 'Name',
            cell: (user) => (
                <>
                    <div className="font-semibold">{user.name}</div>
                    <div className="text-muted-foreground">{user.email}</div>
                    <RowLinks
                        label={`Actions for ${user.name}`}
                        more={`More actions for ${user.name}`}
                        items={invitationActions(user, manage)}
                    />
                </>
            ),
        },
        {
            id: 'role',
            owner: 'core',
            header: 'Role',
            cell: (user) => roleLabel(roles, user.role),
        },
        {
            id: 'invited',
            owner: 'core',
            header: 'Invited',
            cell: (user) => (
                <span className="grid">
                    <span>
                        {user.invitedAt ? shortDate(user.invitedAt) : '—'}
                    </span>
                    {user.invitedBy && (
                        <span className="text-muted-foreground text-xs">
                            {`by ${user.invitedBy}`}
                        </span>
                    )}
                </span>
            ),
        },
        {
            id: 'link',
            owner: 'core',
            header: 'Link',
            cell: (user) => (
                <div className="flex flex-wrap items-center gap-1">
                    {user.linkWorksUntil ? (
                        <span className="text-muted-foreground">
                            {`Works until ${shortDate(user.linkWorksUntil)}`}
                        </span>
                    ) : (
                        <Badge variant="destructive">Expired</Badge>
                    )}
                    {user.invitationFailed && (
                        <Badge variant="destructive">
                            Invitation email failed
                        </Badge>
                    )}
                    {user.status === 'suspended' && (
                        <Badge variant="destructive">Suspended</Badge>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="People" />

            <div className="space-y-4 p-6">
                <PageHeader
                    title="People"
                    description={DESCRIPTION}
                    action={
                        <Button
                            type="button"
                            data-test="people-invite"
                            onClick={() => setInviting(true)}
                        >
                            Invite someone
                        </Button>
                    }
                />

                <TabStrip
                    label="Views"
                    current={view}
                    tabs={tabs.map((tab) => ({
                        ...tab,
                        href:
                            tab.key === 'all'
                                ? usersIndex()
                                : usersIndex({ query: { view: tab.key } }),
                    }))}
                />

                {view === 'invited' ? (
                    <DataTable
                        columns={invitationColumns}
                        rows={users}
                        rowKey={(user) => user.id}
                        caption="Pending invitations"
                        empty={
                            <EmptyState
                                kind="nothing-yet"
                                title="No pending invitations"
                                action={inviteButton}
                            />
                        }
                    />
                ) : (
                    <DataTable
                        columns={accountColumns}
                        rows={users}
                        rowKey={(user) => user.id}
                        caption="People"
                        empty={
                            <EmptyState
                                kind="nothing-yet"
                                title={
                                    view === 'suspended'
                                        ? 'Nobody is suspended'
                                        : `No ${tabLabel.toLowerCase()} yet`
                                }
                                action={
                                    view === 'suspended'
                                        ? undefined
                                        : inviteButton
                                }
                            />
                        }
                    />
                )}
            </div>

            <InviteDialog
                open={inviting}
                onOpenChange={setInviting}
                roles={roles}
                days={invitationDays}
            />
            <RoleDialog
                open={open && managing?.task === 'role'}
                person={managing?.task === 'role' ? managing.user : undefined}
                roles={roles}
                onClose={close}
            />
            <DeleteDialog
                open={open && managing?.task === 'delete'}
                person={managing?.task === 'delete' ? managing.user : undefined}
                reassignOptions={reassignOptions}
                onClose={close}
            />
        </>
    );
}
