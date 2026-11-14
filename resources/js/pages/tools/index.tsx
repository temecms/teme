import { Head } from '@inertiajs/react';
import { HubCard, HubPage } from '@/components/patterns/hub-page';
import { navIcon } from '@/lib/nav-icons';
import { index } from '@/routes/tools';
import type { ToolsIndexPage } from '@/types';

/** The Tools hub (I-467): a card for each tool the person may open. */
export default function ToolsIndex({ tools }: ToolsIndexPage) {
    return (
        <>
            <Head title="Tools" />

            <HubPage
                title="Tools"
                description="Utilities for looking after the site."
                cards={tools.map((tool) => (
                    // A plugin's link to another site leaves Teme in a new tab (I-3353).
                    <HubCard
                        key={tool.key}
                        href={tool.href}
                        external={tool.external}
                        icon={navIcon(tool.icon)}
                        title={tool.label}
                        description={tool.description}
                        testId={`tool-${tool.key}`}
                    />
                ))}
            />
        </>
    );
}

ToolsIndex.layout = {
    breadcrumbs: [{ title: 'Tools', href: index() }],
};
