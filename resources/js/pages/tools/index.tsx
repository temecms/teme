import { Head, setLayoutProps } from '@inertiajs/react';
import { HubCard, HubPage } from '@/components/patterns/hub-page';
import { __ } from '@/lib/i18n';
import { navIcon } from '@/lib/nav-icons';
import { index } from '@/routes/tools';
import type { ToolPage, ToolsIndexPage } from '@/types';

/** One tool's card; a plugin's link to another site leaves Teme in a new tab (I-3353). */
function toolCard(tool: ToolPage) {
    return (
        <HubCard
            key={tool.key}
            href={tool.href}
            external={tool.external}
            icon={navIcon(tool.icon)}
            title={tool.label}
            description={tool.description}
            plugin={tool.plugin}
            testId={`tool-${tool.key}`}
        />
    );
}

/** The heading each of Teme's own tools sits under, in the hub's order. */
const SECTIONS: { title: () => string; keys: string[] }[] = [
    {
        title: () => __('Health and activity'),
        keys: ['jobs', 'updates', 'ai-activity'],
    },
    {
        title: () => __('Content in and out'),
        keys: ['import', 'backups', 'page-cache', 'redirects'],
    },
];

/**
 * The Tools hub (I-467): Teme's tools under the heading a person would look
 * for them by, then the pages plugins added under From plugins, each with
 * its plugin's mark.
 */
export default function ToolsIndex({ tools }: ToolsIndexPage) {
    setLayoutProps({ breadcrumbs: [{ title: __('Tools'), href: index() }] });

    const own = tools.filter((tool) => tool.plugin === undefined);
    const sectioned = SECTIONS.flatMap((section) => section.keys);
    const sections = SECTIONS.map((section) => ({
        heading: section.title(),
        cards: section.keys.flatMap((key) =>
            own.filter((tool) => tool.key === key).map(toolCard),
        ),
    })).filter((section) => section.cards.length > 0);
    // A tool of Teme's own that no heading names yet comes first, on its own.
    const other = own.filter((tool) => !sectioned.includes(tool.key));
    const fromPlugins = tools.filter((tool) => tool.plugin !== undefined);

    return (
        <>
            <Head title={__('Tools')} />

            <HubPage
                title={__('Tools')}
                description={__(
                    'Teme’s tools, then the pages plugins add. A plugin’s pages live here unless it earns a menu entry of its own.',
                )}
                cards={other.map(toolCard)}
                sections={sections}
                pluginCards={fromPlugins.map(toolCard)}
            />
        </>
    );
}
