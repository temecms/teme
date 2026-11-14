import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

export function UserInfo({
    user,
    showEmail = false,
}: {
    user: User;
    showEmail?: boolean;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar className="h-8 w-8 overflow-hidden rounded-full">
                <AvatarImage src={user.picture ?? undefined} alt={user.name} />
                <AvatarFallback className="bg-surface-3 text-ink rounded-lg">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div className="text-small grid flex-1 text-left leading-tight">
                <span className="truncate font-medium">{user.name}</span>
                {showEmail && (
                    <span className="text-caption text-ink-2 truncate">
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}
