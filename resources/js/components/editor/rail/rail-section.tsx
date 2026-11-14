import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';

/**
 * A rail section that collapses from its 48px header. Every section but
 * Publish is one (I-230). Keeping the open state per person waits for I-194,
 * which preferences (1.5f) make possible and nothing has asked for yet.
 */
export function RailSection({
    title,
    triggerId,
    children,
}: {
    title: string;
    /** An id for the header's button, so a link can put focus on it. */
    triggerId?: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(true);

    return (
        <Collapsible open={open} onOpenChange={setOpen} asChild>
            <Panel>
                <PanelHeader>
                    <CollapsibleTrigger
                        id={triggerId}
                        className="focus-visible:ring-ring flex w-full items-center justify-between rounded-sm outline-none focus-visible:ring-2"
                    >
                        <h2>{title}</h2>
                        <ChevronDown
                            aria-hidden
                            className="text-ink-2 size-4 transition-transform data-[open=false]:-rotate-90"
                            data-open={open}
                        />
                    </CollapsibleTrigger>
                </PanelHeader>
                <CollapsibleContent>
                    <PanelBody>{children}</PanelBody>
                </CollapsibleContent>
            </Panel>
        </Collapsible>
    );
}
