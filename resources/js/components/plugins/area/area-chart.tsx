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
import type { AreaChartBlock, AreaChartData } from '@/types/plugin-area';

/** The series' colours, from the design tokens, in order. */
const COLOURS = ['var(--chart-1)', 'var(--chart-2)', 'var(--ink-3)'];

/**
 * A plugin's chart (docs/plans/plugin-sockets-2.md, A9, as the maintainers
 * decided): a line or bar chart core draws with Recharts from the plugin's
 * numbers, its legend beneath, and the same numbers in a table for screen
 * readers. The plugin hands over data only.
 */
export function AreaChart({
    block,
    data,
}: {
    block: AreaChartBlock;
    data: AreaChartData;
}) {
    const rows = data.labels.map((label, index) => ({
        label,
        ...Object.fromEntries(
            data.series.map((series) => [series.id, series.values[index] ?? 0]),
        ),
    }));
    const Chart = block.kind === 'bar' ? BarChart : LineChart;

    return (
        <Panel data-test="area-chart" data-kind={block.kind}>
            <figure className="grid gap-3 p-5">
                <figcaption className="text-body font-semibold">
                    {block.label}
                </figcaption>
                <div aria-hidden="true" className="h-64 w-full">
                    <ResponsiveContainer width="100%" height="100%">
                        <Chart
                            data={rows}
                            margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                        >
                            <CartesianGrid
                                vertical={false}
                                stroke="var(--line)"
                            />
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
                            <Tooltip />
                            <Legend />
                            {data.series.map((series, index) =>
                                block.kind === 'bar' ? (
                                    <Bar
                                        key={series.id}
                                        dataKey={series.id}
                                        name={series.label}
                                        fill={COLOURS[index % COLOURS.length]}
                                        isAnimationActive={false}
                                    />
                                ) : (
                                    <Line
                                        key={series.id}
                                        dataKey={series.id}
                                        name={series.label}
                                        stroke={COLOURS[index % COLOURS.length]}
                                        strokeWidth={2}
                                        dot={false}
                                        isAnimationActive={false}
                                    />
                                ),
                            )}
                        </Chart>
                    </ResponsiveContainer>
                </div>
                {/* The same numbers for screen readers: a table by its roles, as raw tables stay in ui/ (R5). */}
                <div
                    role="table"
                    aria-label={block.label}
                    className="sr-only"
                    data-test="area-chart-table"
                >
                    <div role="row">
                        <span role="columnheader">{''}</span>
                        {data.series.map((series) => (
                            <span key={series.id} role="columnheader">
                                {series.label}
                            </span>
                        ))}
                    </div>
                    {data.labels.map((label, index) => (
                        <div key={label} role="row">
                            <span role="rowheader">{label}</span>
                            {data.series.map((series) => (
                                <span key={series.id} role="cell">
                                    {series.values[index] ?? 0}
                                </span>
                            ))}
                        </div>
                    ))}
                </div>
            </figure>
        </Panel>
    );
}
