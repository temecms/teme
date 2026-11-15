import { Link, router } from '@inertiajs/react';
import {
    LogOut,
    ShieldCheck,
    SlidersHorizontal,
    UserRound,
} from 'lucide-react';
import {
    ResponsiveMenuGroup,
    ResponsiveMenuItem,
    ResponsiveMenuLabel,
    ResponsiveMenuSeparator,
} from '@/components/patterns/responsive-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { __ } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit as editPreferences } from '@/routes/preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    const items = [
        { title: __('Profile'), href: editProfile(), icon: UserRound },
        { title: __('Security'), href: editSecurity(), icon: ShieldCheck },
        {
            title: __('Preferences'),
            href: editPreferences(),
            icon: SlidersHorizontal,
        },
    ];

    return (
        <>
            <ResponsiveMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </ResponsiveMenuLabel>
            <ResponsiveMenuSeparator />
            <ResponsiveMenuGroup>
                {items.map((item) => (
                    <ResponsiveMenuItem key={item.title} asChild>
                        <Link
                            className="block w-full cursor-pointer"
                            href={item.href}
                            prefetch
                            onClick={cleanup}
                        >
                            <item.icon className="mr-2" />
                            {item.title}
                        </Link>
                    </ResponsiveMenuItem>
                ))}
            </ResponsiveMenuGroup>
            <ResponsiveMenuSeparator />
            <ResponsiveMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    {__('Sign out')}
                </Link>
            </ResponsiveMenuItem>
        </>
    );
}
