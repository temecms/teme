import { Link, router } from '@inertiajs/react';
import {
    Bell,
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
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { __ } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editPreferences } from '@/routes/preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { User } from '@/types';

type Props = {
    user: User;
};

/**
 * The account menu body (design system 4.33): "Name · Role", then Your
 * account, Preferences, Notifications, Security, and Sign out.
 */
export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    const items = [
        {
            title: __('Your account'),
            href: editProfile(),
            icon: UserRound,
        },
        {
            title: __('Preferences'),
            href: editPreferences(),
            icon: SlidersHorizontal,
        },
        {
            title: __('Notifications'),
            href: editNotifications(),
            icon: Bell,
        },
        {
            title: __('Security'),
            href: editSecurity(),
            icon: ShieldCheck,
        },
    ];

    const who =
        user.role !== null && user.role !== ''
            ? `${user.name} · ${user.role}`
            : user.name;

    return (
        <>
            <ResponsiveMenuLabel className="p-0 font-normal">
                <div className="text-small grid gap-0.5 px-3 py-2 text-left leading-tight">
                    <span className="text-body truncate font-medium">
                        {who}
                    </span>
                    <span className="text-caption text-ink-2 truncate">
                        {user.email}
                    </span>
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
