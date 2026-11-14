import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { KeyboardEvent } from 'react';
import PluginsController from '@/actions/App/Http/Admin/PluginsController';
import { PageHeader } from '@/components/list/page-header';
import { ConsentDialog } from '@/components/plugins/consent-dialog';
import { DetailsDialog } from '@/components/plugins/details-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as pluginsIndex } from '@/routes/plugins';
import { show as showSettings } from '@/routes/settings';
import type {
    PluginEnablePost,
    PluginRowPage,
    PluginsPage,
    PluginState,
} from '@/types';

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

/** How each state's badge is tinted. The word is always there, so colour is never alone. */
const BADGES: Record<
    PluginState,
    'success' | 'secondary' | 'outline' | 'destructive'
> = {
    enabled: 'success',
    not_enabled: 'secondary',
    disabled: 'secondary',
    disabled_on_server: 'secondary',
    waiting: 'outline',
    some_parts_stopped: 'outline',
    stopped_by_teme: 'destructive',
    cant_be_used: 'destructive',
    code_gone: 'destructive',
};

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

/** The one button a row offers: Enable, Review access for its version, or Disable. */
function RowAction({
    plugin,
    onAsk,
    onDisable,
}: {
    plugin: PluginRowPage;
    onAsk: (plugin: PluginRowPage, opener: HTMLElement) => void;
    onDisable: (plugin: PluginRowPage) => void;
}) {
    if (plugin.action === 'disable') {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-test="plugin-disable"
                onClick={() => onDisable(plugin)}
            >
                {__('Disable')}
                <span className="sr-only"> {plugin.label}</span>
            </Button>
        );
    }

    if (plugin.action === null) {
        return null;
    }

    return (
        <Button
            type="button"
            size="sm"
            variant={plugin.action === 'review' ? 'outline' : 'default'}
            data-test="plugin-enable"
            onClick={(event) => onAsk(plugin, event.currentTarget)}
        >
            {plugin.action === 'review'
                ? __('Review access for :version', {
                      version: plugin.version,
                  })
                : __('Enable')}
            {plugin.action === 'enable' && (
                <span className="sr-only"> {plugin.label}</span>
            )}
        </Button>
    );
}

