import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { PageHeader } from '@/components/list/page-header';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editPreferences } from '@/routes/preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const panels: NavItem[] = [
    { title: 'Profile', href: editProfile() },
    { title: 'Preferences', href: editPreferences() },
    { title: 'Notifications', href: editNotifications() },
];

/** Security always comes last, after a rule: a panel a plugin adds goes before the rule. */
const security: NavItem = { title: 'Security', href: editSecurity() };

/**
 * The frame of the account screens: the page's one h1, a line saying whose
 * account and how it saves, a sub-navigation at the left, and the panel.
 */
export default function AccountLayout({ children }: PropsWithChildren) {
    const { auth } = usePage().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();

    const link = (item: NavItem) => (
        <Link
            key={toUrl(item.href)}
            href={item.href}
            aria-current={isCurrentOrParentUrl(item.href) ? 'page' : undefined}
            className="hover:bg-muted aria-[current=page]:bg-muted flex min-h-8 items-center rounded-md px-2 py-1 text-[13px] aria-[current=page]:font-medium"
        >
            {item.title}
        </Link>
    );

    return (
        <div className="space-y-6 p-6">
            <PageHeader
                title="Account"
                description={`Signed in as ${auth.user.name}. Each panel saves on its own.`}
            />

            <div className="grid gap-6 lg:grid-cols-[200px_minmax(0,1fr)]">
                <nav
                    aria-label="Account"
                    className="grid content-start gap-0.5 lg:sticky lg:top-6"
                >
                    {panels.map(link)}
                    <hr className="border-border my-2" />
                    {link(security)}
                </nav>

                <div className="min-w-0 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
