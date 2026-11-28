import { Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import type { Change } from '@/components/extensions/installer';
import { ScriptSizeLine } from '@/components/plugins/script-size-line';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PluginIcon } from '@/components/ui/plugin-icon';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { formatCost } from '@/lib/ai-cost';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showSettings } from '@/routes/settings';
import type { PluginRowPage, PluginState } from '@/types';

/** A state's tone; the word is the server's, so colour is never alone. */
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
            variant="primary"
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

/**
 * The ⋯ menu: Details and access, Settings, Remove its data… and Forget…
 * when allowed, and Update… and Remove… for a plugin installed here.
 */
function RowMenu({
    plugin,
    onDetails,
    onRemoveData,
    onChange,
    onForget,
}: {
    plugin: PluginRowPage;
    onDetails: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
    onRemoveData: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
    onChange?: (change: Change) => void;
    onForget: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
}) {
    const change = (action: Change['action']) =>
        onChange?.({
            action,
            kind: 'plugin',
            package: plugin.name,
            label: plugin.label,
            version: plugin.version,
        });

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
                {onChange !== undefined && plugin.origin === 'extensions' && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            data-test="plugin-update"
                            onSelect={() => change('update')}
                        >
                            {__('Update…')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            data-test="plugin-uninstall"
                            variant="destructive"
                            onSelect={() => change('remove')}
                        >
                            {__('Remove…')}
                        </DropdownMenuItem>
                    </>
                )}
                {plugin.canForget && (
                    <DropdownMenuItem
                        data-test="plugin-forget"
                        variant="destructive"
                        onSelect={() =>
                            onForget(
                                plugin,
                                document.querySelector<HTMLElement>(
                                    `[data-plugin-menu="${plugin.name}"]`,
                                ),
                            )
                        }
                    >
                        {__('Forget…')}
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * One plugin on the Plugins screen as a table row: 56px icon, name at
 * text-lead, By · Version (with author link), state, action and ⋯.
 * Composer package name stays in Details only. Requires / Required by sit
 * under the row as short names.
 */
export function PluginRow({
    plugin,
    onAsk,
    onDisable,
    onDetails,
    onMigrate,
    onRemoveData,
    onChange,
    onForget,
    onShowPlugin,
}: {
    plugin: PluginRowPage;
    onAsk: (plugin: PluginRowPage, opener: HTMLElement) => void;
    onDisable: (plugin: PluginRowPage) => void;
    onDetails: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
    onMigrate: (plugin: PluginRowPage) => void;
    onRemoveData: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
    /** Asks for an update or a removal; only for someone who may install. */
    onChange?: (change: Change) => void;
    onForget: (plugin: PluginRowPage, opener: HTMLElement | null) => void;
    /** Scrolls to a dependent plugin named under the row. */
    onShowPlugin?: (label: string) => void;
}) {
    const tone = TONES[plugin.state];

    return (
        <li
            data-test={`plugin-${plugin.code === '' ? plugin.name : plugin.code}`}
            className="border-line hover:bg-surface-2 border-b last:border-b-0"
        >
            <div className="flex flex-wrap items-start gap-x-4 gap-y-3 px-4 py-3 md:flex-nowrap">
                <PluginIcon size={56} name={plugin.label} src={plugin.icon} />
                <div className="grid min-w-0 flex-1 basis-0 gap-1">
                    <p className="text-lead text-ink font-semibold">
                        {plugin.label}
                    </p>
                    <p className="text-small text-ink-3">
                        {plugin.author !== '' &&
                            (plugin.authorUrl !== '' ? (
                                <>
                                    {__('By')}{' '}
                                    <TextButton asChild>
                                        <a
                                            href={plugin.authorUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            data-test="plugin-author-link"
                                        >
                                            {plugin.author}
                                        </a>
                                    </TextButton>
                                </>
                            ) : (
                                __('By :author', { author: plugin.author })
                            ))}
                        {plugin.author !== '' && plugin.version !== '' && (
                            <>{' · '}</>
                        )}
                        {plugin.version !== '' &&
                            __('Version :version', {
                                version: plugin.version,
                            })}
                    </p>
                    {plugin.description !== '' && (
                        <p className="text-small text-ink-2">
                            {plugin.description}
                        </p>
                    )}
                    {plugin.ai !== null && (
                        <p
                            data-test="plugin-ai"
                            className="text-small text-ink-2"
                        >
                            {plugin.ai.budgetMicrounits === null
                                ? __('AI this month: :cost', {
                                      cost: formatCost(
                                          plugin.ai.costMicrounits,
                                      ),
                                  })
                                : __('AI this month: :cost of :budget', {
                                      cost: formatCost(
                                          plugin.ai.costMicrounits,
                                      ),
                                      budget: formatCost(
                                          plugin.ai.budgetMicrounits,
                                      ),
                                  })}
                        </p>
                    )}
                    {plugin.reason !== '' && (
                        <p
                            data-test="plugin-reason"
                            className={cn('text-small', REASON_INK[tone])}
                        >
                            {plugin.reason}
                        </p>
                    )}
                    {plugin.schedules !== null && (
                        <p
                            data-test="plugin-schedules"
                            className="text-small text-warn-ink"
                        >
                            {plugin.schedules}
                        </p>
                    )}
                    <ScriptSizeLine
                        scripts={plugin.scripts}
                        testId="plugin-scripts"
                    />
                    {(plugin.requires.length > 0 ||
                        plugin.requiredBy.length > 0) && (
                        <div className="text-small text-ink-2 grid gap-1 pt-1">
                            {plugin.requires.length > 0 && (
                                <p data-test="plugin-requires">
                                    {__('Requires :names', {
                                        names: plugin.requires
                                            .map((line) => line.words)
                                            .join(', '),
                                    })}
                                </p>
                            )}
                            {plugin.requiredBy.length > 0 && (
                                <p data-test="plugin-required-by">
                                    {__('Required by')}{' '}
                                    {plugin.requiredBy.map((label, index) => (
                                        <span key={label}>
                                            {index > 0 && ', '}
                                            {onShowPlugin !== undefined ? (
                                                <TextButton
                                                    type="button"
                                                    onClick={() =>
                                                        onShowPlugin(label)
                                                    }
                                                >
                                                    {label}
                                                </TextButton>
                                            ) : (
                                                label
                                            )}
                                        </span>
                                    ))}
                                </p>
                            )}
                        </div>
                    )}
                </div>
                <div
                    className="w-28 shrink-0 max-md:w-full max-md:ps-16"
                    data-test="plugin-state-cell"
                >
                    <StatusPill tone={tone} data-test="plugin-state">
                        {plugin.badge}
                    </StatusPill>
                </div>
                <div className="flex w-40 shrink-0 flex-wrap items-center justify-end gap-2 max-md:w-full max-md:justify-start max-md:ps-16">
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
                        onChange={onChange}
                        onForget={onForget}
                    />
                </div>
            </div>
        </li>
    );
}
