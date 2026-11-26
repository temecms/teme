import { Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { MouseEvent } from 'react';
import ThemesController from '@/actions/App/Http/Admin/ThemesController';
import { FormPage } from '@/components/patterns/form-page';
import { DesignDraftBar } from '@/components/themes/design-draft-bar';
import { SwitchWarning } from '@/components/themes/switch-warning';
import { ThemePreviewPanel } from '@/components/themes/theme-preview-panel';
import { ThemeSettings } from '@/components/themes/theme-settings';
import { useDesignDraft } from '@/components/themes/use-design-draft';
import { Button } from '@/components/ui/button';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import { index as themesIndex } from '@/routes/themes';
import type { DesignSettingsPage } from '@/types';

/** One of the form's sections for the side list. */
type NavSection = { id: string; label: string };

/**
 * One theme's own settings page: crumbs Site / Themes / name, Back to
 * themes, a left list of sections like Settings, and the fields on the
 * right with the same draft bar as Themes (issue 714).
 */
export default function ThemeSettingsPage(page: DesignSettingsPage) {
    const { errors: pageErrors } = usePage().props;
    const design = useDesignDraft(page);
    const theme = page.themes.find((row) => row.code === page.theme);
    const name = theme?.name ?? page.theme;
    const active = page.themes.find((row) => row.state === 'active');
    const siteCrumb = useSectionCrumb('site');
    const [switching, setSwitching] = useState<{
        code: string;
        name: string;
    } | null>(null);

    setLayoutProps({
        breadcrumbs: [
            siteCrumb,
            { title: __('Themes'), href: themesIndex() },
            {
                title: name,
                href: ThemesController.settings.url({ code: page.theme }),
            },
        ],
    });

    const nav: NavSection[] = [];
    for (const section of [
        { key: 'site' as const, label: __('Site') },
        { key: 'latest' as const, label: __('Latest posts') },
        { key: 'content' as const, label: __('Content') },
        { key: 'archive' as const, label: __('Archive') },
    ]) {
        if (page.fieldsets[section.key].length > 0) {
            nav.push({
                id: `theme-settings-${section.key}`,
                label: section.label,
            });
        }
    }
    if (page.slots.length > 0) {
        nav.push({ id: 'theme-settings-menus', label: __('Menus') });
    }
    nav.push({ id: 'theme-settings-regions', label: __('Regions') });

    const navIds = nav.map((section) => section.id).join(' ');
    const [current, setCurrent] = useState(nav[0]?.id ?? '');

    useEffect(() => {
        if (nav.length === 0) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                const shown = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort(
                        (a, b) =>
                            a.boundingClientRect.top - b.boundingClientRect.top,
                    )[0];

                if (shown !== undefined) {
                    setCurrent(shown.target.id);
                }
            },
            { rootMargin: '0px 0px -66% 0px' },
        );

        for (const id of navIds.split(' ').filter(Boolean)) {
            const element = document.getElementById(id);

            if (element !== null) {
                observer.observe(element);
            }
        }

        return () => observer.disconnect();
    }, [navIds]);

    const jump = (event: MouseEvent<HTMLAnchorElement>, id: string) => {
        event.preventDefault();
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
        setCurrent(id);
    };

    const activate = (code: string) => {
        const target = page.themes.find((row) => row.code === code);

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

    return (
        <FormPage
            title={__(':name settings', { name })}
            description={__(
                'Changes are saved as a draft as you make them. Visitors see them once you publish.',
            )}
            actions={
                <Button variant="neutral" asChild>
                    <Link href={themesIndex()} data-test="theme-settings-back">
                        {__('Back to themes')}
                    </Link>
                </Button>
            }
        >
            <Head title={__(':name settings', { name })} />

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
                        design.pressed === null
                            ? pageErrors['groups.design.theme']
                            : undefined
                    }
                    themeError={pageErrors.theme}
                />
            )}

            {page.preview !== null && (
                <p className="text-small text-ink-2">
                    {__('Visitors see these once you activate :name.', {
                        name: page.preview.name,
                    })}
                </p>
            )}

            <div className="grid gap-8 lg:grid-cols-(--settings-cols) lg:gap-8">
                {nav.length > 0 && (
                    <nav
                        aria-label={__('Sections')}
                        className="hidden lg:block"
                    >
                        <ul className="grid gap-1">
                            {nav.map((section) => (
                                <li key={section.id}>
                                    <a
                                        href={`#${section.id}`}
                                        aria-current={
                                            current === section.id
                                                ? 'location'
                                                : undefined
                                        }
                                        onClick={(event) =>
                                            jump(event, section.id)
                                        }
                                        className="text-body text-ink-2 hover:bg-surface-3 hover:text-ink aria-[current=location]:bg-surface-3 aria-[current=location]:text-ink focus-visible:outline-ring inline-flex h-8 w-full items-center rounded-md px-3 font-medium focus-visible:outline-2 focus-visible:-outline-offset-2"
                                    >
                                        {section.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </nav>
                )}

                <ThemeSettings page={page} design={design} name={name} />
            </div>

            {design.draft !== null && page.preview === null && (
                <DesignDraftBar
                    draft={design.draft}
                    themeError={pageErrors.theme}
                    onPublish={() => design.finish('publish')}
                    onDiscard={() => design.finish('discard')}
                />
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
        </FormPage>
    );
}
