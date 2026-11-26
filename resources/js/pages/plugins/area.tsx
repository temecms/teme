import { Deferred, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import PluginAreaController from '@/actions/App/Http/Admin/PluginAreaController';
import { PluginAreaPage } from '@/components/patterns/plugin-area-page';
import { AreaActions } from '@/components/plugins/area/area-actions';
import { AreaChart } from '@/components/plugins/area/area-chart';
import { AreaFigures } from '@/components/plugins/area/area-figures';
import { AreaTable, areaTableAdd } from '@/components/plugins/area/area-table';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { Notice } from '@/components/ui/notice';
import { Panel } from '@/components/ui/panel';
import { Skeleton } from '@/components/ui/skeleton';
import type {
    AreaBlock,
    AreaChartData,
    AreaFiguresData,
    AreaRowsData,
    AreaTableAsk,
    AreaTableBlock,
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

/**
 * A block that couldn't be shown (design-system §4.25): the octagon, the
 * full line naming what failed, and Try again.
 */
function Failed({ line, onRetry }: { line: string; onRetry: () => void }) {
    return (
        <Panel data-test="area-block-failed">
            <ErrorState message={line} onRetry={onRetry} className="pt-5" />
        </Panel>
    );
}

/**
 * A plugin's own area (docs/plans/plugin-sockets-2.md, A9): headed by the
 * area's own label, with the plugin's name in the badge beside it, its
 * pages as in-page navigation, then each block top to bottom, each loading
 * on its own. The top bar names the area and the current page (design
 * system 4.31).
 * Add for a table sits in the page header.
 */
export default function PluginArea(props: PluginAreaPageProps) {
    const { plugin, area, tabs, current, blocks, asks } = props;
    const areaHome =
        tabs[0]?.href ?? PluginAreaController.show.url({ code: plugin.code });
    // The area's home page is the area alone; other pages add the tab after it.
    const onHome =
        tabs[0] !== undefined && (tabs[0].current || current.id === tabs[0].id);

    setLayoutProps({
        breadcrumbs: onHome
            ? [{ title: area.label, href: areaHome }]
            : [
                  { title: area.label, href: areaHome },
                  {
                      title: current.label,
                      href:
                          tabs.find((one) => one.current)?.href ??
                          PluginAreaController.show.url({
                              code: plugin.code,
                              page: current.id,
                          }),
                  },
              ],
    });

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

    const retry = (block: string) =>
        router.reload({
            only: [`block_${block}`],
        });

    const tableBlocks = blocks.filter(
        (block): block is AreaTableBlock => block.type === 'table',
    );
    const add =
        tableBlocks[0] !== undefined
            ? areaTableAdd(plugin, tableBlocks[0])
            : null;

    return (
        <>
            <Head title={`${current.label} · ${area.label}`} />
            <PluginAreaPage
                plugin={{ name: plugin.name, icon: plugin.icon }}
                title={area.label}
                showTitle
                description={area.description || undefined}
                actions={
                    add !== null ? (
                        <Button asChild variant="primary">
                            <Link href={add.href} data-test="area-table-add">
                                <Plus aria-hidden="true" />
                                {add.label}
                            </Link>
                        </Button>
                    ) : undefined
                }
                pages={tabs.map((one) => {
                    let count = one.count;
                    // The current page's first table total fills the tab when the server sent none.
                    if (count === undefined && one.current) {
                        const table = blocks.find(
                            (block): block is AreaTableBlock =>
                                block.type === 'table',
                        );
                        const data =
                            table === undefined
                                ? undefined
                                : (props[
                                      `block_${table.id}` as `block_${string}`
                                  ] as AreaRowsData | undefined);
                        if (
                            data !== undefined &&
                            typeof data.total === 'number'
                        ) {
                            count = data.total;
                        }
                    }

                    return {
                        href:
                            one.href ??
                            PluginAreaController.show.url({
                                code: plugin.code,
                                page: one.id,
                            }),
                        label: one.label,
                        current: one.current,
                        count,
                    };
                })}
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
                                        return (
                                            <Failed
                                                line={data.failed}
                                                onRetry={() => retry(block.id)}
                                            />
                                        );
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
