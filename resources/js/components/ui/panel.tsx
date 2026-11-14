import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * A region of a screen, lifted off the page on the soft shadow with no
 * border (docs/architecture/design-system.md, section 4.28).
 */
function Panel({ className, ...props }: ComponentProps<'section'>) {
    return (
        <section
            data-slot="panel"
            className={cn(
                'rounded-lg bg-surface text-ink shadow-raised',
                className,
            )}
            {...props}
        />
    );
}

function PanelHeader({ className, ...props }: ComponentProps<'header'>) {
    return (
        <header
            data-slot="panel-header"
            className={cn(
                'flex min-h-13 items-center gap-2 pt-3 pr-4 pl-5 text-body font-semibold',
                className,
            )}
            {...props}
        />
    );
}

function PanelBody({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            data-slot="panel-body"
            className={cn('grid gap-3 px-5 pt-2 pb-5', className)}
            {...props}
        />
    );
}

export { Panel, PanelBody, PanelHeader };
