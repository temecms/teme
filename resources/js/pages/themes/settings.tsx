import { Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import ThemesController from '@/actions/App/Http/Admin/ThemesController';
import {
    DesignFieldInput,
    text,
    visible,
} from '@/components/themes/design-field';
import { EmptyState } from '@/components/list/empty-state';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import { DesignDraftBar } from '@/components/themes/design-draft-bar';
import { ThemePreviewPanel } from '@/components/themes/theme-preview-panel';
import { useDesignDraft } from '@/components/themes/use-design-draft';
import { Button } from '@/components/ui/button';

import { Field } from '@/components/ui/field';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { index as navigationIndex } from '@/routes/navigation';
import type { DesignFieldset, DesignSettingsPage } from '@/types';

/** The settings sections, in the order the page shows them (I-442). */
function sections(): { key: DesignFieldset; label: string }[] {
    return [
        { key: 'site', label: __('Site') },
        { key: 'latest', label: __('Latest posts') },
        { key: 'content', label: __('Content') },
        { key: 'archive', label: __('Archive') },
    ];
}

/**
 * Site › Themes › a theme's settings: the chosen theme's own settings and
 * menu places, the one previewed or else the one in use. Every change is
 * saved as a draft as it is made; each section previews its page on the
 * real site, and nothing reaches readers until Publish.
 */
export default function ThemeSettings(page: DesignSettingsPage) {
    const { errors: pageErrors } = usePage().props;
    const design = useDesignDraft(page);
    const chosen = page.themes.find((theme) => theme.code === page.theme);
    const active = page.themes.find((theme) => theme.state === 'active');
    const name = chosen?.name ?? page.theme;
    const themeGroup = page.themeGroup;
    const title = __(':name settings', { name });

    setLayoutProps({
        breadcrumbs: [
            { title: __('Themes'), href: ThemesController.index() },
            {
                title,
                href: ThemesController.settings({ code: page.theme }),
            },
        ],
    });

    return (
        <FormPage
            title={title}
            description={__(
                'Changes are saved as a draft as you make them. Visitors see them once you publish.',
            )}
        >
            <Head title={title} />

            <div className="grid gap-4">
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
                    <>
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
                                page.preview !== null &&
                                void design.activateTheme(page.preview.code)
                            }
                            busy={design.activating}
                            colourError={pageErrors['groups.design.theme']}
                            themeError={pageErrors.theme}
                        />
                        <p className="text-small text-ink-2">
                            {__('Visitors see these once you activate :name.', {
                                name: page.preview.name,
                            })}
                        </p>
                    </>
                )}

                {themeGroup === null ? (
                    <EmptyState
                        kind="nothing-yet"
                        title={__(
                            ':name has no settings or menu places of its own.',
                            { name },
                        )}
                    />
                ) : (
                    <>
                        {sections()
                            .filter(
                                (section) =>
                                    page.fieldsets[section.key].length > 0,
                            )
                            .map((section) => (
                                <FormPanel
                                    key={section.key}
                                    title={section.label}
                                >
                                    <div>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="neutral"
                                            onClick={() =>
                                                void design.preview(section.key)
                                            }
                                        >
                                            {__('Preview')}
                                            <span className="sr-only">
                                                {' '}
                                                {section.label}
                                            </span>
                                        </Button>
                                    </div>
                                    {page.fieldsets[section.key]
                                        .filter((field) =>
                                            visible(field, design.themeValues),
                                        )
                                        .map((field) => (
                                            <DesignFieldInput
                                                key={field.key}
                                                field={field}
                                                value={
                                                    design.themeValues[
                                                        field.key
                                                    ]
                                                }
                                                error={design.errorOf(
                                                    themeGroup,
                                                    field.key,
                                                )}
                                                onChange={(value) =>
                                                    design.change(
                                                        themeGroup,
                                                        field.key,
                                                        value,
                                                    )
                                                }
                                            />
                                        ))}
                                </FormPanel>
                            ))}

                        {page.slots.length > 0 && (
                            <div data-test="design-menus">
                                <FormPanel
                                    title={__('Menus')}
                                    description={
                                        <span className="grid justify-items-start gap-3">
                                            <span>
                                                {__(
                                                    'A menu you choose here shows once you publish, and replaces whatever Content › Navigation sets for that place. Places you don’t change here keep Navigation’s menu.',
                                                )}
                                            </span>
                                            <Button
                                                size="sm"
                                                variant="neutral"
                                                asChild
                                            >
                                                <Link href={navigationIndex()}>
                                                    {__('Edit menus')}
                                                </Link>
                                            </Button>
                                        </span>
                                    }
                                >
                                    {page.slots.map((slot) => (
                                        <Field
                                            key={slot.key}
                                            htmlFor={`design-${slot.key}`}
                                            label={slot.label}
                                            error={design.errorOf(
                                                themeGroup,
                                                slot.key,
                                            )}
                                        >
                                            <SelectField
                                                id={`design-${slot.key}`}
                                                value={text(
                                                    design.themeValues[
                                                        slot.key
                                                    ],
                                                )}
                                                emptyLabel={__('None')}
                                                options={page.menus.map(
                                                    (menu) => ({
                                                        value: menu.id,
                                                        label: menu.name,
                                                    }),
                                                )}
                                                onValueChange={(value) =>
                                                    design.change(
                                                        themeGroup,
                                                        slot.key,
                                                        value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    ))}
                                </FormPanel>
                            </div>
                        )}
                    </>
                )}

                {design.draft !== null && page.preview === null && (
                    <DesignDraftBar
                        draft={design.draft}
                        themeError={pageErrors.theme}
                        onPublish={() => design.finish('publish')}
                        onDiscard={() => design.finish('discard')}
                    />
                )}
            </div>
        </FormPage>
    );
}
