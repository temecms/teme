import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Panel } from '@/components/ui/panel';
import { ScrollArea } from '@/components/ui/scroll-area';
import { TextButton } from '@/components/ui/text-button';
import { adminLocale } from '@/lib/dates';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    AreaChartBlock,
    AreaChartData,
    AreaPeriodProps,
} from '@/types/plugin-area';

/** The series' colours, from the design tokens, in order. */
const COLOURS = ['var(--chart-1)', 'var(--chart-2)', 'var(--ink-3)'];

/** Legend swatch fills as Tailwind token classes (no inline colour styles). */
const SWATCH = ['bg-chart-1', 'bg-chart-2', 'bg-ink-3'] as const;

/** A chart label as people read a day: "18 Oct", else the label as given. */
function dayLabel(label: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(label);

    if (match === null) {
        return label;
    }

    const date = new Date(
        Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])),
    );

    return new Intl.DateTimeFormat(adminLocale(), {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    }).format(date);
}

/**
 * A plugin's chart (docs/plans/plugin-sockets-2.md, A9): a line or ranked
 * bar chart core draws with Recharts from the plugin's numbers, in the
 * order the plugin gave them, its legend above, day labels as "18 Oct", and
 * Show as table for the same numbers. Earlier-period series are dashed.
 */
export function AreaChart({
    block,
    data,
    period,
}: {
    block: AreaChartBlock;
    data: AreaChartData;
    period?: AreaPeriodProps | null;
}) {
    const [asTable, setAsTable] = useState(false);
    const labels = data.labels.map(dayLabel);
    const rows = useMemo(() => {
        type Row = {
            label: string;
            raw: string;
            total: number;
        } & Record<string, string | number>;

        const built: Row[] = data.labels.map((label, index) => ({
            label: dayLabel(label),
            raw: label,
            ...Object.fromEntries(
                data.series.map((series) => [
                    series.id,
                    series.values[index] ?? 0,
                ]),
            ),
            total: data.series
                .filter((series) => !series.dashed)
                .reduce((sum, series) => sum + (series.values[index] ?? 0), 0),
        }));

        return built;
    }, [data.labels, data.series]);
    const Chart = block.kind === 'bar' ? BarChart : LineChart;
    // Long periods group by week; the rest by day — from the period id, not
    // how many points the plugin returned.
    const byWeek = period?.id === 'last_90_days' || period?.id === 'this_year';
    // One sentence per unit, so a translator can put the label where their language needs it.
    const title = byWeek
        ? __(':label by week', { label: block.label })
        : __(':label by day', { label: block.label });
    const ariaLabel =
        period !== null && period !== undefined
            ? `${title}: ${period.range}.`
            : `${title}: ${labels[0] ?? ''}–${labels.at(-1) ?? ''}.`;

    if (data.empty) {
        return (
            <Panel data-test="area-chart-empty">
                <p className="text-body text-ink-2 p-5">
                    {__('No statistics yet')}
                </p>
            </Panel>
        );
    }

    return (
        <Panel data-test="area-chart" data-kind={block.kind}>
            <figure className="grid gap-3 p-5" aria-label={ariaLabel}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <figcaption className="text-body font-semibold">
                        {title}
                    </figcaption>
                    <TextButton
                        type="button"
                        data-test="area-chart-table-toggle"
                        onClick={() => setAsTable((on) => !on)}
                    >
                        {asTable ? __('Show as chart') : __('Show as table')}
                    </TextButton>
                </div>
                {asTable ? (
                    <ScrollArea
                        axis="x"
                        label={block.label}
                        data-test="area-chart-table"
                        className="text-small"
                    >
                        <div role="table" aria-label={block.label}>
                            <div
                                role="row"
                                className="text-ink-3 border-line flex gap-2 border-b py-2 font-medium"
                            >
                                <span
                                    role="columnheader"
                                    className="min-w-24 flex-1"
                                >
                                    {__('Point')}
                                </span>
                                {data.series.map((series) => (
                                    <span
                                        key={series.id}
                                        role="columnheader"
                                        className="min-w-16 flex-1 text-end"
                                    >
                                        {series.label}
                                    </span>
                                ))}
                            </div>
                            {rows.map((row) => (
                                <div
                                    key={row.raw}
                                    role="row"
                                    className="border-line flex gap-2 border-b py-2 last:border-0"
                                >
                                    <span
                                        role="rowheader"
                                        className="min-w-24 flex-1"
                                    >
                                        {row.label}
                                    </span>
                                    {data.series.map((series) => (
                                        <span
                                            key={series.id}
                                            role="cell"
                                            className="min-w-16 flex-1 text-end tabular-nums"
                                        >
                                            {row[series.id] ?? 0}
                                        </span>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </ScrollArea>
                ) : (
                    <>
                        <ul
                            aria-hidden="true"
                            className="text-small text-ink-2 flex flex-wrap gap-4"
                            data-test="area-chart-legend"
                        >
                            {data.series.map((series, index) => {
                                const at = index % SWATCH.length;

                                return (
                                    <li
                                        key={series.id}
                                        className="inline-flex items-center gap-2"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'inline-block h-0.5 w-4',
                                                series.dashed
                                                    ? 'border-ink-3 border-t border-dashed bg-transparent'
                                                    : SWATCH[at],
                                            )}
                                        />
                                        {series.label}
                                    </li>
                                );
                            })}
                        </ul>
                        <div aria-hidden="true" className="h-64 w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <Chart
                                    data={rows}
                                    accessibilityLayer={false}
                                    layout={
                                        block.kind === 'bar'
                                            ? 'vertical'
                                            : 'horizontal'
                                    }
                                    margin={{
                                        top: 8,
                                        right: 8,
                                        bottom: 0,
                                        left: block.kind === 'bar' ? 8 : 0,
                                    }}
                                >
                                    <CartesianGrid
                                        vertical={block.kind !== 'bar'}
                                        horizontal={block.kind === 'bar'}
                                        stroke="var(--line)"
                                    />
                                    {block.kind === 'bar' ? (
                                        <>
                                            <XAxis
                                                type="number"
                                                tickLine={false}
                                                axisLine={false}
                                                fontSize={12}
                                            />
                                            <YAxis
                                                type="category"
                                                dataKey="label"
                                                tickLine={false}
                                                axisLine={false}
                                                fontSize={12}
                                                width={72}
                                            />
                                        </>
                                    ) : (
                                        <>
                                            <XAxis
                                                dataKey="label"
                                                tickLine={false}
                                                axisLine={false}
                                                fontSize={12}
                                            />
                                            <YAxis
                                                tickLine={false}
                                                axisLine={false}
                                                fontSize={12}
                                                width={40}
                                            />
                                        </>
                                    )}
                                    <Tooltip />
                                    <Legend
                                        content={() => null}
                                        wrapperStyle={{ display: 'none' }}
                                    />
                                    {data.series.map((series, index) =>
                                        block.kind === 'bar' ? (
                                            <Bar
                                                key={series.id}
                                                dataKey={series.id}
                                                name={series.label}
                                                fill={
                                                    COLOURS[
                                                        index % COLOURS.length
                                                    ]
                                                }
                                                fillOpacity={
                                                    series.dashed ? 0.35 : 1
                                                }
                                                isAnimationActive={false}
                                            />
                                        ) : (
                                            <Line
                                                key={series.id}
                                                dataKey={series.id}
                                                name={series.label}
                                                stroke={
                                                    COLOURS[
                                                        index % COLOURS.length
                                                    ]
                                                }
                                                strokeWidth={2}
                                                strokeDasharray={
                                                    series.dashed
                                                        ? '4 4'
                                                        : undefined
                                                }
                                                dot={false}
                                                isAnimationActive={false}
                                            />
                                        ),
                                    )}
                                </Chart>
                            </ResponsiveContainer>
                        </div>
                        <div
                            role="table"
                            aria-label={block.label}
                            className="sr-only"
                            data-test="area-chart-table"
                        >
                            <div role="row">
                                <span role="columnheader">{__('Point')}</span>
                                {data.series.map((series) => (
                                    <span key={series.id} role="columnheader">
                                        {series.label}
                                    </span>
                                ))}
                            </div>
                            {labels.map((label, index) => (
                                <div key={data.labels[index]} role="row">
                                    <span role="rowheader">{label}</span>
                                    {data.series.map((series) => (
                                        <span key={series.id} role="cell">
                                            {series.values[index] ?? 0}
                                        </span>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </figure>
        </Panel>
    );
}
