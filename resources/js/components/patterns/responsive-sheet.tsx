import type { ComponentProps } from 'react';
import { SheetContent } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

/**
 * A side sheet that fits a phone (docs/architecture/design-system.md,
 * section 4.20): used in place of `SheetContent` for a sheet from the start
 * or the end. Below 640px it takes the whole screen.
 */
export function ResponsiveSheetContent({
    className,
    ...props
}: Omit<ComponentProps<typeof SheetContent>, 'side'> & {
    side?: 'left' | 'right';
}) {
    return (
        <SheetContent
            className={cn(
                'max-sm:max-w-none max-sm:rounded-s-none max-sm:rounded-e-none',
                className,
            )}
            {...props}
        />
    );
}
