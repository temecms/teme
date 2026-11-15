import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, EyeOff, GripVertical } from 'lucide-react';
import { useState } from 'react';
import { DashboardPanelRows } from '@/components/dashboard/dashboard-panel';
import type { Section } from '@/components/dashboard/home-layout';
import { panelSpanClass } from '@/components/dashboard/panel-grid';
import { QuickDraft } from '@/components/dashboard/quick-draft';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { PluginMark } from '@/components/ui/plugin-mark';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    DashboardCatalogueEntry,
    DashboardPanelData,
    PanelSize,
} from '@/types';

type HomePanelProps = {
    entry: DashboardCatalogueEntry;
    panel: DashboardPanelData | null;
    size: PanelSize;
    customizing: boolean;
    section: Section;
    canMoveEarlier: boolean;
    canMoveLater: boolean;
    onMoveEarlier: () => void;
    onMoveLater: () => void;
    onResize: (size: PanelSize) => void;
    onHide: () => void;
    gripRef?: (node: HTMLElement | null) => void;
    panelRef?: (node: HTMLElement | null) => void;
    dropEdge?: 'left' | 'right' | 'top' | 'bottom' | null;
    dragging?: boolean;
};

const SIZE_LABELS: Record<PanelSize, string> = {
    small: 'Small',
    medium: 'Medium',
    large: 'Large',
    full: 'Full width',
};

/**
 * One Home panel frame: its name, count, plugin mark, Customize controls, and
 * body by kind — rows, quick draft, failed, or empty while customizing.
 */
export function HomePanel({
    entry,
    panel,
    size,
    customizing,
    canMoveEarlier,
    canMoveLater,
    onMoveEarlier,
    onMoveLater,
    onResize,
    onHide,
    gripRef,
    panelRef,
    dropEdge = null,
    dragging = false,
}: HomePanelProps) {
    const labelId = `panel-${entry.key}`;
    const name = __(entry.label);
    const [retrying, setRetrying] = useState(false);

    const failed = panel?.failed === true;
    const rows = panel?.rows ?? [];

    const retry = () => {
        setRetrying(true);
        router.reload({
            only: ['panels'],
            onFinish: () => setRetrying(false),
        });
    };

    return (
        <Panel
            ref={panelRef}
            data-panel={entry.key}
            data-drop-edge={dropEdge ?? undefined}
            data-dragging={dragging || undefined}
            aria-labelledby={labelId}
            className={cn(
                panelSpanClass(size),
                'relative',
                customizing && 'ring-accent-soft-2 ring-2',
                dragging && 'opacity-50',
                dropEdge === 'left' &&
                    'before:bg-accent before:absolute before:inset-y-2 before:-left-0 before:w-0.5 before:-translate-x-1/2',
                dropEdge === 'right' &&
                    'after:bg-accent after:absolute after:inset-y-2 after:right-0 after:w-0.5 after:translate-x-1/2',
                dropEdge === 'top' &&
                    'before:bg-accent before:absolute before:inset-x-2 before:top-0 before:h-0.5 before:-translate-y-1/2',
                dropEdge === 'bottom' &&
                    'after:bg-accent after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:translate-y-1/2',
            )}
        >
            <PanelHeader>
                {customizing && (
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span
                                    ref={gripRef}
                                    data-drag-handle
                                    className="text-ink-3 inline-flex size-6 cursor-grab items-center justify-center active:cursor-grabbing"
                                    aria-hidden="true"
                                >
                                    <GripVertical className="size-4" />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>
                                {__('Drag to move :name', { name })}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                )}
                <h3 id={labelId} className="text-body font-semibold">
                    {name}
                </h3>
                {entry.kind === 'rows' && rows.length > 0 && !failed && (
                    <span className="text-small text-ink-3 font-normal tabular-nums">
                        {rows.length}
                    </span>
                )}
                {entry.ownerName !== null && !customizing && (
                    <PluginMark name={entry.ownerName} iconOnly />
                )}
                <span className="flex-1" />
                {customizing && (
                    <div
                        role="group"
                        aria-label={__('Arrange :name', { name })}
                        className="flex flex-wrap items-center gap-1"
                    >
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        className="h-11 w-11"
                                        aria-label={__('Move :name earlier', {
                                            name,
                                        })}
                                        aria-disabled={!canMoveEarlier}
                                        data-control="move-earlier"
                                        onClick={() => {
                                            if (canMoveEarlier) {
                                                onMoveEarlier();
                                            }
                                        }}
                                    >
                                        <ChevronLeft aria-hidden="true" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {__('Move :name earlier', { name })}
                                </TooltipContent>
                            </Tooltip>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        className="h-11 w-11"
                                        aria-label={__('Move :name later', {
                                            name,
                                        })}
                                        aria-disabled={!canMoveLater}
                                        data-control="move-later"
                                        onClick={() => {
                                            if (canMoveLater) {
                                                onMoveLater();
                                            }
                                        }}
                                    >
                                        <ChevronRight aria-hidden="true" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {__('Move :name later', { name })}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <Segmented
                            aria-label={__('Size of :name', { name })}
                            value={size}
                            onValueChange={(next) =>
                                onResize(next as PanelSize)
                            }
                            className="max-sm:hidden"
                            data-control="size"
                        >
                            {(
                                [
                                    ['small', 'S'],
                                    ['medium', 'M'],
                                    ['large', 'L'],
                                    ['full', 'Full'],
                                ] as const
                            ).map(([value, short]) => (
                                <SegmentedItem
                                    key={value}
                                    value={value}
                                    aria-label={__(SIZE_LABELS[value])}
                                >
                                    {short}
                                </SegmentedItem>
                            ))}
                        </Segmented>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            className="h-11 w-11"
                            aria-label={__('Hide')}
                            data-control="hide"
                            onClick={onHide}
                        >
                            <EyeOff aria-hidden="true" />
                            <span className="sr-only">{__('Hide')}</span>
                        </Button>
                    </div>
                )}
            </PanelHeader>
            <PanelBody>
                {entry.kind === 'quick-draft' ? (
                    <QuickDraft bare />
                ) : retrying ? (
                    <div className="grid gap-2">
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-4 w-1/2" />
                        <Skeleton className="h-4 w-2/3" />
                    </div>
                ) : failed ? (
                    <ErrorState
                        message={__(
                            "This panel couldn't load. The rest of Home is unaffected.",
                        )}
                        onRetry={retry}
                        retryLabel={__('Try again')}
                        className="px-0 pt-0 pb-0"
                    />
                ) : (
                    <DashboardPanelRows
                        rows={rows}
                        seeAll={panel?.seeAll ?? null}
                        label={name}
                    />
                )}
            </PanelBody>
        </Panel>
    );
}
