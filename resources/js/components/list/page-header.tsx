import type { ReactNode } from 'react';

/**
 * The top of a screen: its one h1, a line saying what the screen is for, and
 * the primary action on the right.
 */
export function PageHeader({
    title,
    description,
    action,
    badge,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    /** Beside the title, outside the heading, so the heading's name stays the title. */
    badge?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-[22px] leading-tight font-semibold tracking-[-0.015em]">
                        {title}
                    </h1>
                    {badge}
                </div>
                {description && (
                    <p className="text-ink-2 max-w-[640px] text-[13px]">
                        {description}
                    </p>
                )}
            </div>
            {action && <div className="flex flex-wrap gap-2">{action}</div>}
        </header>
    );
}
