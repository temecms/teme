import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

/**
 * What a list shows with no rows. Nothing yet offers the primary action;
 * nothing matches offers a control that clears the filters, not text saying
 * to.
 */
export function EmptyState({
    kind,
    title,
    description,
    action,
    clearHref,
}: {
    kind: 'nothing-yet' | 'nothing-matches';
    title: string;
    description?: string;
    action?: ReactNode;
    clearHref?: string;
}) {
    return (
        <div className="grid justify-items-center gap-1.5 px-4 py-10 text-center text-[13px]">
            <p className="font-semibold">{title}</p>
            {description && (
                <p className="text-muted-foreground">{description}</p>
            )}
            {kind === 'nothing-matches' && clearHref !== undefined ? (
                <Button variant="outline" className="mt-2" asChild>
                    <Link href={clearHref}>Clear filters</Link>
                </Button>
            ) : (
                action && <div className="mt-2">{action}</div>
            )}
        </div>
    );
}
