import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { StatusBanner } from '@/components/status-banner';
import type { AppLayoutProps } from '@/types';

/** The screens that draw their own top bar in place of the admin's: the editor (design system, 4.39). */
const OWN_BAR = ['content/edit'];

/**
 * The column beside the sidebar: the top bar with the page's crumbs, the
 * banner under it, then the `main` landmark. The header is outside `main` so the skip link lands on the
 * content, and the banner is a region of its own. The editor draws its own
 * bar instead of both: the site status's count in it carries the banner.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const ownBar = OWN_BAR.includes(usePage().component);

    return (
        <AppShell>
            <AppSidebar />
            <div
                data-slot="app-column"
                className="bg-page flex min-h-svh min-w-0 flex-1 flex-col"
            >
                {!ownBar && (
                    <>
                        <AppSidebarHeader breadcrumbs={breadcrumbs} />
                        <StatusBanner />
                    </>
                )}
                <AppContent className="min-w-0 overflow-x-clip">
                    {children}
                </AppContent>
            </div>
        </AppShell>
    );
}
