import type { ReactNode } from 'react';
import { RowLinks } from '@/components/list/row-links';
import type { RowAction } from '@/components/list/row-actions';
import { PersonPicture } from '@/components/ui/person-picture';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { UserRow } from '@/types';
import { OwnRowLock } from './person-cell';
import { MoreActions } from './row-actions';

/**
 * People on a phone: a card for each person, with their picture, name,
 * role and state, then the same actions as the table's row.
 */
export function PeopleCards({
    label,
    users,
    currentUserId,
    roleLabel,
    pills,
    lineActions,
    moreActions,
    empty,
}: {
    label: string;
    users: UserRow[];
    currentUserId: number;
    roleLabel: (user: UserRow) => string;
    pills: (user: UserRow) => ReactNode;
    lineActions: (user: UserRow) => RowAction[];
    moreActions: (user: UserRow) => RowAction[];
    empty: ReactNode;
}) {
    if (users.length === 0) {
        return <>{empty}</>;
    }

    return (
        <ul aria-label={label} className="grid gap-3">
            {users.map((user) => {
                const isYou = user.id === currentUserId;
                const more = moreActions(user);

                return (
                    <li
                        key={user.id}
                        data-test="person-card"
                        className="group/row bg-surface shadow-raised grid gap-3 rounded-lg p-4"
                    >
                        <div className="flex items-start gap-3">
                            <PersonPicture
                                name={user.name}
                                src={user.picture}
                                invited={user.invitedAt !== null}
                                size={40}
                                nameBeside
                            />
                            <div className="grid min-w-0 flex-1 gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-body text-ink font-semibold break-words">
                                        {user.name}
                                    </span>
                                    {isYou && (
                                        <StatusPill tone="neutral" flat>
                                            {__('You')}
                                        </StatusPill>
                                    )}
                                </div>
                                <span className="text-small text-ink-2 break-all">
                                    {user.email}
                                </span>
                                <span className="text-small text-ink-2">
                                    {roleLabel(user)}
                                </span>
                                <div className="flex flex-wrap gap-1">
                                    {pills(user)}
                                </div>
                            </div>
                        </div>
                        {isYou ? (
                            <OwnRowLock />
                        ) : (
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <RowLinks
                                    label={__('Actions for :name', {
                                        name: user.name,
                                    })}
                                    more={__('More actions for :name', {
                                        name: user.name,
                                    })}
                                    items={lineActions(user)}
                                />
                                {more.length > 0 && (
                                    <MoreActions user={user} items={more} />
                                )}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
