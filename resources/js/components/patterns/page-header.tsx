import type { ReactNode } from 'react';
import { PluginMark } from '@/components/ui/plugin-mark';
import { cn } from '@/lib/utils';

/**
 * The top of a page (docs/architecture/design-system.md, section 4.30): its
 * one h1 (visually hidden when the breadcrumb already names the page), a
 * quiet description under the crumb, and the actions at the end, the primary
 * first. Under 640px the actions go under the title, the primary across the
 * width. Pass showTitle when the page's name is not the crumb (Home's
 * greeting, a detail page).
 */
export function PageHeader({
    title,
    description,
    actions,
    plugin,
    badge,
    showTitle = false,
    as: Title = 'h1',
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    /** The plugin whose page this is: its mark follows the title. */
    plugin?: string;
    /** Beside the title, outside the heading, so the heading's name stays the title. */
    badge?: ReactNode;
    /** Keep the large title when it is not the breadcrumb's last crumb. */
    showTitle?: boolean;
    /** `div` where the page already has its h1, as in the catalogue. */
    as?: 'h1' | 'div';
}) {
    const marked = plugin !== undefined || badge !== undefined;
    const bare = !showTitle && !description && !actions && !marked;

    return (
        <header
            data-slot="page-header"
            className={
                bare
                    ? 'contents'
                    : cn(
                          'mb-6 flex flex-wrap items-end gap-4',
                          showTitle ? 'mt-2' : 'mt-0',
                      )
            }
        >
            <div className="grid min-w-0 flex-1 basis-80 gap-1">
                <div
                    className={
                        showTitle || marked
                            ? 'flex flex-wrap items-center gap-2'
                            : 'contents'
                    }
                >
                    <Title
                        data-test="pattern-title"
                        className={
                            showTitle
                                ? 'text-title text-ink font-semibold tracking-[-0.01em]'
                                : 'sr-only'
                        }
                    >
                        {title}
                    </Title>
                    {plugin !== undefined && <PluginMark name={plugin} />}
                    {badge}
                </div>
                {description && (
                    <p
                        className={
                            showTitle
                                ? 'text-body text-ink-2'
                                : 'text-small text-ink-2'
                        }
                    >
                        {description}
                    </p>
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
