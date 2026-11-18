import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type {
    CSSProperties,
    PointerEvent as ReactPointerEvent,
    ReactNode,
} from 'react';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { __ } from '@/lib/i18n';
import { savePreferences } from '@/lib/preferences';

// The rail's width as built and as the writing area's tests measure it; the design draws 344 (docs/plans/editor.md, Records).
const RAIL_DEFAULT = 360;
const RAIL_MIN = 320;
const RAIL_MAX = 480;

/** A width held between the rail's least and most. */
function within(width: number): number {
    return Math.min(RAIL_MAX, Math.max(RAIL_MIN, width));
}

/** The rail as the editor shows it. */
export type EditorPageRail = {
    content: ReactNode;
    /** The rail's name: "Post settings". */
    title: string;
    /** What the item is called, lower case: post, page, region. */
    noun: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Runs as the small screen's sheet closes, to move focus somewhere other than where it came from. */
    onSheetCloseAutoFocus?: (event: Event) => void;
};

/**
 * The editor's page (docs/architecture/design-system.md, section 5): the
 * window's height, with the editor's own top bar instead of a page header,
 * a writing column that scrolls on its own over the page's ground, and the
 * rail beside it. The rail is 360px, dragged between 320 and 480 and kept
 * as the person's editor.rail_width; below 1024px it is a sheet over the
 * writing. The column is minmax(0, 1fr), so one wide card never widens the
 * fields. The column's sides are 40 pixels (16 on a phone), room for the
 * block handles' gutter beside the writing. `around` holds what the bar, the writing and the rail share.
 */
export function EditorPage({
    header,
    rail,
    focus = false,
    around = (page) => page,
    children,
}: {
    header: ReactNode;
    /** The rail; none on the compare page. */
    rail?: EditorPageRail;
    focus?: boolean;
    around?: (page: ReactNode) => ReactNode;
    children: ReactNode;
}) {
    const isMobile = useIsMobile();
    // The person's own width (editor.rail_width), so it follows them between machines.
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
                if (dragging.current) {
                    setWidth(within(startWidth + (startX - moved.clientX)));
                }
            };

            const stop = (ended: PointerEvent) => {
                dragging.current = false;
                storeWidth(within(startWidth + (startX - ended.clientX)));
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

    const showRail = rail !== undefined && rail.open && !focus && !isMobile;
    const columns = {
        '--editor-cols': `minmax(0, 1fr) ${showRail ? width : 0}px`,
    } as CSSProperties;

    return around(
        <div
            data-slot="editor-shell"
            className="bg-page flex min-h-0 flex-1 flex-col"
        >
            {header}
            <div
                className="grid min-h-0 flex-1 grid-cols-(--editor-cols) grid-rows-1"
                style={columns}
            >
                <ScrollArea data-slot="editor-writing" className="bg-page">
                    <div className="grid w-full grid-cols-1 gap-6 px-4 pt-6 pb-30 sm:px-10">
                        {children}
                    </div>
                </ScrollArea>
                {showRail && (
                    <aside
                        aria-label={rail.title}
                        className="border-line bg-chrome relative flex h-full min-h-0 flex-col border-l"
                    >
                        <div
                            role="separator"
                            aria-orientation="vertical"
                            aria-label={__('Resize the panel')}
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
                                    const next = within(width + step);
                                    setWidth(next);
                                    storeWidth(next);
                                }
                            }}
                            className="hover:bg-line focus-visible:bg-ring focus-visible:outline-ring absolute inset-y-0 -left-1 z-(--z-sticky) w-2 cursor-col-resize outline-none focus-visible:outline-2"
                        />
                        <ScrollArea className="h-full">
                            {rail.content}
                        </ScrollArea>
                    </aside>
                )}
            </div>
            {rail !== undefined && isMobile && !focus && (
                <Sheet open={rail.open} onOpenChange={rail.onOpenChange}>
                    <SheetContent
                        className="w-full sm:max-w-(--sheet-w)"
                        onCloseAutoFocus={rail.onSheetCloseAutoFocus}
                    >
                        <SheetHeader>
                            <SheetTitle>{rail.title}</SheetTitle>
                            <SheetDescription className="sr-only">
                                {__('The :noun’s settings', {
                                    noun: rail.noun,
                                })}
                            </SheetDescription>
                        </SheetHeader>
                        <ScrollArea className="flex-1">
                            {rail.content}
                        </ScrollArea>
                    </SheetContent>
                </Sheet>
            )}
        </div>,
    );
}
