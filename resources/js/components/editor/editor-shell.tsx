import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent, ReactNode } from 'react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { savePreferences } from '@/lib/preferences';

const RAIL_DEFAULT = 360;
const RAIL_MIN = 320;
const RAIL_MAX = 480;

/**
 * The editor's two-column frame (admin-design.md, "Layout"): a writing column
 * and a rail. The shell is a workspace the window's height: its header stays
 * in view, and the writing column and the rail each scroll on their own. The
 * rail is 360px, dragged between 320 and 480, and collapsible; below 1024px it
 * becomes a sheet over the content. The column is minmax(0, 1fr), because one wide card would
 * otherwise stretch the track and widen every field (the "Two traps").
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
    const isMobile = useIsMobile();
    // The person's own width (editor.rail_width), so it follows them between
    // machines.
    const preferred = usePage().props.preferences['editor.rail_width'];
    const [width, setWidth] = useState(
        typeof preferred === 'number' ? preferred : RAIL_DEFAULT,
    );
    const dragging = useRef(false);
    const saveTimer = useRef<number | undefined>(undefined);

    // Saved 500 ms after the last change, in the background.
    const storeWidth = useCallback((next: number) => {
        window.clearTimeout(saveTimer.current);
        saveTimer.current = window.setTimeout(
            () => void savePreferences({ 'editor.rail_width': next }),
            500,
        );
    }, []);

    useEffect(() => () => window.clearTimeout(saveTimer.current), []);

    const startDrag = useCallback(
        (event: ReactPointerEvent<HTMLDivElement>) => {
            event.preventDefault();
            dragging.current = true;
            const startX = event.clientX;
            const startWidth = width;

            const move = (moved: PointerEvent) => {
                if (!dragging.current) {
                    return;
                }

                const next = Math.min(
                    RAIL_MAX,
                    Math.max(RAIL_MIN, startWidth + (startX - moved.clientX)),
                );
                setWidth(next);
            };

            const stop = (ended: PointerEvent) => {
                dragging.current = false;
                storeWidth(
                    Math.min(
                        RAIL_MAX,
                        Math.max(
                            RAIL_MIN,
                            startWidth + (startX - ended.clientX),
                        ),
                    ),
                );
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', stop);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', stop);
        },
        [width, storeWidth],
    );

    // The editor is a workspace the window's height (app.css).
    useEffect(() => {
        document.documentElement.classList.add('editor-workspace');

        return () =>
            document.documentElement.classList.remove('editor-workspace');
    }, []);

    const showRail = railOpen && !focus && !isMobile;

    return (
        <div data-slot="editor-shell" className="flex min-h-0 flex-1 flex-col">
            {header}
            <div
                className="grid min-h-0 flex-1 grid-rows-[minmax(0,1fr)]"
                style={{
                    gridTemplateColumns: `minmax(0, 1fr) ${showRail ? width : 0}px`,
                }}
            >
                <div
                    data-slot="editor-writing"
                    className="min-w-0 overflow-y-auto"
                >
                    {children}
                </div>
                {showRail && (
                    <aside
                        aria-label={railTitle}
                        className="border-border bg-card relative sticky top-0 h-full min-h-0 overflow-y-auto overscroll-contain border-l"
                    >
                        <div
                            role="separator"
                            aria-orientation="vertical"
                            aria-label="Resize the panel"
                            aria-valuemin={RAIL_MIN}
                            aria-valuemax={RAIL_MAX}
                            aria-valuenow={width}
                            tabIndex={0}
                            onPointerDown={startDrag}
                            onKeyDown={(event) => {
                                const step =
                                    event.key === 'ArrowLeft'
                                        ? 16
                                        : event.key === 'ArrowRight'
                                          ? -16
                                          : 0;

                                if (step !== 0) {
                                    event.preventDefault();
                                    const next = Math.min(
                                        RAIL_MAX,
                                        Math.max(RAIL_MIN, width + step),
                                    );
                                    setWidth(next);
                                    storeWidth(next);
                                }
                            }}
                            className="hover:bg-border focus-visible:bg-ring absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize outline-none"
                        />
                        {rail}
                    </aside>
                )}
            </div>
            {isMobile && !focus && (
                <Sheet open={railOpen} onOpenChange={onRailOpenChange}>
                    <SheetContent
                        className="w-full overflow-y-auto sm:max-w-[480px]"
                        onCloseAutoFocus={onSheetCloseAutoFocus}
                    >
                        <SheetHeader>
                            <SheetTitle>{railTitle}</SheetTitle>
                            <SheetDescription className="sr-only">
                                {`The ${railNoun}’s settings`}
                            </SheetDescription>
                        </SheetHeader>
                        {rail}
                    </SheetContent>
                </Sheet>
            )}
        </div>
    );
}
