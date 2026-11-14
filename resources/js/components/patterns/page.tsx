import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The page's container (docs/architecture/design-system.md, section 5):
 * 1280 wide at most, padded 8 32 64, 16 at the sides under 768px. Every
 * pattern draws it, so no page writes its own frame.
 */
export function Page({
    children,
    width = 'full',
    className,
}: {
    children: ReactNode;
    /** `form` keeps the content to the form's 760 column, left-aligned. */
    width?: 'full' | 'form';
    className?: string;
}) {
    return (
        <div
            data-slot="page"
            className={cn(
                'mx-auto w-full max-w-(--page-max) px-8 pt-2 pb-16 max-md:px-4',
                className,
            )}
        >
            {width === 'form' ? (
                <div className="max-w-(--form-max)">{children}</div>
            ) : (
                children
            )}
        </div>
    );
}
