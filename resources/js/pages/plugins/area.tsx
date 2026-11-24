import { Deferred, Head, router, setLayoutProps } from '@inertiajs/react';
import PluginAreaController from '@/actions/App/Http/Admin/PluginAreaController';
import { PluginAreaPage } from '@/components/patterns/plugin-area-page';
import { AreaActions } from '@/components/plugins/area/area-actions';
import { AreaChart } from '@/components/plugins/area/area-chart';
import { AreaFigures } from '@/components/plugins/area/area-figures';
import { AreaTable } from '@/components/plugins/area/area-table';
import { Notice } from '@/components/ui/notice';
import { Panel } from '@/components/ui/panel';
import { Skeleton } from '@/components/ui/skeleton';
import type {
    AreaBlock,
    AreaChartData,
    AreaFiguresData,
    AreaRowsData,
    AreaTableAsk,
    PluginAreaPageProps,
} from '@/types/plugin-area';

/** What a block shows while its data is on its way. */
function BlockSkeleton({ block }: { block: AreaBlock }) {
    if (block.type === 'figures') {
        return (
            <div
                data-test="area-skeleton"
                className="grid grid-cols-2 gap-3 lg:grid-cols-4"
            >
                {block.items.map((item) => (
                    <Skeleton key={item.id} className="h-22 rounded-lg" />
                ))}
            </div>
        );
    }

    return (
        <Skeleton
            data-test="area-skeleton"
            className={
                block.type === 'chart' ? 'h-80 rounded-lg' : 'h-64 rounded-lg'
            }
        />
    );
}

/** A block that couldn't be shown: one line, in its place. */
function Failed({ line }: { line: string }) {
    return (
        <Panel data-test="area-block-failed">
            <p className="text-body text-ink-2 px-5 py-4">{line}</p>
        </Panel>
    );
}

/**
 * A plugin's own area (docs/plans/plugin-sockets-2.md, A9): headed by the
 * area's own label, with the plugin's name in the badge beside it, its
 * pages as in-page navigation, then each block top to bottom, each loading
 * on its own. The top bar names only its parent, Tools for an area placed
 * there, so it repeats nothing.
 */
export default function PluginArea(props: PluginAreaPageProps) {
    const { plugin, area, tabs, current, blocks, asks, parent } = props;

    setLayoutProps({ breadcrumbs: parent === null ? [] : [parent] });

    const ask = (block: string, next: AreaTableAsk) =>
        router.get(
            PluginAreaController.show.url({
                code: plugin.code,
                page: current.id,
            }),
            { t: { ...asks, [block]: next } },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: [`block_${block}`, 'asks'],
            },
        );

    return (
        <>
            <Head title={`${current.label} · ${area.label}`} />
            <PluginAreaPage
                plugin={{ name: plugin.name }}
                title={area.label}
                description={area.description || undefined}
                pages={tabs.map((one) => ({
                    href: PluginAreaController.show.url({
                        code: plugin.code,
                        page: one.id,
                    }),
                    label: one.label,
                    current: one.current,
                }))}
            >
                <div className="grid gap-6" data-test="area-blocks">
                    {blocks.map((block) => {
                        if (block.type === 'notice') {
                            return (
                                <Notice
                                    key={block.id}
                                    tone={
                                        block.tone === 'neutral'
                                            ? 'info'
                                            : block.tone
                                    }
                                    data-test="area-notice"
                                >
                                    {block.text}
                                </Notice>
                            );
                        }

                        if (block.type === 'actions') {
                            return (
                                <AreaActions
                                    key={block.id}
                                    plugin={plugin}
                                    block={block}
                                />
                            );
                        }

                        const key = `block_${block.id}` as const;

                        return (
                            <Deferred
                                key={block.id}
                                data={key}
                                fallback={<BlockSkeleton block={block} />}
                            >
                                {() => {
                                    const data = props[key] as
                                        | (AreaFiguresData &
                                              AreaChartData &
                                              AreaRowsData)
                                        | null
                                        | undefined;

                                    if (data === null || data === undefined) {
                                        return null;
                                    }

                                    if (data.failed !== null) {
                                        return <Failed line={data.failed} />;
                                    }

                                    if (block.type === 'figures') {
                                        return (
                                            <AreaFigures
                                                data={data}
                                                label={current.label}
                                            />
                                        );
                                    }

                                    if (block.type === 'chart') {
                                        return (
                                            <AreaChart
                                                block={block}
                                                data={data}
                                            />
                                        );
                                    }

                                    return (
                                        <AreaTable
                                            plugin={plugin}
                                            page={current.id}
                                            block={block}
                                            data={data}
                                            ask={asks[block.id] ?? {}}
                                            onAsk={(next) =>
                                                ask(block.id, next)
                                            }
                                        />
                                    );
                                }}
                            </Deferred>
                        );
                    })}
                </div>
            </PluginAreaPage>
        </>
    );
}
