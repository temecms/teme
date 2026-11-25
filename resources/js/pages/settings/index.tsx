import { Head, setLayoutProps } from '@inertiajs/react';
import { HubCard, HubPage } from '@/components/patterns/hub-page';
import { __ } from '@/lib/i18n';
import { navIcon } from '@/lib/nav-icons';
import { index } from '@/routes/settings';

/** One Settings page's card, as the server lists it. */
type SettingsCard = {
    key: string;
    label: string;
    href: string;
    icon: string;
    line: string;
    heading: string;
    /** The plugin's name, for a page a plugin added. */
    plugin: string | null;
};

/**
 * The Settings hub: a card for each Settings page the person may open,
 * under "The site", "Writing and discussion" and "People and services",
 * then plugins' pages under "From plugins", each naming its plugin.
 */
export default function SettingsIndex({ cards }: { cards: SettingsCard[] }) {
    setLayoutProps({ breadcrumbs: [{ title: __('Settings'), href: index() }] });

    const headings = [...new Set(cards.map((card) => card.heading))];
    const plugins = __('From plugins');

    return (
        <>
            <Head title={__('Settings')} />

            <HubPage
                title={__('Settings')}
                description={__(
                    'How the site behaves. Each group of settings has its own page and its own permission.',
                )}
                sections={headings.map((heading) => ({
                    heading,
                    hint:
                        heading === plugins
                            ? __('Each names its plugin.')
                            : undefined,
                    cards: cards
                        .filter((card) => card.heading === heading)
                        .map((card) => (
                            <HubCard
                                key={card.key}
                                href={card.href}
                                icon={navIcon(card.icon)}
                                title={
                                    card.plugin !== null
                                        ? card.line
                                        : card.label
                                }
                                description={
                                    card.plugin !== null ? null : card.line
                                }
                                plugin={card.plugin ?? undefined}
                                testId={`settings-card-${card.key}`}
                            />
                        )),
                }))}
            />
        </>
    );
}
