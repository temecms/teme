import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Home's panel grid: 12 columns, gap 16. A panel spans 4, 6, 8 or 12 by size
 * from 1024; from 768 to 1023 small and medium span 6, large and full 12;
 * under 768 every panel spans 12. Dense packing is not used: the visual order
 * must match the saved order Tab, announcements and Move follow (WCAG 1.3.2,
 * 2.4.3).
 */
export function PanelGrid({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            data-test="home-grid"
            className={cn('grid grid-cols-12 gap-4', className)}
        >
            {children}
        </div>
    );
}

/** Tailwind span classes for a panel size across the three breakpoints. */
export function panelSpanClass(
    size: 'small' | 'medium' | 'large' | 'full',
): string {
    switch (size) {
        case 'small':
            return 'col-span-12 sm:col-span-6 lg:col-span-4';
        case 'medium':
            return 'col-span-12 sm:col-span-6 lg:col-span-6';
        case 'large':
            return 'col-span-12 lg:col-span-8';
        case 'full':
            return 'col-span-12';
    }
}
