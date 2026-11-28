import { Deferred, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
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
import { SelectField } from '@/components/ui/select-field';
import { Skeleton } from '@/components/ui/skeleton';
import { __ } from '@/lib/i18n';
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
 * system 4.31). Add for a table sits in the page header; a period select
 * when the page has statistics; a waiting-script banner above the filters.
 */
export default function PluginArea(props: PluginAreaPageProps) {
    const {
        plugin,
        area,
        tabs,
        current,
        blocks,
        asks,
        period,
        counts,
        waitingScripts = 0,
        waitingNoun = __('item'),
        waitingNouns = __('items'),
        reviewScripts,
    } = props;
    const areaHome =
        tabs[0]?.href ?? PluginAreaController.show.url({ code: plugin.code });
    // The area's home page is the area alone; other pages add the tab after it.
    const onHome =
        tabs[0] !== undefined && (tabs[0].current || current.id === tabs[0].id);
    const [periodLoading, setPeriodLoading] = useState(false);

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
            {
                t: { ...asks, [block]: next },
                ...(period !== null && period !== undefined
                    ? { period: period.id }
                    : {}),
            },
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

    const changePeriod = (next: string) => {
        setPeriodLoading(true);
        router.get(
            PluginAreaController.show.url({
                code: plugin.code,
                page: current.id,
            }),
            { period: next, t: asks },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: [
                    'period',
                    ...blocks
                        .filter(
                            (block) =>
                                block.type === 'figures' ||
                                block.type === 'chart',
                        )
                        .map((block) => `block_${block.id}` as const),
                ],
                onFinish: () => {
                    setPeriodLoading(false);
                    document.getElementById('area-period')?.focus();
                },
            },
        );
    };

    const tableBlocks = blocks.filter(
        (block): block is AreaTableBlock => block.type === 'table',
    );
    const add =
        tableBlocks[0] !== undefined
            ? areaTableAdd(plugin, tableBlocks[0])
            : null;

    const countsReady = counts !== undefined;
    const periodSelect =
        period !== null && period !== undefined ? (
            <div
                className="flex min-w-40 flex-col gap-1 max-sm:w-full"
                data-test="area-period"
            >
                <label
                    htmlFor="area-period"
                    className="text-small text-ink-3 font-medium"
                >
                    {__('Period')}
                </label>
                <SelectField
                    id="area-period"
                    aria-label={__('Period')}
                    value={period.id}
                    options={period.options.map((option) => ({
                        value: option.id,
                        label: option.label,
                    }))}
                    onValueChange={changePeriod}
                    className="max-sm:w-full"
                />
            </div>
        ) : null;

    return (
        <>
            <Head title={`${current.label} · ${area.label}`} />
            <PluginAreaPage
                plugin={{ name: plugin.name, icon: plugin.icon }}
                title={area.label}
                showTitle
                description={area.description || undefined}
                actions={
                    <div className="flex flex-wrap items-end gap-2 max-sm:w-full">
                        {periodSelect}
                        {add !== null ? (
                            <Button asChild variant="primary">
                                <Link
                                    href={add.href}
                                    data-test="area-table-add"
                                >
                                    <Plus aria-hidden="true" />
                                    {add.label}
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                }
                pages={tabs.map((one) => {
                    let count = one.count;
                    let counting = false;
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

                    if (
                        count === undefined &&
                        !one.current &&
                        countsReady &&
                        counts !== null &&
                        typeof counts[one.id] === 'number'
                    ) {
                        count = counts[one.id];
                    }

                    if (count === undefined && !one.current && !countsReady) {
                        counting = true;
                    }

                    if (
                        count === undefined &&
                        !one.current &&
                        countsReady &&
                        counts === null
                    ) {
                        counting = false;
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
                        counting,
                    };
                })}
            >
                {period !== null && period !== undefined && (
                    <p
                        className="text-small text-ink-3 mb-4"
                        role="status"
                        data-test="area-period-range"
                    >
                        {periodLoading
                            ? __('Loading the new period…')
                            : `${period.range} ${period.compare}`}
                    </p>
                )}
                {waitingScripts > 0 && (
                    <Notice
                        tone="warn"
                        role="status"
                        className="mb-4"
                        data-test="area-scripts-waiting"
                        action={
                            reviewScripts ? (
                                <Button asChild variant="neutral" size="sm">
                                    <Link
                                        href={reviewScripts}
                                        data-test="area-review-scripts"
                                    >
                                        {__('Review scripts')}
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    >
                        {waitingScripts === 1
                            ? __(
                                  '1 script waits for approval. Until it’s approved, its :noun shows without it.',
                                  { noun: waitingNoun },
                              )
                            : __(
                                  ':count scripts wait for approval. Until they’re approved, their :nouns show without them.',
                                  {
                                      count: waitingScripts,
                                      nouns: waitingNouns,
                                  },
                              )}
                    </Notice>
                )}
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

                        if (
                            periodLoading &&
                            (block.type === 'figures' || block.type === 'chart')
                        ) {
                            return (
                                <BlockSkeleton key={block.id} block={block} />
                            );
                        }

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
                                                empty={block.empty}
                                            />
                                        );
                                    }

                                    if (block.type === 'chart') {
                                        return (
                                            <AreaChart
                                                block={block}
                                                data={data}
                                                period={period}
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
