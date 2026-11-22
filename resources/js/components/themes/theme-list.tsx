import { Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import type { CSSProperties } from 'react';
import type { Change } from '@/components/extensions/installer';
import { originLabel } from '@/components/extensions/words';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Panel } from '@/components/ui/panel';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { show as showSettings } from '@/routes/settings';
import type { DesignTheme, ThemeState } from '@/types';

/** Each state's word and its pill's tone: a word and a mark, never colour alone. */
const STATES: Record<
    ThemeState,
    {
        label: string;
        tone: 'ok' | 'info' | 'neutral' | 'danger';
    }
> = {
    active: { label: 'Active', tone: 'ok' },
    previewing: { label: 'Previewing', tone: 'info' },
    available: { label: 'Available', tone: 'neutral' },
    incompatible: { label: 'Incompatible', tone: 'danger' },
    broken: { label: 'Broken', tone: 'danger' },
};

/** As many 300-wide cards as fit, one on a phone. */
const COLUMNS = {
    '--theme-cols': 'repeat(auto-fill, minmax(min(100%, 300px), 1fr))',
} as CSSProperties;

/** A theme card's ⋯ menu, for a theme installed here: Update… and Remove…. */
function ThemeMenu({
    theme,
    pkg,
    onChange,
}: {
    theme: DesignTheme;
    pkg: string;
    onChange: (change: Change) => void;
}) {
    const change = (action: Change['action']) =>
        onChange({
            action,
            kind: 'theme',
            package: pkg,
            label: theme.name,
            version: theme.version,
        });

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="ms-auto"
                    aria-label={__('Actions for :name', { name: theme.name })}
                    data-test="theme-menu"
                >
                    <MoreHorizontal aria-hidden="true" className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    data-test="theme-update"
                    onSelect={() => change('update')}
                >
                    {__('Update…')}
                </DropdownMenuItem>
                <DropdownMenuItem
                    data-test="theme-uninstall"
                    variant="destructive"
                    onSelect={() => change('remove')}
                >
                    {__('Remove…')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Site › Themes' installed themes, as cards: each with its screenshot, its
 * state in a word, what it is, and what can be done with it here: Preview an
 * available theme, Activate the previewed one, or open the settings of the
 * chosen one. Incompatible and broken themes list every error. Each card
 * says where its theme comes from; one installed here has Update… and
 * Remove… in its ⋯ menu, which ask the installer (I-211).
 */
export function ThemeList({
    themes,
    settingsOf,
    error,
    onPreview,
    onActivate,
    activating,
    onChange,
}: {
    themes: DesignTheme[];
    /** The theme whose settings can be changed, so its card leads down to them. */
    settingsOf: string | null;
    /** A refused Preview, shown under that theme's card. */
    error: { code: string; message: string } | null;
    onPreview: (code: string) => void;
    onActivate: (code: string) => void;
    /** Whether Activate is on its way. */
    activating: boolean;
    /** Asks for an update or a removal; only for someone who may install. */
    onChange?: (change: Change) => void;
}) {
    return (
        <section aria-labelledby="installed-themes" className="grid gap-3">
            <h2 id="installed-themes" className="text-body font-semibold">
                Installed themes
            </h2>
            {themes.length === 0 ? (
                <p className="text-small text-ink-2">
                    No themes are installed.
                </p>
            ) : (
                <ul
                    data-test="theme-list"
                    className="grid grid-cols-(--theme-cols) gap-4"
                    style={COLUMNS}
                >
                    {themes.map((theme) => (
                        <li key={theme.code} data-test={`theme-${theme.code}`}>
                            <Panel className="flex h-full flex-col gap-3 p-4">
                                {theme.screenshot !== null && (
                                    <img
                                        src={theme.screenshot}
                                        alt=""
                                        className="bg-surface-3 aspect-[4/3] w-full rounded-md object-cover object-top"
                                    />
                                )}
                                <div className="text-small grid min-w-0 flex-1 content-start gap-1">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <span className="text-body font-semibold">
                                            {theme.name}
                                        </span>
                                        {theme.version !== '' && (
                                            <span className="text-small text-ink-3">
                                                {theme.version}
                                            </span>
                                        )}
                                        <StatusPill
                                            tone={STATES[theme.state].tone}
                                            data-test="theme-state"
                                        >
                                            {STATES[theme.state].label}
                                        </StatusPill>
                                        {onChange !== undefined &&
                                            theme.origin === 'extensions' &&
                                            theme.package !== null && (
                                                <ThemeMenu
                                                    theme={theme}
                                                    pkg={theme.package}
                                                    onChange={onChange}
                                                />
                                            )}
                                    </p>
                                    <p
                                        className="text-caption text-ink-2"
                                        data-test="theme-origin"
                                    >
                                        {originLabel(
                                            theme.origin,
                                            theme.source,
                                        )}
                                    </p>
                                    {theme.description !== '' && (
                                        <p>{theme.description}</p>
                                    )}
                                    {(theme.author !== '' ||
                                        theme.homepage !== '') && (
                                        <p className="text-caption text-ink-3 flex flex-wrap gap-x-2">
                                            {theme.author !== '' &&
                                                (theme.authorUrl !== '' ? (
                                                    <span>
                                                        {__('By')}{' '}
                                                        <a
                                                            href={
                                                                theme.authorUrl
                                                            }
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="underline"
                                                            data-test="theme-author-link"
                                                        >
                                                            {theme.author}
                                                        </a>
                                                    </span>
                                                ) : (
                                                    <span>
                                                        {__('By :author', {
                                                            author: theme.author,
                                                        })}
                                                    </span>
                                                ))}
                                            {theme.homepage !== '' && (
                                                <a
                                                    href={theme.homepage}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="underline"
                                                    data-test="theme-homepage-link"
                                                >
                                                    {__('Homepage')}
                                                </a>
                                            )}
                                        </p>
                                    )}
                                    {theme.live && !theme.usable && (
                                        <p>
                                            This is the site’s theme, but it
                                            can’t be used, so visitors see plain
                                            pages until it’s fixed or you
                                            activate another theme.
                                        </p>
                                    )}
                                    {theme.errors.length > 0 && (
                                        <ul className="text-danger-ink list-disc ps-5">
                                            {theme.errors.map((message) => (
                                                <li key={message}>{message}</li>
                                            ))}
                                        </ul>
                                    )}
                                    {error?.code === theme.code && (
                                        <div
                                            role="alert"
                                            className="grid gap-1"
                                        >
                                            <InputError
                                                message={error.message}
                                            />
                                            <Link
                                                href={showSettings({
                                                    group: 'general',
                                                })}
                                                className="underline"
                                            >
                                                Change the brand colour in
                                                Settings › General
                                            </Link>
                                        </div>
                                    )}
                                </div>
                                {(theme.state === 'available' ||
                                    theme.state === 'previewing' ||
                                    theme.code === settingsOf) && (
                                    <div className="flex flex-wrap gap-2">
                                        {theme.state === 'available' && (
                                            <Button
                                                type="button"
                                                variant="neutral"
                                                size="sm"
                                                onClick={() =>
                                                    onPreview(theme.code)
                                                }
                                                data-test="theme-preview"
                                            >
                                                {__('Preview')}
                                                <span className="sr-only">
                                                    {' '}
                                                    {theme.name}
                                                </span>
                                            </Button>
                                        )}
                                        {theme.state === 'previewing' && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                disabled={activating}
                                                onClick={() =>
                                                    onActivate(theme.code)
                                                }
                                                data-test="theme-card-activate"
                                            >
                                                {__('Activate :name', {
                                                    name: theme.name,
                                                })}
                                            </Button>
                                        )}
                                        {theme.code === settingsOf && (
                                            <Button
                                                variant="neutral"
                                                size="sm"
                                                asChild
                                            >
                                                <a
                                                    href="#theme-settings"
                                                    data-test="theme-settings-link"
                                                >
                                                    {theme.state ===
                                                    'previewing'
                                                        ? __('Its settings')
                                                        : __('Theme settings')}
                                                </a>
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </Panel>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
