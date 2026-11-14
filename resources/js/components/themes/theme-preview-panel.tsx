import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { show as showSettings } from '@/routes/settings';
import type { ThemePreview } from '@/types';

/**
 * The panel at the top of Site › Themes while a theme is previewed: whose
 * theme visitors still see, the site in preview, the draft's two ends,
 * Activate and Stop previewing, and the places the theme would show no menu
 * in (docs/studies/theme-layer-and-appearance.md).
 */
export function ThemePreviewPanel({
    preview,
    activeName,
    unbound,
    onOpenSite,
    onStop,
    onActivate,
    busy,
    colourError,
    themeError,
    children,
}: {
    preview: ThemePreview;
    /** The active theme's name, or null when the site has none it can use. */
    activeName: string | null;
    /** The labels of the previewed theme's places that show no menu yet. */
    unbound: string[];
    onOpenSite: () => void;
    onStop: () => void;
    onActivate: () => void;
    /** Whether Activate is on its way. */
    busy: boolean;
    /** Activate refused for the brand colour, in its own words. */
    colourError?: string;
    /** Activate refused for the theme itself. */
    themeError?: string;
    children?: ReactNode;
}) {
    return (
        <Notice
            tone="info"
            role="region"
            aria-labelledby="theme-preview-heading"
            data-test="theme-preview-panel"
        >
            <div className="grid gap-4">
                <div className="grid gap-1">
                    <h2
                        id="theme-preview-heading"
                        tabIndex={-1}
                        className="text-body font-semibold"
                    >
                        Previewing {preview.name} {preview.version}
                    </h2>
                    <p className="text-small text-ink-2">
                        {activeName === null
                            ? 'Visitors still see plain pages.'
                            : `Visitors still see ${activeName}.`}{' '}
                        Anyone who may change the design sees this preview until
                        it is activated or stopped.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        disabled={busy}
                        onClick={onActivate}
                        data-test="theme-activate"
                    >
                        Activate {preview.name}
                    </Button>
                    <Button
                        type="button"
                        variant="neutral"
                        size="sm"
                        onClick={onOpenSite}
                    >
                        Open the site in preview
                    </Button>
                    <Button
                        type="button"
                        variant="neutral"
                        size="sm"
                        onClick={onStop}
                        aria-describedby={
                            preview.changed ? 'theme-preview-drops' : undefined
                        }
                        data-test="theme-stop"
                    >
                        Stop previewing
                    </Button>
                </div>
                {(colourError !== undefined || themeError !== undefined) && (
                    <div role="alert" className="grid gap-1">
                        <InputError message={colourError ?? themeError} />
                        {colourError !== undefined && (
                            <Link
                                href={showSettings({ group: 'general' })}
                                className="text-small underline"
                            >
                                Change the brand colour in Settings › General
                            </Link>
                        )}
                    </div>
                )}
                {preview.changed && (
                    <p
                        id="theme-preview-drops"
                        className="text-small text-ink-2"
                    >
                        Stopping drops the changes made to {preview.name}’s
                        settings and menus.
                    </p>
                )}
                {unbound.length > 0 && (
                    <p className="text-small">
                        These places in {preview.name} show no menu yet:{' '}
                        {unbound.join(', ')}. Choose them under Menus below.
                    </p>
                )}
                {children}
            </div>
        </Notice>
    );
}
