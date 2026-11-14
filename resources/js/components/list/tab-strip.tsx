import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export type Tab = {
    key: string;
    label: string;
    count?: number;
    href: ComponentProps<typeof Link>['href'];
};

/**
 * The views of a list, such as All, Drafts and Trash. The current one takes
 * the foreground colour and a 2px accent underline; each count sits beside
 * its label.
 */
export function TabStrip({
    tabs,
    current,
    label,
    preserveState = false,
}: {
    tabs: Tab[];
    current: string;
    label: string;
    /** Keeps the page's own state across the visit, as the Media screen does. */
    preserveState?: boolean;
}) {
    return (
        <nav
            aria-label={label}
            className="border-line flex flex-wrap gap-x-5 border-b"
        >
            {tabs.map((tab) => {
                const active = tab.key === current;

                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        preserveState={preserveState}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            '-mb-px inline-flex h-9 items-center gap-1.5 border-b-2 px-0.5 text-[13px]',
                            active
                                ? 'border-accent text-ink font-semibold'
                                : 'text-ink-2 hover:text-ink border-transparent font-medium',
                        )}
                    >
                        {tab.label}
                        {tab.count !== undefined && (
                            <span className="text-ink-2 font-mono text-[11px] font-normal">
                                {tab.count.toLocaleString()}
                            </span>
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
