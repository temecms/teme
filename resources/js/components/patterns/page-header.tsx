import type { ReactNode } from 'react';
import { PluginMark } from '@/components/ui/plugin-mark';

/**
 * The top of a page (docs/architecture/design-system.md, section 4.30): its
 * one h1, a line saying what the page is for, and the actions at the end,
 * the primary first. Under 640px the actions go under the title, the primary
 * across the width.
 */
export function PageHeader({
    title,
    description,
    actions,
    plugin,
    badge,
    as: Title = 'h1',
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    /** The plugin whose page this is: its mark follows the title. */
    plugin?: string;
    /** Beside the title, outside the heading, so the heading's name stays the title. */
    badge?: ReactNode;
    /** `div` where the page already has its h1, as in the catalogue. */
    as?: 'h1' | 'div';
}) {
    return (
        <header
            data-slot="page-header"
            className="mt-2 mb-6 flex flex-wrap items-end gap-4"
        >
            <div className="grid min-w-0 flex-1 basis-80 gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Title
                        data-test="pattern-title"
                        className="text-title text-ink font-semibold tracking-[-0.01em]"
                    >
                        {title}
                    </Title>
                    {plugin !== undefined && <PluginMark name={plugin} />}
                    {badge}
                </div>
                {description && (
                    <p className="text-body text-ink-2">{description}</p>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap gap-2 max-sm:w-full max-sm:[&>*:first-child]:flex-1">
                    {actions}
                </div>
            )}
        </header>
    );
}
