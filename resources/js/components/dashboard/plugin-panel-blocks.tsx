import { AreaChart } from '@/components/plugins/area/area-chart';
import { AreaFigures } from '@/components/plugins/area/area-figures';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import type { DashboardPluginBlock } from '@/types/dashboard';
import type {
    AreaChartData,
    AreaFiguresData,
    AreaRowsData,
    AreaTableBlock,
} from '@/types/plugin-area';
import type { PluginCellValue } from '@/types/plugin-columns';

/**
 * A plugin's Home panel body: its blocks as its area draws them (figures, a
 * chart, the first rows of a table, read only, and a notice). A block that
 * couldn't be read says so in one line, and the rest of the panel still shows.
 */
export function PluginPanelBlocks({
    blocks,
    label,
}: {
    blocks: DashboardPluginBlock[];
    label: string;
}) {
    return (
        <div className="grid gap-4" data-test="home-plugin-blocks">
            {blocks.map((block) => {
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

                    return data.failed !== null ? (
                        unreadable(block.id)
                    ) : (
                        <AreaFigures key={block.id} data={data} label={label} />
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
                        {block.columns
                            .map((column) => cellText(row.cells[column.id]))
                            .filter((text) => text !== '')
                            .join(' · ')}
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

function cellText(cell: PluginCellValue | undefined): string {
    if (cell === undefined) {
        return '';
    }

    return String(cell.value);
}
