import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

type Props = {
    /** What the button says: "Save {page} settings". */
    label: string;
    /** A line beside it, only where the page has another Save. */
    note?: string;
    processing: boolean;
    /** The button's data-test hook. */
    testId?: string;
    /** Anything else beside the button, such as a running count. */
    children?: ReactNode;
};

/**
 * The Save bar at the foot of a settings form: sticky at the foot of the
 * scroll area, ending where its form ends (I-3128). The form's last child.
 */
export function SettingsSaveBar({
    label,
    note,
    processing,
    testId,
    children,
}: Props) {
    return (
        <div
            data-test="save-bar"
            className="bg-card sticky bottom-0 z-10 flex flex-wrap items-center gap-x-3 gap-y-2 border px-5 py-3 shadow-sm max-sm:px-4"
        >
            <Button type="submit" disabled={processing} data-test={testId}>
                {label}
            </Button>
            {note !== undefined && note !== '' && (
                <p
                    data-test="save-bar-note"
                    className="text-muted-foreground min-w-0 text-xs text-pretty"
                >
                    {note}
                </p>
            )}
            {children}
        </div>
    );
}
