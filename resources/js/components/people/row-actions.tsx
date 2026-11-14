import UserController from '@/actions/App/Http/Admin/Users/UserController';
import { ConfirmAction } from '@/components/confirm-action';
import { RowActionsMenu } from '@/components/list/row-actions';
import type { RowAction } from '@/components/list/row-actions';
import { __ } from '@/lib/i18n';
import type { UserRow } from '@/types';

/**
 * Which person a dialog is open for, and what for. The row itself is kept,
 * not its ID, so the dialog keeps its words while it closes, even after the
 * view's list no longer holds the person.
 */
export type Managing = { user: UserRow; task: 'role' | 'delete' };

/** A person's line: their role and their access. */
export function accountActions(
    user: UserRow,
    manage: (managing: Managing) => void,
): RowAction[] {
    return [
        {
            id: 'role',
            owner: 'core',
            label: __('Change role…'),
            onSelect: () => manage({ user, task: 'role' }),
        },
        user.status === 'suspended'
            ? {
                  id: 'reactivate',
                  owner: 'core',
                  label: __('Reactivate'),
                  form: UserController.reactivate.form({ user: user.id }),
              }
            : {
                  id: 'suspend',
                  owner: 'core',
                  label: __('Suspend'),
                  form: UserController.suspend.form({ user: user.id }),
              },
    ];
}

/** A person's More menu: taking their picture off, and deleting them, last. */
export function accountMoreActions(
    user: UserRow,
    manage: (managing: Managing) => void,
): RowAction[] {
    return [
        ...(user.picture === null
            ? []
            : [
                  {
                      id: 'remove-picture',
                      owner: 'core',
                      label: __('Remove picture…'),
                      form: UserController.removePicture.form({
                          user: user.id,
                      }),
                      wrap: (button) => (
                          <ConfirmAction
                              title={__('Remove the picture of :name?', {
                                  name: user.name,
                              })}
                              description={__(
                                  'Their initials show instead. The image stays in Media.',
                              )}
                              confirmLabel={__('Remove picture')}
                          >
                              {button}
                          </ConfirmAction>
                      ),
                  } satisfies RowAction,
              ]),
        {
            id: 'delete',
            owner: 'core',
            label: __('Delete…'),
            destructive: true,
            onSelect: () => manage({ user, task: 'delete' }),
        },
    ];
}

/** An invitation's line: a new link, or withdrawing it. */
export function invitationActions(user: UserRow): RowAction[] {
    return [
        {
            id: 'resend',
            owner: 'core',
            label: __('Send again'),
            form: UserController.resendInvitation.form({ user: user.id }),
        },
        {
            id: 'revoke',
            owner: 'core',
            label: __('Revoke invitation'),
            destructive: true,
            form: UserController.revokeInvitation.form({ user: user.id }),
            wrap: (button) => (
                <ConfirmAction
                    title={__('Revoke the invitation to :email?', {
                        email: user.email,
                    })}
                    description={__(
                        'The link in their email stops working, and the account waiting for them is removed. You can invite them again.',
                    )}
                    confirmLabel={__('Revoke invitation')}
                >
                    {button}
                </ConfirmAction>
            ),
        },
    ];
}

/** An invitation's More menu: the role it gives. */
export function invitationMoreActions(
    user: UserRow,
    manage: (managing: Managing) => void,
): RowAction[] {
    return [
        {
            id: 'role',
            owner: 'core',
            label: __('Change role…'),
            onSelect: () => manage({ user, task: 'role' }),
        },
    ];
}

/** A row's More menu, in its own last column, named for the person. */
export function MoreActions({
    user,
    items,
}: {
    user: UserRow;
    items: RowAction[];
}) {
    return (
        <RowActionsMenu
            items={items}
            label={__('More actions for :name', { name: user.name })}
        />
    );
}
