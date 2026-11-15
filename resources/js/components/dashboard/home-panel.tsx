import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { DashboardPanelRows } from '@/components/dashboard/dashboard-panel';
import { panelSpanClass } from '@/components/dashboard/panel-grid';
import { QuickDraft } from '@/components/dashboard/quick-draft';
import { ErrorState } from '@/components/ui/error-state';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { PluginMark } from '@/components/ui/plugin-mark';
import { Skeleton } from '@/components/ui/skeleton';
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
};

/**
 * One Home panel frame: its name, count, plugin mark, and body by kind —
 * rows, quick draft, failed, or empty while customizing.
 */
export function HomePanel({ entry, panel, size, customizing }: HomePanelProps) {
    const labelId = `panel-${entry.key}`;
    const [retrying, setRetrying] = useState(false);
    const element = useRef<HTMLElement | null>(null);

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
            ref={element}
            data-panel={entry.key}
            aria-labelledby={labelId}
            className={cn(
                panelSpanClass(size),
                customizing && 'ring-accent-soft-2 ring-2',
            )}
        >
            <PanelHeader>
                <h3 id={labelId} className="text-body font-semibold">
                    {__(entry.label)}
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
                        label={__(entry.label)}
                    />
                )}
            </PanelBody>
        </Panel>
    );
}
