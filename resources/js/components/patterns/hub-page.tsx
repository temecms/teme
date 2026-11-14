import { Link } from '@inertiajs/react';
import type { LucideProps } from 'lucide-react';
import { ExternalLink } from 'lucide-react';
import type { ComponentType, CSSProperties, ReactNode } from 'react';
import { Page } from '@/components/patterns/page';
import { PageHeader } from '@/components/patterns/page-header';
import { IconTile } from '@/components/ui/icon-tile';
import { PluginMark } from '@/components/ui/plugin-mark';
import { __ } from '@/lib/i18n';

/** The cards' grid: as many 300px columns as fit (docs/architecture/design-system.md, section 5). */
const GRID = {
    '--hub-cols': 'repeat(auto-fill, minmax(min(300px, 100%), 1fr))',
} as CSSProperties;

const CARD =
    'bg-surface shadow-raised hover:bg-surface-2 focus-visible:outline-ring flex h-full items-start gap-4 rounded-lg p-5 transition-[background-color] duration-(--dur-1) ease-(--ease) focus-visible:outline-2 focus-visible:outline-offset-2';

/**
 * A clickable card of a hub (docs/architecture/design-system.md,
 * section 4.28): an icon tile, the name, one line, and the plugin's mark
 * when a plugin added it. A link to another site opens in a new tab.
 */
export function HubCard({
    href,
    external = false,
    icon,
    title,
    description,
    plugin,
    testId,
}: {
    href: string;
    external?: boolean;
    icon: ComponentType<LucideProps>;
    title: string;
    description: ReactNode;
    plugin?: string;
    testId?: string;
}) {
    const body = (
        <>
            <IconTile icon={icon} />
            <span className="grid min-w-0 gap-1">
                <span className="text-body text-ink flex items-center gap-1 font-semibold">
                    {title}
                    {external && (
                        <>
                            <ExternalLink
                                aria-hidden="true"
                                className="text-ink-3 size-3.5"
                            />
                            <span className="sr-only">
                                {__('(opens in a new tab)')}
                            </span>
                        </>
                    )}
                </span>
                <span className="text-small text-ink-2">{description}</span>
                {plugin !== undefined && <PluginMark name={plugin} />}
            </span>
        </>
    );

    return external ? (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            data-test={testId}
            className={CARD}
        >
            {body}
        </a>
    ) : (
        <Link href={href} data-test={testId} className={CARD}>
            {body}
        </Link>
    );
}

/**
 * A hub (docs/architecture/design-system.md, section 5): the page header,
 * a grid of cards, then the cards plugins added under their own heading.
 */
export function HubPage({
    title,
    description,
    cards,
    pluginCards = [],
    titleAs,
}: {
    title: string;
    description?: ReactNode;
    cards: ReactNode[];
    pluginCards?: ReactNode[];
    titleAs?: 'h1' | 'div';
}) {
    return (
        <Page>
            <PageHeader title={title} description={description} as={titleAs} />
            <ul className="grid grid-cols-(--hub-cols) gap-4" style={GRID}>
                {cards.map((card, index) => (
                    <li key={index}>{card}</li>
                ))}
            </ul>
            {pluginCards.length > 0 && (
                <section aria-labelledby="hub-from-plugins" className="mt-8">
                    <h2
                        id="hub-from-plugins"
                        className="text-lead text-ink mb-4 font-semibold"
                    >
                        {__('From plugins')}
                    </h2>
                    <ul
                        className="grid grid-cols-(--hub-cols) gap-4"
                        style={GRID}
                    >
                        {pluginCards.map((card, index) => (
                            <li key={index}>{card}</li>
                        ))}
                    </ul>
                </section>
            )}
        </Page>
    );
}
