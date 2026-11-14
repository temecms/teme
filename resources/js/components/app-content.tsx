import * as React from 'react';
import { cn } from '@/lib/utils';

/** The `main` landmark: the skip link's target, and where a screen's own markup starts. */
export function AppContent({
    className,
    ...props
}: React.ComponentProps<'main'>) {
    return (
        <main
            id="main-content"
            tabIndex={-1}
            className={cn(
                'relative flex flex-1 flex-col outline-none',
                className,
            )}
            {...props}
        />
    );
}
