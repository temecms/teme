import { Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { useInstaller } from '@/components/extensions/installer';
import { ListPage } from '@/components/patterns/list-page';
import { DesignDraftBar } from '@/components/themes/design-draft-bar';
import { SwitchWarning } from '@/components/themes/switch-warning';
import { ThemeList } from '@/components/themes/theme-list';
import { ThemePreviewPanel } from '@/components/themes/theme-preview-panel';
import { ThemeRegions } from '@/components/themes/theme-regions';
import { ThemeSettings } from '@/components/themes/theme-settings';
import { useDesignDraft } from '@/components/themes/use-design-draft';
import { Button } from '@/components/ui/button';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import { index as pluginsIndex } from '@/routes/plugins';
import { show as showSettings } from '@/routes/settings';
import { index as themesIndex } from '@/routes/themes';
import type { DesignPage } from '@/types';

/**
 * Site › Themes: the installed themes as cards, a theme's preview, the
 * regions each places, and under them the chosen theme's settings and menu
 * places (v10), which its card's Theme settings leads down to; the draft
 * they make waits here for Publish or Discard. Someone who may install also
 * adds, updates and removes themes here; their requests are listed on
 * Plugins (I-211).
 */
export default function Design(page: DesignPage) {
    const { errors: pageErrors } = usePage().props;
    const design = useDesignDraft(page);
    const siteCrumb = useSectionCrumb('site');
    const title = __('Themes');

    setLayoutProps({
        breadcrumbs: [siteCrumb, { title, href: themesIndex() }],
    });
    const active = page.themes.find((theme) => theme.state === 'active');
    // The theme the draft or the live value names: the previewed one, else the active one.
    const chosen = page.themes.find((theme) => theme.code === page.theme);
    const previewError = pageErrors['groups.design.theme'];
    // A switch that hides the active theme's own cards asks first (docs/guides/developers/themes/regions.md).
    const [switching, setSwitching] = useState<{
        code: string;
        name: string;
    } | null>(null);
    const activate = (code: string) => {
        const target = page.themes.find((theme) => theme.code === code);

        if (
            page.hiddenCards.length > 0 &&
            target !== undefined &&
            target.code !== active?.code
        ) {
            setSwitching({ code: target.code, name: target.name });

            return;
        }

        void design.activateTheme(code);
    };
    const installer = useInstaller(
        page.extensions,
        'theme',
        page.themes.flatMap((theme) =>
            theme.origin === 'extensions' && theme.package !== null
                ? [{ package: theme.package, version: theme.version }]
                : [],
        ),
    );

    return (
        <ListPage
            title={title}
            description={__(
                'How the public site looks. A theme draws the site; it adds no features of its own.',
            )}
            actions={
                page.extensions !== null && (
                    <div className="flex flex-wrap gap-2">
                        <Button variant="neutral" asChild>
                            <Link
                                href={pluginsIndex({
                                    query: { tab: 'requests' },
                                })}
                                data-test="themes-requests-link"
                            >
                                {__('Install requests')}
                            </Link>
                        </Button>
                        <Button
                            type="button"
                            onClick={installer.openAdd}
                            data-test="themes-add"
                        >
                            <Plus aria-hidden="true" />
                            {__('Add theme')}
                        </Button>
                    </div>
                )
            }
        >
            <Head title={title} />

            <p
                className="text-small text-ink-2"
                role="status"
                aria-live="polite"
            >
                {design.saveState === 'saving' && __('Saving the draft…')}
                {design.saveState === 'saved' && __('Saved as a draft.')}
                {design.saveState === 'failed' &&
                    __(
                        'The last change was not saved. Check the fields marked below.',
                    )}
            </p>

            <div className="flex flex-wrap items-center gap-3">
                <p className="text-small text-ink-2">
                    {__(
                        'The site’s name, tagline and brand colour stay when you change theme.',
                    )}
                </p>
                <Button variant="neutral" size="sm" asChild>
                    <Link href={showSettings({ group: 'general' })}>
                        {__('Open Settings › General')}
                    </Link>
                </Button>
            </div>

            {page.preview !== null && (
                <ThemePreviewPanel
                    preview={page.preview}
                    activeName={active?.name ?? null}
                    unbound={design.unbound}
                    onOpenSite={() => void design.preview('site')}
                    onStop={() =>
                        page.preview !== null &&
                        design.stopPreview(page.preview.code)
                    }
                    onActivate={() =>
                        page.preview !== null && activate(page.preview.code)
                    }
                    busy={design.activating}
                    colourError={
                        design.pressed === null ? previewError : undefined
                    }
                    themeError={pageErrors.theme}
                >
                    {page.previewRegions !== null && (
                        <ThemeRegions
                            regions={page.previewRegions}
                            level={3}
                            intro={
                                active === undefined
                                    ? 'Regions are filled in Content › Regions.'
                                    : `Regions are filled in Content › Regions. What ${active.name} places stays as it is, listed under From other themes, and comes back with ${active.name}.`
                            }
                            unplacedLine={`What ${page.preview.name} would leave unplaced: kept as it is, and placed again when its theme is in use.`}
                            canEdit={false}
                            idPrefix="theme-preview-regions"
                        />
                    )}
                </ThemePreviewPanel>
            )}

            <ThemeList
                themes={page.themes}
                settingsOf={
                    chosen !== undefined && chosen.usable ? chosen.code : null
                }
                error={
                    previewError !== undefined && design.pressed !== null
                        ? { code: design.pressed, message: previewError }
                        : null
                }
                onPreview={(code) => void design.previewTheme(code)}
                onActivate={activate}
                activating={design.activating}
                onChange={
                    page.extensions === null ? undefined : installer.openChange
                }
            />

            <ThemeRegions
                regions={page.regions}
                level={2}
                unplacedLine="Kept from themes not in use, as they were. Each is placed again when its theme is in use."
                canEdit={page.canEditRegions}
                idPrefix="theme-regions"
            />

            {chosen !== undefined && chosen.usable && (
                <ThemeSettings page={page} design={design} name={chosen.name} />
            )}

            <SwitchWarning
                hidden={page.hiddenCards}
                leaving={active?.name ?? ''}
                target={switching}
                onCancel={() => setSwitching(null)}
                onConfirm={(code) => {
                    setSwitching(null);
                    void design.activateTheme(code);
                }}
            />

            {design.draft !== null && page.preview === null && (
                <DesignDraftBar
                    draft={design.draft}
                    themeError={pageErrors.theme}
                    onPublish={() => design.finish('publish')}
                    onDiscard={() => design.finish('discard')}
                />
            )}
            {installer.sheets}
        </ListPage>
    );
}
