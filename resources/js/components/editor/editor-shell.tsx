import type { ReactNode } from 'react';
import { EditorPage } from '@/components/patterns/editor-page';

/**
 * The editor's two-column frame, now drawn by the EditorPage pattern; kept
 * under its old name until the editor's page renders the pattern itself.
 */
export function EditorShell({
    header,
    rail,
    railTitle,
    railNoun,
    railOpen,
    onRailOpenChange,
    onSheetCloseAutoFocus,
    focus,
    children,
}: {
    header: ReactNode;
    rail: ReactNode;
    railTitle: string;
    /** What the item is called, lower case: post, page, region. */
    railNoun: string;
    railOpen: boolean;
    onRailOpenChange: (open: boolean) => void;
    /** Runs as the phone's sheet closes, to move focus somewhere other than where it came from. */
    onSheetCloseAutoFocus?: (event: Event) => void;
    focus: boolean;
    children: ReactNode;
}) {
    return (
        <EditorPage
            header={header}
            focus={focus}
            rail={{
                content: rail,
                title: railTitle,
                noun: railNoun,
                open: railOpen,
                onOpenChange: onRailOpenChange,
                onSheetCloseAutoFocus,
            }}
        >
            {children}
        </EditorPage>
    );
}
