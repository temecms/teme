import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { PluginMark } from '@/components/ui/plugin-mark';
import { cn } from '@/lib/utils';

/**
 * A rail section (docs/architecture/design-system.md, 4.39), flat on the
 * rail's chrome: a 44px header button with its title, a plugin's mark for a
 * plugin's, its summary at the end ("2 · 3", "6 headings", "1 to look at")
 * and a chevron, over a body that folds away. Every section but Publish is
 * one (I-230). Keeping the open state per person waits for I-194.
 */
export function RailSection({
    title,
    summary,
    plugin,
    triggerId,
    defaultOpen = true,
    testId,
    children,
}: {
    title: string;
    /** What the section holds, in a few words, at the end of its header. */
    summary?: ReactNode;
    /** The plugin the section comes from, marked after the title. */
    plugin?: string;
    /** An id for the header's button, so a link can put focus on it. */
    triggerId?: string;
    defaultOpen?: boolean;
    testId?: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <Collapsible open={open} onOpenChange={setOpen} asChild>
            <section
                data-slot="rail-section"
                data-test={testId}
                className="px-2"
            >
                <h2 className="m-0">
                    <CollapsibleTrigger
                        id={triggerId}
                        className="text-body text-ink hover:bg-surface-3 focus-visible:outline-ring flex min-h-11 w-full items-center gap-2 rounded-md px-3 text-start font-semibold outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
                    >
                        <span className="min-w-0 truncate">{title}</span>
                        {plugin !== undefined && (
                            <PluginMark name={plugin} iconOnly />
                        )}
                        {summary !== undefined && (
                            <span
                                data-slot="rail-section-summary"
                                className="text-small text-ink-3 ms-auto shrink-0 font-normal whitespace-nowrap"
                            >
                                {summary}
                            </span>
                        )}
                        <ChevronDown
                            aria-hidden
                            data-open={open}
                            className={cn(
                                'text-ink-3 size-4 shrink-0 transition-[rotate] duration-(--dur-2) ease-(--ease) data-[open=false]:-rotate-90',
                                summary === undefined && 'ms-auto',
                            )}
                        />
                    </CollapsibleTrigger>
                </h2>
                <CollapsibleContent>
                    <div className="grid gap-3 px-3 pt-1 pb-4">{children}</div>
                </CollapsibleContent>
            </section>
        </Collapsible>
    );
}
