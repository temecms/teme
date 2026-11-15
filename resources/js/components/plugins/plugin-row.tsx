import { Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PluginIcon } from '@/components/ui/plugin-icon';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showSettings } from '@/routes/settings';
import type { PluginRowPage, PluginState } from '@/types';

/** A state's tone; the word is the server's, so color is never alone. */
const TONES: Record<PluginState, 'ok' | 'neutral' | 'warn' | 'danger'> = {
    enabled: 'ok',
    not_enabled: 'neutral',
    disabled: 'neutral',
    disabled_on_server: 'neutral',
    waiting: 'warn',
    some_parts_stopped: 'warn',
    stopped_by_teme: 'danger',
    cant_be_used: 'danger',
    code_gone: 'danger',
};

/** The reason's ink, following its state's tone. */
const REASON_INK = {
    ok: 'text-ink-2',
    neutral: 'text-ink-2',
    warn: 'text-warn-ink',
    danger: 'text-danger-ink',
} as const;

/** The one button a row offers: Enable, Review, Run database changes, or Disable. */
function RowAction({
    plugin,
    onAsk,
    onDisable,
    onMigrate,
}: {
    plugin: PluginRowPage;
    onAsk: (plugin: PluginRowPage, opener: HTMLElement) => void;
    onDisable: (plugin: PluginRowPage) => void;
    onMigrate: (plugin: PluginRowPage) => void;
}) {
    if (plugin.action === 'disable') {
        return (
            <Button
                type="button"
                variant="neutral"
                size="sm"
                data-test="plugin-disable"
                onClick={() => onDisable(plugin)}
            >
                {__('Disable')}
                <span className="sr-only"> {plugin.label}</span>
            </Button>
        );
    }

    if (plugin.action === 'migrate') {
        return (
            <Button
                type="button"
                variant="neutral"
                size="sm"
                data-test="plugin-migrate"
                onClick={() => onMigrate(plugin)}
            >
                {__('Run database changes')}
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
            variant="neutral"
            size="sm"
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

/** The ⋯ menu: Details and access, Settings, and Remove its data… when allowed. */
function RowMenu({
    plugin,
    onDetails,
    onRemoveData,
}: {
    plugin: PluginRowPage;
    onDetails: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
    onRemoveData: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    data-plugin-menu={plugin.name}
                    type="button"
                    variant="ghost"
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
                {plugin.canRemoveData && (
                    <DropdownMenuItem
                        data-test="plugin-remove-data"
                        variant="destructive"
                        onSelect={() =>
                            onRemoveData(
                                plugin,
                                document.querySelector<HTMLElement>(
                                    `[data-plugin-menu="${plugin.name}"]`,
                                ),
                            )
                        }
                    >
                        {__('Remove its data…')}
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * One plugin on the Plugins screen: its icon, its name, version and what it
 * does, who made it and its package, its state as a status pill with the
 * reason, then its one action and its ⋯ menu. Under 768px it stacks.
 */
export function PluginRow({
    plugin,
    onAsk,
    onDisable,
    onDetails,
    onMigrate,
    onRemoveData,
}: {
    plugin: PluginRowPage;
    onAsk: (plugin: PluginRowPage, opener: HTMLElement) => void;
    onDisable: (plugin: PluginRowPage) => void;
    onDetails: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
    onMigrate: (plugin: PluginRowPage) => void;
    onRemoveData: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
}) {
    const tone = TONES[plugin.state];

    return (
        <li
            data-test={`plugin-${plugin.code === '' ? plugin.name : plugin.code}`}
            className="border-line flex flex-wrap items-start gap-x-4 gap-y-3 rounded-md border-b px-3 py-3 last:border-b-0 md:flex-nowrap"
        >
            {/* Later (I-3405): the picture, once the manifest can declare one. */}
            <PluginIcon size={32} name={plugin.label} />
            <div className="grid min-w-0 flex-1 basis-0 gap-1">
                <p className="flex flex-wrap items-baseline gap-x-2">
                    <span className="text-body text-ink font-semibold">
                        {plugin.label}
                    </span>
                    {plugin.version !== '' && (
                        <span className="text-small text-ink-3 tabular-nums">
                            {plugin.version}
                        </span>
                    )}
                </p>
                {plugin.description !== '' && (
                    <p className="text-small text-ink-2">
                        {plugin.description}
                    </p>
                )}
                <p className="text-caption text-ink-3 flex flex-wrap gap-x-2">
                    {plugin.author !== '' && (
                        <span>
                            {__('By :author', { author: plugin.author })}
                        </span>
                    )}
                    <span className="font-mono break-all">{plugin.name}</span>
                </p>
                {plugin.reason !== '' && (
                    <p
                        data-test="plugin-reason"
                        className={cn('text-small', REASON_INK[tone])}
                    >
                        {plugin.reason}
                    </p>
                )}
            </div>
            <div className="shrink-0 max-md:w-full max-md:ps-12">
                <StatusPill tone={tone} data-test="plugin-state">
                    {plugin.badge}
                </StatusPill>
            </div>
            <div className="flex shrink-0 flex-wrap items-center gap-2 max-md:w-full max-md:ps-12">
                <RowAction
                    plugin={plugin}
                    onAsk={onAsk}
                    onDisable={onDisable}
                    onMigrate={onMigrate}
                />
                <RowMenu
                    plugin={plugin}
                    onDetails={onDetails}
                    onRemoveData={onRemoveData}
                />
            </div>
        </li>
    );
}
