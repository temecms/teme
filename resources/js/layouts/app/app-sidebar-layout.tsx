import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

/** The screens that draw their own top bar in place of the admin's: the editor (design system, 4.39). */
const OWN_BAR = ['content/edit'];

/**
 * The column beside the sidebar: the top bar with the page's crumbs, then
 * the `main` landmark. The header is outside `main` so the skip link lands
 * on the content. Nothing prints at the top of every screen (design system
 * 4.33); site problems live in the top-bar site status and on Home.
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
                {!ownBar && <AppSidebarHeader breadcrumbs={breadcrumbs} />}
                <AppContent className="min-w-0 overflow-x-clip">
                    {children}
                </AppContent>
            </div>
        </AppShell>
    );
}
