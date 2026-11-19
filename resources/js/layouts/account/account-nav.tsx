import { Link, usePage } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { Fragment } from 'react';
import {
    ResponsiveMenu,
    ResponsiveMenuContent,
    ResponsiveMenuItem,
    ResponsiveMenuSeparator,
    ResponsiveMenuTrigger,
} from '@/components/patterns/responsive-menu';
import { Button } from '@/components/ui/button';
import { SoonTag } from '@/components/ui/soon-tag';
import { PersonPicture } from '@/components/ui/person-picture';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { __ } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editPreferences } from '@/routes/preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { edit as editTwoFactor } from '@/routes/two-factor';

type AccountPage = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    /** Planned and not built: drawn with its Soon tag, opening nothing. */
    soon?: false;
};

type SoonPage = { title: string; soon: true };

const LINK =
    'text-body text-ink-2 hover:bg-surface-3 hover:text-ink aria-[current=page]:bg-accent-soft aria-[current=page]:text-on-accent-soft focus-visible:outline-ring flex min-h-(--ctl-h) items-center rounded-md px-3 font-medium focus-visible:outline-2 focus-visible:-outline-offset-2 max-sm:min-h-11';

/**
 * The account's pages in two groups, a rule between them: what you see and
 * hear, then how you sign in. Passkeys and Sessions are planned, so they are
 * listed with their Soon tag until the server has them
 * (docs/proposals/people-and-account.md).
 */
function useAccountPages(): (AccountPage | SoonPage)[][] {
    return [
        [
            { title: __('Profile'), href: editProfile() },
            { title: __('Preferences'), href: editPreferences() },
            { title: __('Notifications'), href: editNotifications() },
        ],
        [
            { title: __('Security'), href: editSecurity() },
            { title: __('Two-factor sign-in'), href: editTwoFactor() },
            { title: __('Passkeys'), soon: true },
            { title: __('Sessions'), soon: true },
        ],
    ];
}

/**
 * The account's side list from 1024px: the signed-in person at its head,
 * then the account's pages, the one you are on marked.
 */
export function AccountNav() {
    const { auth } = usePage().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const groups = useAccountPages();

    return (
        <nav
            aria-label={__('Account')}
            className="grid content-start gap-0.5 max-lg:hidden lg:sticky lg:top-4"
        >
            <div className="flex items-center gap-3 px-3 pb-3">
                <PersonPicture
                    name={auth.user.name}
                    src={auth.user.picture}
                    size={40}
                    nameBeside
                />
                <div className="grid min-w-0">
                    <span className="text-small text-ink truncate font-semibold">
                        {auth.user.name}
                    </span>
                    {auth.user.role !== null && (
                        <span className="text-small text-ink-3 truncate">
                            {auth.user.role}
                        </span>
                    )}
                </div>
            </div>
            {groups.map((group, index) => (
                <Fragment key={index}>
                    {index > 0 && <hr className="border-line mx-3 my-2" />}
                    {group.map((item) =>
                        item.soon ? (
                            <span
                                key={item.title}
                                aria-disabled="true"
                                data-test="account-soon"
                                className={cn(LINK, 'cursor-default')}
                            >
                                {item.title}
                                <SoonTag />
                            </span>
                        ) : (
                            <Link
                                key={toUrl(item.href)}
                                href={item.href}
                                aria-current={
                                    isCurrentOrParentUrl(item.href)
                                        ? 'page'
                                        : undefined
                                }
                                className={LINK}
                            >
                                {item.title}
                            </Link>
                        ),
                    )}
                </Fragment>
            ))}
        </nav>
    );
}

/**
 * The account's pages under 1024px: one button across the column, naming
 * the page you are on, that opens the same list.
 */
export function AccountPagesButton() {
    const { auth } = usePage().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const groups = useAccountPages();
    const current =
        groups
            .flat()
            .find((item) => !item.soon && isCurrentOrParentUrl(item.href))
            ?.title ?? __('Account');

    return (
        <div className="lg:hidden">
            <ResponsiveMenu>
                <ResponsiveMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="neutral"
                        data-test="account-pages"
                        aria-label={__('Account pages: :page', {
                            page: current,
                        })}
                        className="w-full justify-start max-sm:min-h-11"
                    >
                        <PersonPicture
                            name={auth.user.name}
                            src={auth.user.picture}
                            size={24}
                            nameBeside
                        />
                        <span className="min-w-0 flex-1 truncate text-start">
                            {current}
                        </span>
                        <ChevronDown aria-hidden="true" />
                    </Button>
                </ResponsiveMenuTrigger>
                <ResponsiveMenuContent
                    title={__('Account pages')}
                    align="start"
                    className="w-(--radix-dropdown-menu-trigger-width)"
                >
                    {groups.map((group, index) => (
                        <Fragment key={index}>
                            {index > 0 && <ResponsiveMenuSeparator />}
                            {group.map((item) =>
                                item.soon ? (
                                    <ResponsiveMenuItem
                                        key={item.title}
                                        disabled
                                    >
                                        {item.title}
                                        <SoonTag className="ms-auto" />
                                    </ResponsiveMenuItem>
                                ) : (
                                    <ResponsiveMenuItem
                                        key={toUrl(item.href)}
                                        asChild
                                    >
                                        <Link
                                            href={item.href}
                                            aria-current={
                                                isCurrentOrParentUrl(item.href)
                                                    ? 'page'
                                                    : undefined
                                            }
                                        >
                                            {item.title}
                                        </Link>
                                    </ResponsiveMenuItem>
                                ),
                            )}
                        </Fragment>
                    ))}
                </ResponsiveMenuContent>
            </ResponsiveMenu>
        </div>
    );
}
