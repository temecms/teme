import { Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show as showSettings } from '@/routes/settings';
import type { DesignTheme, ThemeState } from '@/types';

/** Each state's word and how its chip is drawn: a tint, never colour alone. */
const STATES: Record<
    ThemeState,
    {
        label: string;
        variant: 'success' | 'secondary' | 'outline' | 'destructive';
    }
> = {
    active: { label: 'Active', variant: 'success' },
    previewing: { label: 'Previewing', variant: 'outline' },
    available: { label: 'Available', variant: 'secondary' },
    incompatible: { label: 'Incompatible', variant: 'destructive' },
    broken: { label: 'Broken', variant: 'destructive' },
};

/**
 * Site › Themes' installed themes: each with its screenshot, its state in a
 * word, what it is, and what can be done with it here: Preview an available
 * theme, or go to the settings the screen shows. Incompatible and broken
 * themes list every error; installing and removing are the installer's (I-211).
 */
export function ThemeList({
    themes,
    settingsOf,
    error,
    onPreview,
}: {
    themes: DesignTheme[];
    /** The theme whose settings the screen shows, so its row links to them. */
    settingsOf: string | null;
    /** A refused Preview, shown under that theme's row. */
    error: { code: string; message: string } | null;
    onPreview: (code: string) => void;
}) {
    return (
        <section aria-labelledby="installed-themes" className="grid gap-3">
            <h2 id="installed-themes" className="text-[15px] font-semibold">
                Installed themes
            </h2>
            {themes.length === 0 ? (
                <p className="text-ink-2 text-[13px]">
                    No themes are installed.
                </p>
            ) : (
                <ul
                    data-test="theme-list"
                    className="border-line divide-line divide-y rounded border"
                >
                    {themes.map((theme) => (
                        <li
                            key={theme.code}
                            data-test={`theme-${theme.code}`}
                            className="flex flex-wrap items-start gap-4 p-4"
                        >
                            {theme.screenshot !== null && (
                                <img
                                    src={theme.screenshot}
                                    alt=""
                                    className="border-line aspect-[4/3] w-28 shrink-0 border object-cover object-top"
                                />
                            )}
                            <div className="grid min-w-0 flex-1 gap-1 text-[13px]">
                                <p className="flex flex-wrap items-center gap-2">
                                    <span className="font-semibold">
                                        {theme.name}
                                    </span>
                                    {theme.version !== '' && (
                                        <span className="text-ink-2 font-mono text-xs">
                                            {theme.version}
                                        </span>
                                    )}
                                    <Badge
                                        variant={STATES[theme.state].variant}
                                        data-test="theme-state"
                                    >
                                        {STATES[theme.state].label}
                                    </Badge>
                                </p>
                                {theme.description !== '' && (
                                    <p>{theme.description}</p>
                                )}
                                {theme.live && !theme.usable && (
                                    <p>
                                        This is the site’s theme, but it can’t
                                        be used, so visitors see plain pages
                                        until it’s fixed or you activate another
                                        theme.
                                    </p>
                                )}
                                {theme.errors.length > 0 && (
                                    <ul className="text-danger-ink list-disc pl-5">
                                        {theme.errors.map((message) => (
                                            <li key={message}>{message}</li>
                                        ))}
                                    </ul>
                                )}
                                {error?.code === theme.code && (
                                    <div role="alert" className="grid gap-1">
                                        <InputError message={error.message} />
                                        <Link
                                            href={showSettings({
                                                group: 'general',
                                            })}
                                            className="underline"
                                        >
                                            Change the brand colour in Settings
                                            › General
                                        </Link>
                                    </div>
                                )}
                            </div>
                            {theme.state === 'available' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onPreview(theme.code)}
                                    data-test="theme-preview"
                                >
                                    Preview
                                    <span className="sr-only">
                                        {' '}
                                        {theme.name}
                                    </span>
                                </Button>
                            )}
                            {theme.code === settingsOf && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href="#theme-settings">Theme settings</a>
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
