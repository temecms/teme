import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * A region of a screen, divided from its neighbour by a hairline. Not a card:
 * no radius, no border box, nothing lifting it off the page. Five of the seven content systems in
 * study M separate panels this way; the two that use cards are a framework
 * and a dashboard template.
 */
function Panel({ className, ...props }: ComponentProps<'section'>) {
    return (
        <section
            data-slot="panel"
            className={cn(
                'border-border bg-card border-t first:border-t-0',
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
                'flex min-h-12 items-center gap-2 px-4 text-[13px] font-semibold',
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
            className={cn('grid gap-3 px-4 pb-4', className)}
            {...props}
        />
    );
}

export { Panel, PanelBody, PanelHeader };
