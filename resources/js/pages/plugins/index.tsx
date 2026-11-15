import { Head, router, setLayoutProps } from '@inertiajs/react';
import { Plug } from 'lucide-react';
import { useEffect, useState } from 'react';
import PluginsController from '@/actions/App/Http/Admin/PluginsController';
import { ListPage } from '@/components/patterns/list-page';
import { ConsentDialog } from '@/components/plugins/consent-dialog';
import { DetailsDialog } from '@/components/plugins/details-dialog';
import { PluginRow } from '@/components/plugins/plugin-row';
import { Panel } from '@/components/ui/panel';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { __ } from '@/lib/i18n';
import { index as pluginsIndex } from '@/routes/plugins';
import type { PluginEnablePost, PluginRowPage, PluginsPage } from '@/types';

type Tab = 'all' | 'enabled' | 'disabled' | 'attention';

/** The tabs, in order, and the plugins each one holds. */
const TABS: {
    key: Tab;
    holds: (p: PluginRowPage) => boolean;
}[] = [
    { key: 'all', holds: () => true },
    {
        key: 'enabled',
        holds: (p) => p.state === 'enabled' || p.state === 'some_parts_stopped',
    },
    {
        key: 'disabled',
        holds: (p) =>
            p.state === 'not_enabled' ||
            p.state === 'disabled' ||
            p.state === 'disabled_on_server' ||
            p.state === 'stopped_by_teme',
    },
    {
        key: 'attention',
        holds: (p) => p.needsAttention,
    },
];

/** A tab's label, looked up while the page draws, never where the module loads. */
function tabLabel(key: Tab): string {
    switch (key) {
        case 'all':
            return __('All');
        case 'enabled':
            return __('Enabled');
        case 'disabled':
            return __('Disabled');
        case 'attention':
            return __('Needs attention');
    }
}

/** Where an enable request waits while the person types their password on Fortify's screen. */
const PENDING_KEY = 'teme.plugins.pending';

/** A request is carried on only if it was asked for in the last ten minutes. */
const PENDING_MINUTES = 10;

type Pending = { code: string; post: PluginEnablePost; at: number };

/** Storage may be missing or refuse; the page then works as it does with no marker. */
function readPending(): Pending | null {
    try {
        const raw = sessionStorage.getItem(PENDING_KEY);

        return raw === null ? null : (JSON.parse(raw) as Pending);
    } catch {
        return null;
    }
}

function writePending(code: string, post: PluginEnablePost): void {
    try {
        sessionStorage.setItem(
            PENDING_KEY,
            JSON.stringify({ code, post, at: Date.now() }),
        );
    } catch {
        // The person presses the button again, as without a marker.
    }
}

function removePending(): void {
    try {
        sessionStorage.removeItem(PENDING_KEY);
    } catch {
        // Nothing to remove.
    }
}

/**
 * Site › Plugins: every plugin with its state and why, enabled through a
 * consent dialog and disabled with one click. Enabling posts plainly; without
 * a recent password check the server sends the person to the password screen
 * and back, and the page then makes the request again from a marker kept in
 * sessionStorage (I-3342).
 */
export default function Plugins({ plugins }: PluginsPage) {
    const [tab, setTab] = useState<Tab>('all');
    const [asking, setAsking] = useState<PluginRowPage | null>(null);
    const [details, setDetails] = useState<PluginRowPage | null>(null);
    // What opened a dialog, so closing it puts focus back there.
    const [opener, setOpener] = useState<HTMLElement | null>(null);

    setLayoutProps({
        breadcrumbs: [{ title: __('Plugins'), href: pluginsIndex() }],
    });

    // Closing a dialog gives focus back to the button that opened it.
    useEffect(() => {
        if (asking === null && details === null && opener !== null) {
            opener.focus();
        }
    }, [asking, details, opener]);

    // Back from the password screen: the request is made again, once.
    useEffect(() => {
        const pending = readPending();

        if (pending === null) {
            return;
        }

        if (Date.now() - pending.at > PENDING_MINUTES * 60_000) {
            removePending();

            return;
        }

        // Removed first, so a second mount can never send it twice.
        removePending();

        router.post(
            PluginsController.enable.url({ code: pending.code }),
            pending.post,
            {
                headers: { 'X-Teme-Ask-In-Place': '1' },
                onHttpException: (response) => {
                    if (response.status === 423) {
                        return false;
                    }
                },
            },
        );
    }, []);

    const enable = (plugin: PluginRowPage) => {
        setAsking(null);
        router.post(
            PluginsController.enable.url({ code: plugin.code }),
            plugin.shown,
            {
                onBefore: () => writePending(plugin.code, plugin.shown),
                onSuccess: (page) => {
                    // Straight through: nothing is pending.
                    if (page.component === 'plugins/index') {
                        removePending();
                    }
                },
            },
        );
    };

    const disable = (plugin: PluginRowPage) => {
        router.post(PluginsController.disable.url({ code: plugin.code }), {});
    };

    const onAsk = (row: PluginRowPage, button: HTMLElement) => {
        setOpener(button);
        setAsking(row);
    };

    const onDetails = (row: PluginRowPage, button: HTMLElement | null) => {
        setOpener(button);
        setDetails(row);
    };

    return (
        <>
            <Head title={__('Plugins')} />

            <ListPage
                title={__('Plugins')}
                description={__(
                    'Plugins are Composer packages, added to the site’s build by whoever runs the server. Plugins don’t run on this page, so you can always disable one here.',
                )}
            >
                {plugins.length === 0 ? (
                    <Panel
                        data-test="plugins-empty"
                        className="grid justify-items-center gap-3 px-6 py-12 text-center"
                    >
                        <Plug
                            aria-hidden="true"
                            className="text-ink-3 size-8"
                        />
                        <p className="text-lead text-ink font-semibold">
                            {__('No plugins are installed')}
                        </p>
                        <p className="text-body text-ink-2">
                            {__(
                                'Whoever runs the server adds a plugin to the site’s build; it then appears here, disabled.',
                            )}
                        </p>
                    </Panel>
                ) : (
                    <Tabs
                        value={tab}
                        onValueChange={(value) => setTab(value as Tab)}
                    >
                        <TabsList
                            aria-label={__('Plugins')}
                            className="flex-wrap"
                        >
                            {TABS.map((item) => (
                                <TabsTrigger
                                    key={item.key}
                                    value={item.key}
                                    count={plugins.filter(item.holds).length}
                                    data-test={`plugins-tab-${item.key}`}
                                >
                                    {tabLabel(item.key)}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        {TABS.map((item) => {
                            const shown = plugins.filter(item.holds);

                            return (
                                <TabsContent key={item.key} value={item.key}>
                                    <Panel className="p-1">
                                        {shown.length === 0 ? (
                                            <p className="text-ink-3 px-3 py-3">
                                                {__('Nothing in this view')}
                                            </p>
                                        ) : (
                                            <ul data-test="plugins-list">
                                                {shown.map((plugin) => (
                                                    <PluginRow
                                                        key={plugin.name}
                                                        plugin={plugin}
                                                        onAsk={onAsk}
                                                        onDisable={disable}
                                                        onDetails={onDetails}
                                                    />
                                                ))}
                                            </ul>
                                        )}
                                    </Panel>
                                </TabsContent>
                            );
                        })}
                    </Tabs>
                )}

                <ConsentDialog
                    plugin={asking}
                    onClose={() => setAsking(null)}
                    onConfirm={enable}
                />
                <DetailsDialog
                    plugin={details}
                    onClose={() => setDetails(null)}
                />
            </ListPage>
        </>
    );
}
