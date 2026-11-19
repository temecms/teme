import { Head } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { SignInBrand } from '@/components/brand';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type AuthPageProps = {
    /** The h1, and the tab's title unless `tabTitle` is given. */
    title: string;
    lead?: ReactNode;
    tabTitle?: string;
    /** A tile over the title, such as the mail icon on "Check your email". */
    icon?: ReactNode;
    /** 'teme' on the setup page, where the site has no name yet. */
    brand?: 'site' | 'teme';
    /** Above the form: a sent link, a changed password, a paused sign-in. */
    notices?: ReactNode;
    /** Under the form: the other ways on, each an AuthLink, centered. */
    links?: ReactNode;
    children: ReactNode;
};

/**
 * The one card every page before the admin draws
 * (docs/architecture/design-system.md, section 4.44): the site's mark, the
 * title and its lead, the notices, the form, the other ways on, and under
 * the card a quiet credit. At the top of a phone's screen, centered on a
 * wider one.
 */
export function AuthPage({
    title,
    lead,
    tabTitle,
    brand = 'site',
    icon,
    notices,
    links,
    children,
}: AuthPageProps) {
    return (
        <>
            <Head title={tabTitle ?? title} />
            <main className="bg-page flex min-h-svh flex-col items-center gap-6 px-4 pt-5 pb-8 sm:justify-center sm:py-8">
                <div
                    data-slot="auth-card"
                    className="bg-surface shadow-float grid w-full max-w-105 gap-5 rounded-xl p-5 sm:p-8"
                >
                    <SignInBrand teme={brand === 'teme'} />
                    <div className="grid justify-items-start gap-1">
                        {icon && <div className="mb-3">{icon}</div>}
                        <h1 className="text-title text-ink font-semibold">
                            {title}
                        </h1>
                        {lead && <p className="text-body text-ink-2">{lead}</p>}
                    </div>
                    {notices}
                    {children}
                    {links && (
                        <div className="grid justify-items-center gap-1">
                            {links}
                        </div>
                    )}
                </div>
                <p
                    data-test="powered-by"
                    className="text-caption text-ink-3 flex items-center gap-2"
                >
                    <img
                        src="/teme/icon.svg"
                        alt=""
                        aria-hidden="true"
                        width={16}
                        height={16}
                    />
                    {__('Powered by Teme')}
                </p>
            </main>
        </>
    );
}

/**
 * One of the card's other ways on: small, underlined words, 32px tall to
 * tap. `asChild` for a link.
 */
export function AuthLink({
    className,
    ...props
}: ComponentProps<typeof TextButton>) {
    return (
        <TextButton
            {...props}
            className={cn(
                'text-small min-h-(--ctl-h) font-normal decoration-current',
                className,
            )}
        />
    );
}
