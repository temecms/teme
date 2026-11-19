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
 * One heading and its cards. The hub's grid gives each item one cell, so a
 * section spans the whole row and lays out its own cards on the hub's
 * columns, which it inherits.
 */
function HubSection({
    id,
    title,
    hint,
    tools,
}: {
    id: string;
    title: string;
    hint?: string;
    tools: ToolPage[];
}) {
    return (
        <section
            aria-labelledby={id}
            className="grid gap-4 pb-4 [li:has(>&)]:col-span-full"
            data-test={id}
        >
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id={id} className="text-lead text-ink font-semibold">
                    {title}
                </h2>
                {hint !== undefined && (
                    <span className="text-small text-ink-2">{hint}</span>
                )}
            </div>
            <ul className="grid grid-cols-(--hub-cols) gap-4">
                {tools.map((tool) => (
                    <li key={tool.key}>{toolCard(tool)}</li>
                ))}
            </ul>
        </section>
    );
}

/**
 * The Tools hub (I-467): Teme's tools under the heading a person would look
 * for them by, then the pages plugins added under From plugins, each with
 * its plugin's mark.
 */
export default function ToolsIndex({ tools }: ToolsIndexPage) {
    setLayoutProps({ breadcrumbs: [{ title: __('Tools'), href: index() }] });

    const own = tools.filter((tool) => tool.plugin === undefined);
    const sectioned = SECTIONS.flatMap((section) => section.keys);
    const sections = SECTIONS.map((section, at) => ({
        id: `tools-section-${at}`,
        title: section.title(),
        tools: section.keys.flatMap((key) =>
            own.filter((tool) => tool.key === key),
        ),
    }));
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
                cards={[
                    ...sections
                        .filter((section) => section.tools.length > 0)
                        .map((section) => (
                            <HubSection
                                key={section.id}
                                id={section.id}
                                title={section.title}
                                tools={section.tools}
                            />
                        )),
                    ...(other.length > 0 || fromPlugins.length > 0
                        ? [
                              <HubSection
                                  key="plugins"
                                  id="tools-from-plugins"
                                  title={__('From plugins')}
                                  hint={__('Each names its plugin.')}
                                  tools={[...other, ...fromPlugins]}
                              />,
                          ]
                        : []),
                ]}
            />
        </>
    );
}
