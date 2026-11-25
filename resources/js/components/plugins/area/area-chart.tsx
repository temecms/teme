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
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import type { AreaChartBlock, AreaChartData } from '@/types/plugin-area';

/** The series' colours, from the design tokens, in order. */
const COLOURS = ['var(--chart-1)', 'var(--chart-2)', 'var(--ink-3)'];

/** A chart label as people read a day: "18 Oct", else the label as given. */
function dayLabel(label: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(label);

    if (match === null) {
        return label;
    }

    const date = new Date(
        Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])),
    );

    return new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    }).format(date);
}

/** Whether a series is the earlier comparison line (dashed). */
function isEarlier(
    series: AreaChartData['series'][number],
    index: number,
    total: number,
): boolean {
    const label = series.label.toLowerCase();

    if (
        label.includes('earlier') ||
        label.includes('previous') ||
        label.includes('prior')
    ) {
        return true;
    }

    return total === 2 && index === 1;
}

/**
 * A plugin's chart (docs/plans/plugin-sockets-2.md, A9): a line or ranked
 * bar chart core draws with Recharts from the plugin's numbers, its legend
 * above, earlier series dashed, day labels as "18 Oct", and Show as table
 * for the same numbers.
 */
export function AreaChart({
    block,
    data,
}: {
    block: AreaChartBlock;
    data: AreaChartData;
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
            total: data.series.reduce(
                (sum, series) => sum + (series.values[index] ?? 0),
                0,
            ),
        }));

        // Ranked bars: categories ordered by the first series, highest first.
        if (block.kind === 'bar' && data.series[0] !== undefined) {
            const key = data.series[0].id;

            return [...built].sort(
                (a, b) => Number(b[key] ?? 0) - Number(a[key] ?? 0),
            );
        }

        return built;
    }, [block.kind, data.labels, data.series]);
    const Chart = block.kind === 'bar' ? BarChart : LineChart;

    return (
        <Panel data-test="area-chart" data-kind={block.kind}>
            <figure className="grid gap-3 p-5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <figcaption className="text-body font-semibold">
                        {block.label}
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
                    <div
                        role="table"
                        aria-label={block.label}
                        data-test="area-chart-table"
                        className="text-small overflow-x-auto"
                    >
                        <div
                            role="row"
                            className="text-ink-3 border-line grid gap-2 border-b py-2 font-medium"
                            style={{
                                gridTemplateColumns: `minmax(6rem,1fr) repeat(${data.series.length}, minmax(4rem,1fr))`,
                            }}
                        >
                            <span role="columnheader">{__('Point')}</span>
                            {data.series.map((series) => (
                                <span
                                    key={series.id}
                                    role="columnheader"
                                    className="text-end"
                                >
                                    {series.label}
                                </span>
                            ))}
                        </div>
                        {rows.map((row) => (
                            <div
                                key={row.raw}
                                role="row"
                                className="border-line grid gap-2 border-b py-2 last:border-0"
                                style={{
                                    gridTemplateColumns: `minmax(6rem,1fr) repeat(${data.series.length}, minmax(4rem,1fr))`,
                                }}
                            >
                                <span role="rowheader">{row.label}</span>
                                {data.series.map((series) => (
                                    <span
                                        key={series.id}
                                        role="cell"
                                        className="text-end tabular-nums"
                                    >
                                        {row[series.id] ?? 0}
                                    </span>
                                ))}
                            </div>
                        ))}
                    </div>
                ) : (
                    <>
                        <ul
                            aria-hidden="true"
                            className="text-small text-ink-2 flex flex-wrap gap-4"
                            data-test="area-chart-legend"
                        >
                            {data.series.map((series, index) => (
                                <li
                                    key={series.id}
                                    className="inline-flex items-center gap-2"
                                >
                                    <span
                                        className="inline-block h-0.5 w-4"
                                        style={{
                                            background:
                                                COLOURS[index % COLOURS.length],
                                            borderTop: isEarlier(
                                                series,
                                                index,
                                                data.series.length,
                                            )
                                                ? '2px dashed currentColor'
                                                : undefined,
                                            backgroundColor: isEarlier(
                                                series,
                                                index,
                                                data.series.length,
                                            )
                                                ? 'transparent'
                                                : COLOURS[
                                                      index % COLOURS.length
                                                  ],
                                            color: COLOURS[
                                                index % COLOURS.length
                                            ],
                                        }}
                                    />
                                    {series.label}
                                </li>
                            ))}
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
                                                    isEarlier(
                                                        series,
                                                        index,
                                                        data.series.length,
                                                    )
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
