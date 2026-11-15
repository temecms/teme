import { Form, Head, Link, usePage } from '@inertiajs/react';
import { LayoutDashboard } from 'lucide-react';
import { useMemo, useState } from 'react';
import ContentListController from '@/actions/App/Http/Admin/Contents/ContentListController';
import { todayLine } from '@/components/dashboard/greeting';
import { GettingStarted } from '@/components/dashboard/getting-started';
import { sectionOf } from '@/components/dashboard/home-layout';
import { HomePanel } from '@/components/dashboard/home-panel';
import { PanelGrid } from '@/components/dashboard/panel-grid';
import { SiteFigures } from '@/components/dashboard/site-figures';
import { DashboardPage } from '@/components/patterns/dashboard-page';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index as mediaIndex } from '@/routes/media';
import type { DashboardPage as HomeProps } from '@/types';

/**
 * Home (I-161, I-210, I-3401): greeting and actions, the site's figures as
 * tiles, then Your work and From plugins in a grid of four sizes. Empty
 * panels stay out of the way outside Customize.
 */
export default function Dashboard({
    panels,
    catalogue,
    layout,
    figures,
    mainType,
    gettingStarted,
    canQuickDraft,
    canUpload,
    searchEngines,
}: HomeProps) {
    const { auth, i18n } = usePage().props;
    const [customizing, setCustomizing] = useState(false);

    const panelsByKey = useMemo(
        () => Object.fromEntries(panels.map((panel) => [panel.key, panel])),
        [panels],
    );

    const greetingLine = (() => {
        const hour = new Date().getHours();
        const first =
            auth.user.name.trim().split(/\s+/u).filter(Boolean)[0] ??
            auth.user.name;

        if (hour >= 5 && hour <= 11) {
            return __('Good morning, :name', { name: first });
        }

        if (hour >= 12 && hour <= 17) {
            return __('Good afternoon, :name', { name: first });
        }

        return __('Good evening, :name', { name: first });
    })();

    const create = mainType.canCreate && (
        <Form {...ContentListController.store.form({ type: mainType.segment })}>
            {({ processing }) => (
                <Button disabled={processing}>
                    {__('New :singular', { singular: mainType.singular })}
                </Button>
            )}
        </Form>
    );

    const actions = !customizing && (
        <>
            {create}
            {canUpload && (
                <Button variant="neutral" asChild>
                    <Link href={mediaIndex()}>{__('Upload media')}</Link>
                </Button>
            )}
            <Button
                variant="ghost"
                aria-pressed={customizing}
                onClick={() => setCustomizing(true)}
            >
                <LayoutDashboard aria-hidden="true" />
                {__('Customize')}
            </Button>
        </>
    );

    const shown = (section: 'core' | 'plugins') =>
        layout.order.filter((key) => {
            const entry = catalogue.find((item) => item.key === key);

            if (
                entry === undefined ||
                sectionOf(entry.owner) !== section ||
                layout.hidden.includes(key)
            ) {
                return false;
            }

            if (customizing) {
                return true;
            }

            if (entry.kind === 'quick-draft') {
                return canQuickDraft;
            }

            const panel = panelsByKey[key];

            return (
                panel !== undefined && (panel.rows.length > 0 || panel.failed)
            );
        });

    const coreKeys = shown('core');
    const pluginKeys = shown('plugins');
    const hasPluginCatalogue = catalogue.some(
        (entry) => sectionOf(entry.owner) === 'plugins',
    );
    // As today: the empty line is about rows panels; Quick draft still shows (I-161).
    const nothingWaiting =
        !customizing &&
        coreKeys.every((key) => {
            const entry = catalogue.find((item) => item.key === key);

            return entry?.kind !== 'rows';
        });

    return (
        <>
            <Head title={__('Home')} />
            <DashboardPage
                title={greetingLine}
                description={
                    <>
                        {todayLine(i18n.locale)}
                        {__(
                            " Your work first, then the site's figures and what plugins report.",
                        )}
                    </>
                }
                actions={actions}
            >
                {searchEngines !== null && (
                    <Notice
                        tone="warn"
                        data-test="home-search-engines"
                        className="mb-6"
                    >
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <p className="min-w-0 flex-1 basis-60">
                                <span className="font-medium">
                                    {searchEngines.message}
                                </span>{' '}
                                {searchEngines.detail}
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={searchEngines.actionUrl}>
                                    {searchEngines.actionLabel}
                                </Link>
                            </Button>
                        </div>
                    </Notice>
                )}

                {gettingStarted !== null && (
                    <div className="mb-6">
                        <GettingStarted steps={gettingStarted.steps} />
                    </div>
                )}

                <div className="mb-8">
                    <SiteFigures figures={figures} />
                </div>

                <section
                    aria-labelledby="home-your-work"
                    data-test="home-your-work"
                    className="mb-8"
                >
                    <h2
                        id="home-your-work"
                        className="text-lead text-ink mb-4 font-semibold"
                    >
                        {__('Your work')}
                    </h2>
                    {nothingWaiting && (
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <p className="text-small text-ink-2">
                                {__('Nothing needs you right now.')}
                            </p>
                            {create}
                        </div>
                    )}
                    {coreKeys.length > 0 && (
                        <PanelGrid>
                            {coreKeys.map((key) => {
                                const entry = catalogue.find(
                                    (item) => item.key === key,
                                )!;

                                return (
                                    <HomePanel
                                        key={key}
                                        entry={entry}
                                        panel={panelsByKey[key] ?? null}
                                        size={layout.sizes[key] ?? entry.size}
                                        customizing={customizing}
                                    />
                                );
                            })}
                        </PanelGrid>
                    )}
                </section>

                {hasPluginCatalogue && (
                    <section
                        aria-labelledby="home-from-plugins"
                        data-test="home-from-plugins"
                        className="mb-8"
                    >
                        <h2
                            id="home-from-plugins"
                            className="text-lead text-ink mb-1 font-semibold"
                        >
                            {__('From plugins')}
                        </h2>
                        <p className="text-small text-ink-2 mb-4">
                            {__(
                                'Panels that plugins add, each naming its plugin. Hide or move them in Customize.',
                            )}
                        </p>
                        {pluginKeys.length > 0 && (
                            <PanelGrid>
                                {pluginKeys.map((key) => {
                                    const entry = catalogue.find(
                                        (item) => item.key === key,
                                    )!;

                                    return (
                                        <HomePanel
                                            key={key}
                                            entry={entry}
                                            panel={panelsByKey[key] ?? null}
                                            size={
                                                layout.sizes[key] ?? entry.size
                                            }
                                            customizing={customizing}
                                        />
                                    );
                                })}
                            </PanelGrid>
                        )}
                    </section>
                )}
            </DashboardPage>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Home',
            href: dashboard(),
        },
    ],
};
