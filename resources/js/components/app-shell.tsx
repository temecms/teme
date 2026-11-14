import { usePage } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';

type Props = {
    children: ReactNode;
};

function SkipLink() {
    return (
        <a
            href="#main-content"
            className="bg-page text-ink focus:ring-ring sr-only z-50 rounded-md px-4 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:ring-2"
        >
            Skip to content
        </a>
    );
}

export function AppShell({ children }: Props) {
    const isOpen = usePage().props.sidebarOpen;

    return (
        <SidebarProvider
            defaultOpen={isOpen}
            // 264px open, 68px folded (docs/architecture/design-system.md, section 2.9).
            style={
                {
                    '--sidebar-width': 'var(--side-w)',
                    '--sidebar-width-icon': 'var(--side-w-rail)',
                } as CSSProperties
            }
        >
            <SkipLink />
            {children}
        </SidebarProvider>
    );
}
