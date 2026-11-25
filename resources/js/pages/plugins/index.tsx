import { Head, router, setLayoutProps } from '@inertiajs/react';
import { Plug, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import PluginsController from '@/actions/App/Http/Admin/PluginsController';
import { useInstaller } from '@/components/extensions/installer';
import { RequestsList } from '@/components/extensions/requests-list';
import { ListPage } from '@/components/patterns/list-page';
import { ConsentDialog } from '@/components/plugins/consent-dialog';
import { DetailsDialog } from '@/components/plugins/details-dialog';
import { ForgetDialog } from '@/components/plugins/forget-dialog';
import { PluginRow } from '@/components/plugins/plugin-row';
import { RemoveDataDialog } from '@/components/plugins/remove-data-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Panel } from '@/components/ui/panel';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { __ } from '@/lib/i18n';
import { index as pluginsIndex } from '@/routes/plugins';
import type { PluginEnablePost, PluginRowPage, PluginsPage } from '@/types';

type PluginTab = 'all' | 'enabled' | 'disabled' | 'attention';

/** The plugin views, then Install requests for someone who may install. */
type Tab = PluginTab | 'requests';

/** The tabs, in order, and the plugins each one holds. */
const TABS: {
    key: PluginTab;
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
function tabLabel(key: PluginTab): string {
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

/** The tab the address names: ?tab=requests opens Install requests, as Themes links to it. */
function firstTab(canInstall: boolean): Tab {
    if (!canInstall || typeof window === 'undefined') {
        return 'all';
    }

    return new URLSearchParams(window.location.search).get('tab') === 'requests'
        ? 'requests'
        : 'all';
}

/** Where a password-gated request waits while the person types their password. */
const PENDING_KEY = 'teme.plugins.pending';

/** A request is carried on only if it was asked for in the last ten minutes. */
const PENDING_MINUTES = 10;

type Pending =
    | { kind: 'enable'; code: string; post: PluginEnablePost; at: number }
    | {
          kind: 'migrate' | 'remove-data' | 'forget';
          code: string;
          name?: string;
          at: number;
      };

/** Storage may be missing or refuse; the page then works as it does with no marker. */
function readPending(): Pending | null {
    try {
        const raw = sessionStorage.getItem(PENDING_KEY);

        if (raw === null) {
            return null;
        }

        const parsed = JSON.parse(raw) as {
            kind?: string;
            code?: string;
            post?: PluginEnablePost;
            at?: number;
        };

        if (typeof parsed.code !== 'string' || typeof parsed.at !== 'number') {
            return null;
        }

        if (
            parsed.kind === 'migrate' ||
            parsed.kind === 'remove-data' ||
            parsed.kind === 'forget'
        ) {
            return {
                kind: parsed.kind,
                code: parsed.code,
                name:
                    typeof (parsed as { name?: string }).name === 'string'
                        ? (parsed as { name?: string }).name
                        : undefined,
                at: parsed.at,
            };
        }

        // A saved sign-in marker with no kind is enable when it has a post.
        if (
            (parsed.kind === 'enable' || parsed.kind === undefined) &&
            parsed.post !== undefined
        ) {
            return {
                kind: 'enable',
                code: parsed.code,
                post: parsed.post,
                at: parsed.at,
            };
        }

        return null;
    } catch {
        return null;
    }
}

function writePending(pending: Pending): void {
    try {
        sessionStorage.setItem(PENDING_KEY, JSON.stringify(pending));
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

function pendingUrl(pending: Pending): string {
    switch (pending.kind) {
        case 'enable':
            return PluginsController.enable.url({ code: pending.code });
        case 'migrate':
            return PluginsController.migrate.url({ code: pending.code });
        case 'remove-data':
            return PluginsController.removeData.url({ code: pending.code });
        case 'forget':
            return PluginsController.forget.url({ code: pending.code });
    }
}

/**
 * Site › Plugins: every plugin with its state and why, enabled through a
 * consent dialog, migrated or cleared of data after the password screen, and
 * disabled with one click (I-3353, I-3442, I-3342). Someone who may install
 * also adds, updates and removes plugins here, and follows every plugin and
 * theme request on Install requests (I-211).
 */
export default function Plugins({
    plugins,
    settingHosts,
    aiSite,
    extensions,
}: PluginsPage) {
    const [tab, setTab] = useState<Tab>(() => firstTab(extensions !== null));
    const installer = useInstaller(
        extensions,
        'plugin',
        plugins
            .filter((plugin) => plugin.origin === 'extensions')
            .map((plugin) => ({
                package: plugin.name,
                version: plugin.version,
            })),
    );
    const [asking, setAsking] = useState<PluginRowPage | null>(null);
    const [details, setDetails] = useState<PluginRowPage | null>(null);
    const [removing, setRemoving] = useState<PluginRowPage | null>(null);
    const [forgetting, setForgetting] = useState<PluginRowPage | null>(null);
    /** A disable refused because another enabled plugin needs it. */
    const [blocked, setBlocked] = useState<{
        plugin: PluginRowPage;
        dependents: PluginRowPage[];
    } | null>(null);
    // What opened a dialog, so closing it puts focus back there.
    const [opener, setOpener] = useState<HTMLElement | null>(null);

    setLayoutProps({
        breadcrumbs: [{ title: __('Plugins'), href: pluginsIndex() }],
    });

    // Closing a dialog gives focus back to the button that opened it.
    useEffect(() => {
        if (
            asking === null &&
            details === null &&
            removing === null &&
            forgetting === null &&
            opener !== null
        ) {
            opener.focus();
        }
    }, [asking, details, removing, forgetting, opener]);

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
            pendingUrl(pending),
            pending.kind === 'enable'
                ? pending.post
                : pending.kind === 'forget'
                  ? { name: pending.name ?? '' }
                  : {},
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
        // The hosts its settings name now, as the dialog showed them (docs/plans/plugin-sockets-1.md, A2).
        const post: PluginEnablePost = {
            ...plugin.shown,
            settingHosts: settingHosts[plugin.name]?.hosts ?? [],
        };
        router.post(PluginsController.enable.url({ code: plugin.code }), post, {
            onBefore: () =>
                writePending({
                    kind: 'enable',
                    code: plugin.code,
                    post,
                    at: Date.now(),
                }),
            onSuccess: (page) => {
                if (page.component === 'plugins/index') {
                    removePending();
                }
            },
        });
    };

    const migrate = (plugin: PluginRowPage) => {
        router.post(
            PluginsController.migrate.url({ code: plugin.code }),
            {},
            {
                onBefore: () =>
                    writePending({
                        kind: 'migrate',
                        code: plugin.code,
                        at: Date.now(),
                    }),
                onSuccess: (page) => {
                    if (page.component === 'plugins/index') {
                        removePending();
                    }
                },
            },
        );
    };

    const removeData = (plugin: PluginRowPage) => {
        setRemoving(null);
        router.post(
            PluginsController.removeData.url({ code: plugin.code }),
            {},
            {
                onBefore: () =>
                    writePending({
                        kind: 'remove-data',
                        code: plugin.code,
                        at: Date.now(),
                    }),
                onSuccess: (page) => {
                    if (page.component === 'plugins/index') {
                        removePending();
                    }
                },
            },
        );
    };

    const forget = (plugin: PluginRowPage) => {
        setForgetting(null);
        router.post(
            PluginsController.forget.url({ code: plugin.code }),
            { name: plugin.name },
            {
                onBefore: () =>
                    writePending({
                        kind: 'forget',
                        code: plugin.code,
                        name: plugin.name,
                        at: Date.now(),
                    }),
                onSuccess: (page) => {
                    if (page.component === 'plugins/index') {
                        removePending();
                    }
                },
            },
        );
    };

    const disable = (plugin: PluginRowPage) => {
        const dependents = plugins.filter(
            (row) => row.enabled && plugin.requiredBy.includes(row.label),
        );

        if (dependents.length > 0) {
            setBlocked({ plugin, dependents });

            return;
        }

        router.post(PluginsController.disable.url({ code: plugin.code }), {});
    };

    const showPlugin = (label: string) => {
        // Dependents may sit on another tab; All always holds them.
        if (tab !== 'all') {
            setTab('all');
        }

        requestAnimationFrame(() => {
            const row = plugins.find((plugin) => plugin.label === label);
            const code =
                row?.code === '' || row === undefined ? row?.name : row.code;
            const target =
                code === undefined
                    ? null
                    : document.querySelector<HTMLElement>(
                          `[data-test="plugin-${code}"]`,
                      );

            target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target?.querySelector<HTMLElement>('button, a')?.focus();
        });
    };

    /** Whole translated phrases for one or several dependents. */
    const blockedCopy = (
        plugin: PluginRowPage,
        dependents: PluginRowPage[],
    ): { title: string; body: string } => {
        const title = __('Cannot disable :name', { name: plugin.label });

        if (dependents.length === 1) {
            const name = dependents[0].label;

            return {
                title,
                body: __(':name needs it. Disable :name first.', { name }),
            };
        }

        return {
            title,
            body: __(':names need it. Disable them first.', {
                names: dependents.map((row) => row.label).join(', '),
            }),
        };
    };

    const onAsk = (row: PluginRowPage, button: HTMLElement) => {
        setOpener(button);
        setAsking(row);
    };

    const onDetails = (row: PluginRowPage, button: HTMLElement | null) => {
        setOpener(button);
        setDetails(row);
    };

    const onRemoveData = (row: PluginRowPage, button: HTMLElement | null) => {
        setOpener(button);
        setRemoving(row);
    };

    const onForget = (row: PluginRowPage, button: HTMLElement | null) => {
        setOpener(button);
        setForgetting(row);
    };
    return (
        <>
            <Head title={__('Plugins')} />

            <ListPage
                title={__('Plugins')}
                description={__(
                    'Plugins are Composer packages, added to the site’s build by whoever runs the server. Plugins don’t run on this page, so you can always disable one here.',
                )}
                actions={
                    extensions !== null && (
                        <Button
                            type="button"
                            onClick={installer.openAdd}
                            data-test="plugins-add"
                        >
                            <Plus aria-hidden="true" />
                            {__('Add plugin')}
                        </Button>
                    )
                }
            >
                {plugins.length === 0 &&
                (extensions === null || extensions.requests.length === 0) ? (
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
                            {extensions === null
                                ? __(
                                      'Whoever runs the server adds a plugin to the site’s build; it then appears here, disabled.',
                                  )
                                : __(
                                      'Add one with Add plugin, or whoever runs the server adds one to the site’s build. It then appears here, disabled.',
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
                            {extensions !== null && (
                                <TabsTrigger
                                    value="requests"
                                    count={extensions.requests.length}
                                    data-test="plugins-tab-requests"
                                >
                                    {__('Install requests')}
                                </TabsTrigger>
                            )}
                        </TabsList>
                        {TABS.map((item) => {
                            const shown = plugins.filter(item.holds);

                            return (
                                <TabsContent key={item.key} value={item.key}>
                                    <Panel className="overflow-hidden p-0">
                                        {shown.length === 0 ? (
                                            <p className="text-ink-3 px-3 py-3">
                                                {__('Nothing in this view')}
                                            </p>
                                        ) : (
                                            <>
                                                <div className="bg-surface text-small text-ink-3 sticky top-0 z-10 flex h-11 items-center gap-4 px-4 font-medium">
                                                    <span
                                                        className="size-14 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        {__('Plugin')}
                                                    </span>
                                                    <span className="w-28 shrink-0 max-md:hidden">
                                                        {__('State')}
                                                    </span>
                                                    <span
                                                        className="w-40 shrink-0 max-md:hidden"
                                                        aria-hidden="true"
                                                    />
                                                </div>
                                                <ul data-test="plugins-list">
                                                    {shown.map((plugin) => (
                                                        <PluginRow
                                                            key={plugin.name}
                                                            plugin={plugin}
                                                            onAsk={onAsk}
                                                            onDisable={disable}
                                                            onDetails={
                                                                onDetails
                                                            }
                                                            onMigrate={migrate}
                                                            onRemoveData={
                                                                onRemoveData
                                                            }
                                                            onChange={
                                                                extensions ===
                                                                null
                                                                    ? undefined
                                                                    : installer.openChange
                                                            }
                                                            onForget={onForget}
                                                            onShowPlugin={
                                                                showPlugin
                                                            }
                                                        />
                                                    ))}
                                                </ul>
                                            </>
                                        )}
                                    </Panel>
                                </TabsContent>
                            );
                        })}
                        {extensions !== null && (
                            <TabsContent value="requests">
                                <RequestsList
                                    data={extensions}
                                    onOpen={installer.openRequest}
                                />
                            </TabsContent>
                        )}
                    </Tabs>
                )}

                <ConsentDialog
                    plugin={asking}
                    settingHosts={
                        asking === null
                            ? null
                            : (settingHosts[asking.name] ?? null)
                    }
                    aiSite={aiSite}
                    onClose={() => setAsking(null)}
                    onConfirm={enable}
                />
                <DetailsDialog
                    plugin={details}
                    onClose={() => setDetails(null)}
                />
                <RemoveDataDialog
                    plugin={removing}
                    onClose={() => setRemoving(null)}
                    onConfirm={removeData}
                />
                {installer.sheets}
                <ForgetDialog
                    plugin={forgetting}
                    onClose={() => setForgetting(null)}
                    onConfirm={forget}
                />
                <Dialog
                    open={blocked !== null}
                    onOpenChange={(next) => !next && setBlocked(null)}
                >
                    {blocked !== null && (
                        <DialogContent
                            data-test="plugin-disable-blocked"
                            size="sm"
                        >
                            <DialogHeader>
                                <DialogTitle>
                                    {
                                        blockedCopy(
                                            blocked.plugin,
                                            blocked.dependents,
                                        ).title
                                    }
                                </DialogTitle>
                                <DialogDescription>
                                    {
                                        blockedCopy(
                                            blocked.plugin,
                                            blocked.dependents,
                                        ).body
                                    }
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="neutral">
                                        {__('Close')}
                                    </Button>
                                </DialogClose>
                                {blocked.dependents.map((row) => (
                                    <Button
                                        key={row.name}
                                        type="button"
                                        variant="neutral"
                                        data-test="plugin-show-dependent"
                                        onClick={() => {
                                            setBlocked(null);
                                            showPlugin(row.label);
                                        }}
                                    >
                                        {__('Show :name', { name: row.label })}
                                    </Button>
                                ))}
                            </DialogFooter>
                        </DialogContent>
                    )}
                </Dialog>
            </ListPage>
        </>
    );
}