/** The ⋯ menu: Details and access, and Settings for a plugin that has them. */
function RowMenu({
    plugin,
    onDetails,
}: {
    plugin: PluginRowPage;
    onDetails: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    data-plugin-menu={plugin.name}
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-label={__('Actions for :name', { name: plugin.label })}
                    data-test="plugin-menu"
                >
                    <MoreHorizontal aria-hidden="true" className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    data-test="plugin-details"
                    onSelect={() =>
                        onDetails(
                            plugin,
                            document.querySelector<HTMLElement>(
                                `[data-plugin-menu="${plugin.name}"]`,
                            ),
                        )
                    }
                >
                    {__('Details and access')}
                </DropdownMenuItem>
                {plugin.hasSettings && (
                    <DropdownMenuItem asChild>
                        <Link
                            href={showSettings({
                                group: `plugin.${plugin.code}`,
                            })}
                            data-test="plugin-settings"
                        >
                            {__('Settings')}
                        </Link>
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
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

    const shown = plugins.filter(TABS.find((t) => t.key === tab)!.holds);

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

    const onTabKey = (event: KeyboardEvent<HTMLDivElement>) => {
        const index = TABS.findIndex((t) => t.key === tab);
        const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

        if (move === undefined) {
            return;
        }

        event.preventDefault();
        const next = TABS[(index + move + TABS.length) % TABS.length];
        setTab(next.key);
        document.getElementById(`plugins-tab-${next.key}`)?.focus();
    };

    return (
        <>
            <Head title={__('Plugins')} />

            <div className="max-w-5xl space-y-6 p-6">
                <PageHeader
                    title={__('Plugins')}
                    description={__(
                        'Plugins are Composer packages, added to the site’s build by whoever runs the server. Plugins don’t run on this page, so you can always disable one here.',
                    )}
                />

                {plugins.length === 0 ? (
                    <p
                        data-test="plugins-empty"
                        className="text-ink-2 text-[13px]"
                    >
                        {__(
                            'No plugins are installed. Whoever runs the server adds a plugin to the site’s build; it then appears here, disabled.',
                        )}
                    </p>
                ) : (
                    <>
                        <div
                            role="tablist"
                            aria-label={__('Plugins')}
                            className="border-line flex flex-wrap gap-x-5 border-b"
                            onKeyDown={onTabKey}
                        >
                            {TABS.map((item) => (
                                <button
                                    key={item.key}
                                    id={`plugins-tab-${item.key}`}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === item.key}
                                    aria-controls="plugins-panel"
                                    tabIndex={tab === item.key ? 0 : -1}
                                    data-test={`plugins-tab-${item.key}`}
                                    onClick={() => setTab(item.key)}
                                    className={cn(
                                        '-mb-px inline-flex h-9 items-center gap-1.5 border-b-2 px-0.5 text-[13px]',
                                        tab === item.key
                                            ? 'border-accent text-ink font-semibold'
                                            : 'text-ink-2 hover:text-ink border-transparent font-medium',
                                    )}
                                >
                                    {tabLabel(item.key)}
                                    <span className="text-ink-2 tabular-nums">
                                        {plugins.filter(item.holds).length}
                                    </span>
                                </button>
                            ))}
                        </div>

                        <div
                            id="plugins-panel"
                            role="tabpanel"
                            aria-labelledby={`plugins-tab-${tab}`}
                        >
                            {shown.length === 0 ? (
                                <p className="text-ink-2 text-[13px]">
                                    {__('Nothing in this view')}
                                </p>
                            ) : (
                                <table
                                    data-test="plugins-table"
                                    className="border-line w-full border-collapse text-left text-[13px] max-md:block"
                                >
                                    <thead className="max-md:sr-only">
                                        <tr className="border-line text-ink-2 border-b">
                                            <th
                                                scope="col"
                                                className="py-2 pr-4 font-medium"
                                            >
                                                {__('Plugin')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="py-2 pr-4 font-medium"
                                            >
                                                {__('State')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="py-2 font-medium"
                                            >
                                                {__('Actions')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="max-md:block">
                                        {shown.map((plugin) => (
                                            <tr
                                                key={plugin.name}
                                                data-test={`plugin-${plugin.code === '' ? plugin.name : plugin.code}`}
                                                className="border-line border-b align-top max-md:block max-md:py-3"
                                            >
                                                <td className="space-y-1 py-3 pr-4 max-md:block max-md:py-1">
                                                    <p className="flex flex-wrap items-baseline gap-2">
                                                        <span className="font-semibold">
                                                            {plugin.label}
                                                        </span>
                                                        {plugin.version !==
                                                            '' && (
                                                            <span className="text-ink-2 font-mono text-xs">
                                                                {plugin.version}
                                                            </span>
                                                        )}
                                                    </p>
                                                    {plugin.description !==
                                                        '' && (
                                                        <p>
                                                            {plugin.description}
                                                        </p>
                                                    )}
                                                    <p className="text-ink-2 font-mono text-xs break-all">
                                                        {plugin.name}
                                                    </p>
                                                </td>
                                                <td className="space-y-1 py-3 pr-4 max-md:block max-md:py-1">
                                                    <Badge
                                                        variant={
                                                            BADGES[plugin.state]
                                                        }
                                                        data-test="plugin-state"
                                                    >
                                                        {plugin.badge}
                                                    </Badge>
                                                    {plugin.reason !== '' && (
                                                        <p
                                                            data-test="plugin-reason"
                                                            className="text-ink-2 mt-1"
                                                        >
                                                            {plugin.reason}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="py-3 max-md:block max-md:py-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <RowAction
                                                            plugin={plugin}
                                                            onAsk={(
                                                                row,
                                                                button,
                                                            ) => {
                                                                setOpener(
                                                                    button,
                                                                );
                                                                setAsking(row);
                                                            }}
                                                            onDisable={disable}
                                                        />
                                                        <RowMenu
                                                            plugin={plugin}
                                                            onDetails={(
                                                                row,
                                                                button,
                                                            ) => {
                                                                setOpener(
                                                                    button,
                                                                );
                                                                setDetails(row);
                                                            }}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    </>
                )}
            </div>

            <ConsentDialog
                plugin={asking}
                onClose={() => setAsking(null)}
                onConfirm={enable}
            />
            <DetailsDialog plugin={details} onClose={() => setDetails(null)} />
        </>
    );
}
