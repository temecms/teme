import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/adapter/element-adapter';
import { combine } from '@atlaskit/pragmatic-drag-and-drop/utils/combine';
import { attachClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge/attach-closest-edge';
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge/extract-closest-edge';
import type { Edge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/types';
import { useEffect, useRef, type RefObject } from 'react';
import type { Section } from '@/components/dashboard/home-layout';

/** What a dragged panel carries: a marker, so nothing else dropped here is taken for a panel. */
type PanelDrag = { teme: 'home-panel'; key: string; section: Section };

const isPanelDrag = (
    data: Record<string | symbol, unknown>,
): data is PanelDrag => data.teme === 'home-panel';

/**
 * Makes one panel draggable by its grip and a drop target for panels of its
 * own section. `edge` reports which side a panel would land on, for the drop line.
 */
export function usePanelDrag(
    element: RefObject<HTMLElement | null>,
    grip: RefObject<HTMLElement | null>,
    panel: { key: string; section: Section },
    enabled: boolean,
    setEdge: (edge: Edge | null) => void,
    setDragging: (dragging: boolean) => void,
): void {
    useEffect(() => {
        const node = element.current;
        const handle = grip.current;
        const key = panel.key;
        const section = panel.section;

        if (!enabled || node === null || handle === null) {
            return;
        }

        // Left and right in a grid that flows in reading order; top and bottom in one column.
        const edges: Edge[] = window.matchMedia('(min-width: 40rem)').matches
            ? ['left', 'right']
            : ['top', 'bottom'];

        return combine(
            draggable({
                element: node,
                dragHandle: handle,
                getInitialData: (): PanelDrag => ({
                    teme: 'home-panel',
                    key: node.dataset.panel ?? key,
                    section:
                        (node.dataset.section as Section | undefined) ??
                        section,
                }),
                onDragStart: () => setDragging(true),
                onDrop: () => setDragging(false),
            }),
            dropTargetForElements({
                element: node,
                canDrop: ({ source }) => {
                    const selfKey = node.dataset.panel ?? key;
                    const selfSection =
                        (node.dataset.section as Section | undefined) ??
                        section;

                    return (
                        isPanelDrag(source.data) &&
                        source.data.section === selfSection &&
                        source.data.key !== selfKey
                    );
                },
                getData: ({ input }) =>
                    attachClosestEdge(
                        { key: node.dataset.panel ?? key },
                        { element: node, input, allowedEdges: edges },
                    ),
                onDrag: ({ self }) => setEdge(extractClosestEdge(self.data)),
                onDragLeave: () => setEdge(null),
                onDrop: () => setEdge(null),
            }),
        );
    }, [
        element,
        grip,
        panel.key,
        panel.section,
        enabled,
        setEdge,
        setDragging,
    ]);
}

/**
 * One listener for the whole page: on a drop onto a panel, the dragged panel
 * goes before or after it, through moveTo(), and the move is announced.
 */
export function usePanelDrops(
    onMove: (key: string, targetKey: string, after: boolean) => void,
    enabled: boolean,
): void {
    const onMoveRef = useRef(onMove);
    onMoveRef.current = onMove;

    useEffect(() => {
        if (!enabled) {
            return;
        }

        return monitorForElements({
            canMonitor: ({ source }) => isPanelDrag(source.data),
            onDrop: ({ source, location }) => {
                const target = location.current.dropTargets[0];

                if (target === undefined || !isPanelDrag(source.data)) {
                    return;
                }

                const edge = extractClosestEdge(target.data);
                onMoveRef.current(
                    source.data.key,
                    String(target.data.key),
                    edge === 'right' || edge === 'bottom',
                );
            },
        });
    }, [enabled]);
}
