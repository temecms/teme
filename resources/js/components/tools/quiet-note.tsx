import { Info } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * A quiet note on a Tools screen: information that blocks nothing, on the
 * second surface with an information mark, where a coloured notice would be
 * for something to act on first.
 */
export function QuietNote({
    children,
    testId,
}: {
    children: ReactNode;
    testId?: string;
}) {
    return (
        <div
            data-test={testId}
            className="bg-surface-2 text-small text-ink-2 flex items-start gap-3 rounded-lg px-5 py-4"
        >
            <span className="flex h-5 shrink-0 items-center">
                <Info aria-hidden="true" className="text-ink-3 size-4" />
            </span>
            <div className="grid min-w-0 gap-2">{children}</div>
        </div>
    );
}
