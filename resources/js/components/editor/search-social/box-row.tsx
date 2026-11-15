import type { ReactNode } from 'react';

/**
 * One row of Search and social (I-3415, docs/architecture/design-system.md,
 * 4.39): the label and its guidance in a 300px column on the left, the field
 * at full width beside it; on a narrow box they stack, the label above.
 */
export function BoxRow({
    guide,
    children,
}: {
    /** The label, its help and its count. */
    guide: ReactNode;
    /** The field, and its proposal when there is one. */
    children: ReactNode;
}) {
    return (
        <div
            data-slot="box-row"
            className="flex flex-col gap-2 @2xl:flex-row @2xl:gap-0"
        >
            <div className="@2xl:bg-surface-2 grid content-start gap-1 @2xl:w-75 @2xl:shrink-0 @2xl:rounded-l-md @2xl:p-3">
                {guide}
            </div>
            <div className="grid min-w-0 flex-1 content-start gap-2 @2xl:py-3 @2xl:pl-4">
                {children}
            </div>
        </div>
    );
}
