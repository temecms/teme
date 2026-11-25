import { setLayoutProps, usePage } from '@inertiajs/react';
import type { CSSProperties, PropsWithChildren, ReactNode } from 'react';
import { Page } from '@/components/patterns/page';
import { PageHeader } from '@/components/patterns/page-header';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit as editProfile } from '@/routes/profile';
import { AccountNav, AccountPagesButton } from './account-nav';

/** The side list beside the page from 1024px: up to 200 wide, the page taking the rest. */
const COLUMNS = {
    '--account-cols': 'minmax(0, 200px) minmax(0, 1fr)',
} as CSSProperties;

/**
 * The account page's own settings-page frame gives its header and its
 * width to this layout: the title runs across the top, and the panels fill
 * the column 16 apart.
 */
const PAGE_IN_FRAME =
    '[&_[data-slot=settings-page]]:grid [&_[data-slot=settings-page]]:max-w-none [&_[data-slot=settings-page]]:gap-4 [&_[data-slot=settings-page]>[data-slot=page-header]]:hidden [&_[data-slot=settings-page]>form]:grid [&_[data-slot=settings-page]>form]:gap-4 [&_[data-slot=form-panel]]:mt-0!';

/**
 * The frame of every account page (docs/architecture/design-system.md,
 * section 4.43): the page's title and line across the full width, then the
 * account's side list headed by you beside the page's column; under 1024px
 * one button opens the list. Crumbs are Account / the page, set once here
 * (design system 4.31); each page names only its title and line.
 */
export default function AccountLayout({
    title,
    description,
    children,
}: PropsWithChildren<{
    /** The page's h1. */
    title?: string;
    /** The line under it, saying what the page is for. */
    description?: ReactNode;
}>) {
    const url = usePage().url;

    if (title !== undefined) {
        setLayoutProps({
            breadcrumbs: [
                { title: __('Account'), href: editProfile() },
                { title, href: url },
            ],
        });
    }

    return (
        <Page>
            {title !== undefined && (
                <PageHeader title={title} description={description} />
            )}
            <div
                className="grid items-start gap-8 lg:grid-cols-(--account-cols)"
                style={COLUMNS}
            >
                <AccountNav />
                <div className={cn('grid min-w-0 gap-4', PAGE_IN_FRAME)}>
                    <AccountPagesButton />
                    {children}
                </div>
            </div>
        </Page>
    );
}
