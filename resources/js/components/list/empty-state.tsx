import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Inbox, SearchX } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

/**
 * What a list shows with no rows (docs/architecture/design-system.md,
 * section 4.23). Nothing yet offers the primary action; nothing matches
 * offers a control that clears the filters, not text saying to.
 */
export function EmptyState({
    kind,
    title,
    description,
    action,
    clearHref,
    icon,
}: {
    kind: 'nothing-yet' | 'nothing-matches';
    title: string;
    description?: string;
    action?: ReactNode;
    clearHref?: string;
    /** The icon above the title; an empty tray, or for nothing matching a search that found none. */
    icon?: LucideIcon;
}) {
    const Icon = icon ?? (kind === 'nothing-matches' ? SearchX : Inbox);

    return (
        <div
            data-slot="empty-state"
            className="grid justify-items-center gap-3 px-6 py-12 text-center"
        >
            <Icon aria-hidden="true" className="text-ink-3 size-8" />
            <div className="grid gap-1">
                <p className="text-lead text-ink font-semibold">{title}</p>
                {description && (
                    <p className="text-body text-ink-2">{description}</p>
                )}
            </div>
            {kind === 'nothing-matches' && clearHref !== undefined ? (
                <Button variant="neutral" asChild>
                    <Link href={clearHref}>Clear filters</Link>
                </Button>
            ) : (
                action && <div>{action}</div>
            )}
        </div>
    );
}
