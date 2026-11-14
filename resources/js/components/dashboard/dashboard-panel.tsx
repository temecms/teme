import { Link } from '@inertiajs/react';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import type { DashboardPanelData } from '@/types';
import { shownTitle } from '@/lib/titles';

/**
 * One panel of the person's own work: its name and See all in a hairline
 * header, then up to five items, each its title as a link to the editor and
 * a line in words. Rows wrap; nothing is cut off.
 */
export function DashboardPanel({ panel }: { panel: DashboardPanelData }) {
    const labelId = `panel-${panel.key}`;

    return (
        <Panel aria-labelledby={labelId} className="border-t first:border-t">
            <PanelHeader>
                <h2 id={labelId}>{panel.label}</h2>
                <span className="flex-1" />
                {panel.seeAll !== null && (
                    <Link
                        href={panel.seeAll}
                        className="text-link text-[13px] font-normal underline-offset-2 hover:underline"
                    >
                        See all
                        <span className="sr-only"> {panel.label}</span>
                    </Link>
                )}
            </PanelHeader>
            <PanelBody className="gap-0">
                <ul>
                    {panel.rows.map((row) => (
                        <li
                            key={row.id}
                            className="border-border grid min-h-11 content-center gap-0.5 border-t py-2 text-[13px]"
                        >
                            <Link
                                href={row.url}
                                className="font-medium break-words hover:underline"
                            >
                                {shownTitle(row.title)}
                            </Link>
                            <span className="text-muted-foreground break-words">
                                {row.subtitle}
                            </span>
                        </li>
                    ))}
                </ul>
            </PanelBody>
        </Panel>
    );
}
