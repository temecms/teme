import { AreaChart } from '@/components/plugins/area/area-chart';
import { AreaFigures } from '@/components/plugins/area/area-figures';
import { PluginCell } from '@/components/list/plugin-cell';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import type { DashboardPluginBlock } from '@/types/dashboard';
import type {
    AreaChartData,
    AreaFiguresData,
    AreaRowsData,
    AreaTableBlock,
} from '@/types/plugin-area';

/**
 * A plugin's Home panel body: its blocks as its area draws them (figures, a
 * chart, the first rows of a table, read only, and a notice). A block that
 * couldn't be read says so in one line, and the rest of the panel still shows.
 */
export function PluginPanelBlocks({
    blocks,
    label,
    noticesOnly = false,
}: {
    blocks: DashboardPluginBlock[];
    label: string;
    /** Show only the notices, such as under a failed panel's error line. */
    noticesOnly?: boolean;
}) {
    const shown = noticesOnly
        ? blocks.filter((block) => block.type === 'notice')
        : blocks;

    return (
        <div className="grid gap-4" data-test="home-plugin-blocks">
            {shown.map((block) => {
                if (block.type === 'notice') {
                    return (
                        <Notice
                            key={block.id}
                            tone={
                                block.tone === 'neutral' ? 'info' : block.tone
                            }
                        >
                            {block.text}
                        </Notice>
                    );
                }

                if (block.failed === true || block.data === null) {
                    return unreadable(block.id);
                }

                if (block.type === 'figures') {
                    const data = block.data as AreaFiguresData;
                    const figures = block as { empty?: string };

                    return data.failed !== null ? (
                        unreadable(block.id)
                    ) : (
                        <AreaFigures
                            key={block.id}
                            data={data}
                            label={label}
                            empty={figures.empty}
                        />
                    );
                }

                if (block.type === 'chart') {
                    const data = block.data as AreaChartData;

                    return data.failed !== null ? (
                        unreadable(block.id)
                    ) : (
                        <AreaChart key={block.id} block={block} data={data} />
                    );
                }

                if (block.type === 'table') {
                    const data = block.data as AreaRowsData;

                    return data.failed !== null ? (
                        unreadable(block.id)
                    ) : (
                        <ReadOnlyRows
                            key={block.id}
                            block={block}
                            data={data}
                        />
                    );
                }

                return null;
            })}
        </div>
    );
}

/**
 * The first rows of a table block, read only, one line each with its cells
 * joined: no sorting, search, filters or row actions, which stay on the
 * plugin's own screen.
 */
function ReadOnlyRows({
    block,
    data,
}: {
    block: AreaTableBlock;
    data: AreaRowsData;
}) {
    return (
        <div>
            <h4 className="text-small text-ink-3 font-semibold">
                {block.record.plural}
            </h4>
            <ul className="text-small mt-2 grid gap-1">
                {data.rows.map((row) => (
                    <li key={row.id}>
                        {block.columns.map((column, index) => (
                            <span key={column.id}>
                                {index > 0 && ' · '}
                                <PluginCell value={row.cells[column.id]} />
                            </span>
                        ))}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function unreadable(id: string) {
    return (
        <p key={id} className="text-small text-ink-3">
            {__(
                "This block couldn't load. The rest of this panel is unaffected.",
            )}
        </p>
    );
}
