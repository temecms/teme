import { Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import type { CSSProperties } from 'react';
import type { Change } from '@/components/extensions/installer';
import ThemesController from '@/actions/App/Http/Admin/ThemesController';
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
import { TextButton } from '@/components/ui/text-button';
import { __, __n } from '@/lib/i18n';
import { index as navigationIndex } from '@/routes/navigation';
import { index as regionsIndex } from '@/routes/regions';
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

/** Four cards across at 1440 with the admin shell; one on a phone. */
const COLUMNS = {
    '--theme-cols': 'repeat(auto-fill, minmax(min(100%, 240px), 1fr))',
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

/** Version and maker line on every card, from the theme's manifest. */
function VersionLine({ version, author }: { version: string; author: string }) {
    if (version === '' && author === '') {
        return null;
    }

    if (version === '') {
        return (
            <p className="text-caption text-ink-3" data-test="theme-origin">
                {author}
            </p>
        );
    }

    if (author === '') {
        return (
            <p className="text-caption text-ink-3" data-test="theme-origin">
                {__('Version :version', { version })}
            </p>
        );
    }

    return (
        <p className="text-caption text-ink-3" data-test="theme-origin">
            {__('Version :version · :maker', { version, maker: author })}
        </p>
    );
}

/** Screenshot flush to the top of a card, 3:2. */
function ThemePicture({
    theme,
    className,
}: {
    theme: DesignTheme;
    className?: string;
}) {
    if (theme.screenshot === null) {
        return (
            <div
                className={
                    className ??
                    'bg-surface-3 aspect-[3/2] w-full object-cover object-top'
                }
                aria-hidden="true"
            />
        );
    }

    return (
        <img
            src={theme.screenshot}
            alt=""
            className={
                className ??
                'bg-surface-3 aspect-[3/2] w-full object-cover object-top'
            }
        />
    );
}

/** One of the other installed themes in the grid. */
function OtherCard({
    theme,
    settingsOf,
    error,
    onPreview,
    onActivate,
    activating,
    onChange,
}: {
    theme: DesignTheme;
    settingsOf: string | null;
    error: { code: string; message: string } | null;
    onPreview: (code: string) => void;
    onActivate: (code: string) => void;
    activating: boolean;
    onChange?: (change: Change) => void;
}) {
    return (
        <li data-test={`theme-${theme.code}`}>
            <Panel className="flex h-full flex-col overflow-hidden p-0">
                <ThemePicture theme={theme} />
                <div className="text-small grid min-w-0 flex-1 content-start gap-1 p-4">
                    <p className="flex flex-wrap items-center gap-2">
                        <span className="text-body min-w-0 flex-1 font-semibold">
                            {theme.name}
                        </span>
                        <StatusPill
                            tone={STATES[theme.state].tone}
                            data-test="theme-state"
                            className="ms-auto"
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
                    <VersionLine
                        version={theme.version}
                        author={theme.author}
                    />
                    {theme.description !== '' && <p>{theme.description}</p>}
                    {(theme.author !== '' || theme.homepage !== '') && (
                        <p className="text-caption text-ink-3 flex flex-wrap gap-x-2">
                            {theme.author !== '' &&
                                (theme.authorUrl !== '' ? (
                                    <span>
                                        {__('By')}{' '}
                                        <TextButton asChild>
                                            <a
                                                href={theme.authorUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                data-test="theme-author-link"
                                            >
                                                {theme.author}
                                            </a>
                                        </TextButton>
                                    </span>
                                ) : (
                                    <span>
                                        {__('By :author', {
                                            author: theme.author,
                                        })}
                                    </span>
                                ))}
                            {theme.homepage !== '' && (
                                <TextButton asChild>
                                    <a
                                        href={theme.homepage}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        data-test="theme-homepage-link"
                                    >
                                        {__('Homepage')}
                                    </a>
                                </TextButton>
                            )}
                        </p>
                    )}
                    {theme.live && !theme.usable && (
                        <p>
                            {__(
                                'This is the site’s theme, but it can’t be used, so visitors see plain pages until it’s fixed or you activate another theme.',
                            )}
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
                        <div role="alert" className="grid gap-1">
                            <InputError message={error.message} />
                            <TextButton asChild>
                                <Link
                                    href={showSettings({
                                        group: 'general',
                                    })}
                                >
                                    {__(
                                        'Change the brand colour in Settings › General',
                                    )}
                                </Link>
                            </TextButton>
                        </div>
                    )}
                </div>
                {(theme.state === 'available' ||
                    theme.state === 'previewing' ||
                    theme.code === settingsOf) && (
                    <div className="flex flex-wrap gap-2 px-4 pb-4">
                        {theme.state === 'available' && (
                            <Button
                                type="button"
                                variant="neutral"
                                size="sm"
                                onClick={() => onPreview(theme.code)}
                                data-test="theme-preview"
                            >
                                {__('Preview')}
                                <span className="sr-only"> {theme.name}</span>
                            </Button>
                        )}
                        {theme.state === 'previewing' && (
                            <Button
                                type="button"
                                size="sm"
                                disabled={activating}
                                onClick={() => onActivate(theme.code)}
                                data-test="theme-card-activate"
                            >
                                {__('Activate :name', {
                                    name: theme.name,
                                })}
                            </Button>
                        )}
                        {theme.code === settingsOf && (
                            <Button variant="neutral" size="sm" asChild>
                                <Link
                                    href={ThemesController.settings.url({
                                        code: theme.code,
                                    })}
                                    data-test="theme-settings-link"
                                >
                                    {theme.state === 'previewing'
                                        ? __('Its settings')
                                        : __('Theme settings')}
                                </Link>
                            </Button>
                        )}
                    </div>
                )}
            </Panel>
        </li>
    );
}

/**
 * Site › Themes' installed themes: the active theme in a wide card of its
 * own, then the others in a grid of smaller cards (issue 714). Each has its
 * screenshot flush at the top, its state in a word, and what can be done
 * with it here.
 */
export function ThemeList({
    themes,
    settingsOf,
    regionCount,
    menuCount,
    error,
    onPreview,
    onPreviewSite,
    onActivate,
    activating,
    onChange,
}: {
    themes: DesignTheme[];
    /** The theme whose settings can be changed, so its card leads there. */
    settingsOf: string | null;
    /** How many regions the active theme places. */
    regionCount: number;
    /** How many menu places it declares; null while another theme is previewed. */
    menuCount: number | null;
    /** A refused Preview, shown under that theme's card. */
    error: { code: string; message: string } | null;
    onPreview: (code: string) => void;
    /** Opens the public site with the active theme (and any draft). */
    onPreviewSite: () => void;
    onActivate: (code: string) => void;
    /** Whether Activate is on its way. */
    activating: boolean;
    /** Asks for an update or a removal; only for someone who may install. */
    onChange?: (change: Change) => void;
}) {
    const active = themes.find((theme) => theme.state === 'active');
    const others = themes.filter((theme) => theme.state !== 'active');

    return (
        <div className="grid gap-6">
            {active !== undefined && (
                <section
                    aria-labelledby="active-theme-heading"
                    data-test={`theme-${active.code}`}
                >
                    <Panel className="overflow-hidden p-0">
                        <div className="grid lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                            <ThemePicture
                                theme={active}
                                className="bg-surface-3 aspect-[3/2] w-full object-cover object-top lg:aspect-auto lg:h-full lg:min-h-72"
                            />
                            <div className="grid content-start gap-4 p-6 max-sm:p-4">
                                <div className="grid gap-2">
                                    <StatusPill
                                        tone="ok"
                                        data-test="theme-state"
                                    >
                                        {__('Active on this site')}
                                    </StatusPill>
                                    <div className="flex flex-wrap items-start gap-2">
                                        <h2
                                            id="active-theme-heading"
                                            className="text-title text-ink font-semibold"
                                        >
                                            {active.name}
                                        </h2>
                                        {onChange !== undefined &&
                                            active.origin === 'extensions' &&
                                            active.package !== null && (
                                                <ThemeMenu
                                                    theme={active}
                                                    pkg={active.package}
                                                    onChange={onChange}
                                                />
                                            )}
                                    </div>
                                    <VersionLine
                                        version={active.version}
                                        author={active.author}
                                    />
                                    {(active.author !== '' ||
                                        active.homepage !== '') && (
                                        <p className="text-small text-ink-3 flex flex-wrap gap-x-2">
                                            {active.author !== '' &&
                                                (active.authorUrl !== '' ? (
                                                    <span>
                                                        {__('By')}{' '}
                                                        <TextButton asChild>
                                                            <a
                                                                href={
                                                                    active.authorUrl
                                                                }
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                data-test="theme-author-link"
                                                            >
                                                                {active.author}
                                                            </a>
                                                        </TextButton>
                                                    </span>
                                                ) : (
                                                    <span>
                                                        {__('By :author', {
                                                            author: active.author,
                                                        })}
                                                    </span>
                                                ))}
                                            {active.homepage !== '' && (
                                                <TextButton asChild>
                                                    <a
                                                        href={active.homepage}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        data-test="theme-homepage-link"
                                                    >
                                                        {__('Homepage')}
                                                    </a>
                                                </TextButton>
                                            )}
                                        </p>
                                    )}
                                    {active.description !== '' && (
                                        <p className="text-body text-ink-2">
                                            {active.description}
                                        </p>
                                    )}
                                    {active.errors.length > 0 && (
                                        <ul className="text-danger-ink text-small list-disc ps-5">
                                            {active.errors.map((message) => (
                                                <li key={message}>{message}</li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {active.code === settingsOf && (
                                        <Button
                                            asChild
                                            data-test="theme-settings-link"
                                        >
                                            <Link
                                                href={ThemesController.settings.url(
                                                    { code: active.code },
                                                )}
                                            >
                                                {__('Theme settings')}
                                            </Link>
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="neutral"
                                        onClick={onPreviewSite}
                                        data-test="theme-preview-site"
                                    >
                                        {__('Preview the site')}
                                    </Button>
                                </div>
                                <p className="text-small text-ink-2">
                                    <TextButton asChild>
                                        <Link
                                            href={regionsIndex().url}
                                            data-test="theme-regions-link"
                                        >
                                            {__n(
                                                ':count region|:count regions',
                                                regionCount,
                                            )}
                                        </Link>
                                    </TextButton>
                                    {menuCount !== null && (
                                        <>
                                            {' · '}
                                            <TextButton asChild>
                                                <Link
                                                    href={navigationIndex()}
                                                    data-test="theme-menus-link"
                                                >
                                                    {__n(
                                                        ':count menu place|:count menu places',
                                                        menuCount,
                                                    )}
                                                </Link>
                                            </TextButton>
                                        </>
                                    )}
                                </p>
                            </div>
                        </div>
                    </Panel>
                </section>
            )}

            <section aria-labelledby="installed-themes" className="grid gap-3">
                <h2 id="installed-themes" className="text-body font-semibold">
                    {others.length === 0
                        ? __('Other installed themes')
                        : __('Other installed themes · :count', {
                              count: String(others.length),
                          })}
                </h2>
                {themes.length === 0 ? (
                    <p className="text-small text-ink-2">
                        {__('No themes are installed.')}
                    </p>
                ) : others.length === 0 ? (
                    <p className="text-small text-ink-2">
                        {__('No other themes are installed.')}
                    </p>
                ) : (
                    <ul
                        data-test="theme-list"
                        className="grid grid-cols-(--theme-cols) gap-4"
                        style={COLUMNS}
                    >
                        {others.map((theme) => (
                            <OtherCard
                                key={theme.code}
                                theme={theme}
                                settingsOf={settingsOf}
                                error={error}
                                onPreview={onPreview}
                                onActivate={onActivate}
                                activating={activating}
                                onChange={onChange}
                            />
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
