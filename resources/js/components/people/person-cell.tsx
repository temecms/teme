import { Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { RowLinks } from '@/components/list/row-links';
import type { RowAction } from '@/components/list/row-actions';
import { PersonPicture } from '@/components/ui/person-picture';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { edit as editProfile } from '@/routes/profile';
import type { UserRow } from '@/types';

/**
 * Your own row: nothing on it can change you, so the action people look for
 * is drawn locked on the row's line, beside the way to your account, with
 * the reason under them. The lock stays focusable, so a screen reader
 * reaches the reason.
 */
export function OwnRowLock() {
    return (
        <div className="grid justify-items-start gap-1">
            <div
                role="group"
                aria-label={__('Actions for you')}
                className="text-small flex flex-wrap items-center gap-x-3"
            >
                <TextButton
                    aria-disabled="true"
                    aria-describedby="own-role-reason"
                    data-test="own-row-lock"
                    className="text-ink-2 cursor-not-allowed font-normal hover:decoration-transparent"
                    onClick={(event) => event.preventDefault()}
                >
                    <Lock aria-hidden="true" className="size-4" />
                    {__('Change role…')}
                </TextButton>
                <TextButton asChild className="font-normal">
                    <Link href={editProfile()}>
                        {__('Manage yourself in Account')}
                    </Link>
                </TextButton>
            </div>
            <p id="own-role-reason" className="text-caption text-ink-3">
                {__(
                    'You can’t change your own role. Ask another administrator.',
                )}
            </p>
        </div>
    );
}

/** A person at the head of a dialog about them: their picture, name and email. */
export function PersonHead({ user }: { user: UserRow }) {
    return (
        <div className="flex min-w-0 items-center gap-3">
            <PersonPicture
                name={user.name}
                src={user.picture}
                invited={user.invitedAt !== null}
                size={40}
                nameBeside
            />
            <div className="grid min-w-0">
                <span className="text-body text-ink truncate font-semibold">
                    {user.name}
                </span>
                <span className="text-small text-ink-2 truncate">
                    {user.email}
                </span>
            </div>
        </div>
    );
}

/**
 * The Name cell of a person's row: their picture, name, "You" on your own
 * row, their email, and the row's actions under them.
 */
export function PersonCell({
    user,
    isYou,
    actions,
}: {
    user: UserRow;
    isYou: boolean;
    actions: RowAction[];
}) {
    return (
        <div className="flex items-start gap-3">
            <PersonPicture
                name={user.name}
                src={user.picture}
                invited={user.invitedAt !== null}
                size={40}
                nameBeside
            />
            <div className="grid min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2 font-semibold">
                    {user.name}
                    {isYou && (
                        <StatusPill tone="neutral" flat>
                            {__('You')}
                        </StatusPill>
                    )}
                </div>
                <div className="text-ink-2">{user.email}</div>
                {/* Each of your own row's actions is refused for yourself, so it has the lock, not links. */}
                {isYou ? (
                    <OwnRowLock />
                ) : (
                    <RowLinks
                        label={__('Actions for :name', { name: user.name })}
                        more={__('More actions for :name', { name: user.name })}
                        items={actions}
                    />
                )}
            </div>
        </div>
    );
}
