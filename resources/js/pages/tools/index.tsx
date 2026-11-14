import { Head, Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { PageHeader } from '@/components/list/page-header';
import { navIcon } from '@/lib/nav-icons';
import { index } from '@/routes/tools';
import type { ToolsIndexPage } from '@/types';

const CARD =
    'border-border hover:bg-muted focus-visible:ring-ring flex h-full gap-3 rounded-md border p-4 focus-visible:ring-2 focus-visible:outline-none';

/** The Tools hub (I-467): a card for each tool the person may open. */
export default function ToolsIndex({ tools }: ToolsIndexPage) {
    return (
        <>
            <Head title="Tools" />

            <div className="space-y-6 p-6">
                <PageHeader
                    title="Tools"
                    description="Utilities for looking after the site."
                />

                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {tools.map((tool) => {
                        const Icon = navIcon(tool.icon);

                        const body = (
                            <>
                                <Icon
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                <span className="grid gap-1">
                                    <span className="flex items-center gap-1 text-[13px] font-semibold">
                                        {tool.label}
                                        {tool.external && (
                                            <>
                                                <ExternalLink
                                                    aria-hidden="true"
                                                    className="text-muted-foreground size-3"
                                                />
                                                <span className="sr-only">
                                                    (opens in a new tab)
                                                </span>
                                            </>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground text-[13px]">
                                        {tool.description}
                                    </span>
                                </span>
                            </>
                        );

                        return (
                            <li key={tool.key}>
                                {/* A plugin's link to another site leaves Teme in a new tab (I-3353). */}
                                {tool.external ? (
                                    <a
                                        href={tool.href}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        data-test={`tool-${tool.key}`}
                                        className={CARD}
                                    >
                                        {body}
                                    </a>
                                ) : (
                                    <Link
                                        href={tool.href}
                                        data-test={`tool-${tool.key}`}
                                        className={CARD}
                                    >
                                        {body}
                                    </Link>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </>
    );
}

ToolsIndex.layout = {
    breadcrumbs: [{ title: 'Tools', href: index() }],
};
