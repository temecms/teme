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

/**
 * The Tools hub (I-467): a card for each tool the person may open, then the
 * links plugins added under From plugins, each with its plugin's mark.
 */
export default function ToolsIndex({ tools }: ToolsIndexPage) {
    setLayoutProps({ breadcrumbs: [{ title: __('Tools'), href: index() }] });

    return (
        <>
            <Head title={__('Tools')} />

            <HubPage
                title={__('Tools')}
                description={__('Utilities for looking after the site.')}
                cards={tools
                    .filter((tool) => tool.plugin === undefined)
                    .map(toolCard)}
                pluginCards={tools
                    .filter((tool) => tool.plugin !== undefined)
                    .map(toolCard)}
            />
        </>
    );
}
