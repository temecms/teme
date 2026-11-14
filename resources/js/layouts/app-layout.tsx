import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { CommandPalette } from '@/components/palette/command-palette';
import { setAppearanceSaving, syncAppearance } from '@/hooks/use-appearance';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { preferences } = usePage().props;
    const mode = preferences['appearance.mode'];

    // Signed in: the light or dark switch saves the person's preference.
    useEffect(() => {
        setAppearanceSaving(true);

        return () => setAppearanceSaving(false);
    }, []);

    // The person's saved appearance wins over this browser's hint, and a
    // change saved on the preferences screen shows without a reload.
    useEffect(() => {
        if (mode !== undefined) {
            syncAppearance(mode);
        }
    }, [mode]);

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
            {/* Once, for every admin screen: ⌘K or Ctrl K, or the header's search. */}
            <CommandPalette />
        </AppLayoutTemplate>
    );
}
