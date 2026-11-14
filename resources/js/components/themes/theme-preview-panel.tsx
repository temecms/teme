import { Link } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
        <section
            aria-labelledby="theme-preview-heading"
            data-test="theme-preview-panel"
            className="border-ink grid gap-4 rounded border p-4"
        >
            <div className="grid gap-1">
                <h2
                    id="theme-preview-heading"
                    tabIndex={-1}
                    className="flex items-center gap-2 text-[15px] font-semibold"
                >
                    <Eye aria-hidden="true" className="size-4" />
                    Previewing {preview.name} {preview.version}
                </h2>
                <p className="text-ink-2 text-[13px]">
                    {activeName === null
                        ? 'Visitors still see plain pages.'
                        : `Visitors still see ${activeName}.`}{' '}
                    Anyone who may change the design sees this preview until it
                    is activated or stopped.
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
                    variant="outline"
                    size="sm"
                    onClick={onOpenSite}
                >
                    Open the site in preview
                </Button>
                <Button
                    type="button"
                    variant="outline"
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
                            className="text-[13px] underline"
                        >
                            Change the brand colour in Settings › General
                        </Link>
                    )}
                </div>
            )}
            {preview.changed && (
                <p id="theme-preview-drops" className="text-ink-2 text-[13px]">
                    Stopping drops the changes made to {preview.name}’s settings
                    and menus.
                </p>
            )}
            {unbound.length > 0 && (
                <p className="text-[13px]" role="note">
                    These places in {preview.name} show no menu yet:{' '}
                    {unbound.join(', ')}. Choose them under Menus below.
                </p>
            )}
            {children}
        </section>
    );
}
