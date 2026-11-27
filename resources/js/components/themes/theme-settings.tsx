import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    DesignFieldInput,
    text,
    visible,
} from '@/components/themes/design-field';
import { ThemeRegions } from '@/components/themes/theme-regions';
import type { useDesignDraft } from '@/components/themes/use-design-draft';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Field } from '@/components/ui/field';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { index as navigationIndex } from '@/routes/navigation';
import type { DesignField, DesignFieldset, DesignPage, DesignSettingsPage } from '@/types';

/** The settings sections, in the order Themes shows them. */
function sections(): { key: DesignFieldset; label: string }[] {
    return [
        { key: 'site', label: __('Site') },
        { key: 'latest', label: __('Latest posts') },
        { key: 'content', label: __('Content') },
        { key: 'archive', label: __('Archive') },
    ];
}

/** One section of the theme's settings: a titled form panel (design system 4.12). */
function Section({
    id,
    title,
    action,
    children,
    test,
}: {
    id: string;
    title: string;
    action?: ReactNode;
    children: ReactNode;
    test?: string;
}) {
    return (
        <section
            id={id}
            data-settings-section={title}
            aria-labelledby={`${id}-heading`}
            data-test={test}
            className="bg-surface shadow-raised grid gap-5 rounded-lg p-6 max-sm:p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2
                    id={`${id}-heading`}
                    className="text-lead text-ink font-semibold"
                >
                    {title}
                </h2>
                {action}
            </div>
            {children}
        </section>
    );
}

/**
 * The visible fields of one fieldset, ungrouped first, then each group in
 * manifest order as a foldable section.
 */
function groupedFields(fields: DesignField[]): {
    top: DesignField[];
    groups: { label: string; fields: DesignField[] }[];
} {
    const top: DesignField[] = [];
    const groups: { label: string; fields: DesignField[] }[] = [];
    const seen = new Map<string, number>();

    for (const field of fields) {
        if (field.group === null || field.group === '') {
            top.push(field);

            continue;
        }

        const index = seen.get(field.group);

        if (index === undefined) {
            seen.set(field.group, groups.length);
            groups.push({ label: field.group, fields: [field] });
        } else {
            groups[index].fields.push(field);
        }
    }

    return { top, groups };
}

/**
 * The chosen theme's own settings and menu places: drafted as they change,
 * each section with a Preview of its page, and nothing live until Publish.
 * Drawn on the theme's settings page (issue 714).
 */
export function ThemeSettings({
    page,
    design,
    name,
}: {
    page: DesignSettingsPage | DesignPage;
    design: ReturnType<typeof useDesignDraft>;
    name: string;
}) {
    const themeGroup = page.themeGroup;
    const regions =
        'regions' in page && page.regions !== undefined ? page.regions : null;
    const canEditRegions =
        'canEditRegions' in page ? page.canEditRegions === true : false;
    const media = page.media ?? {};

    return (
        <div
            id="theme-settings"
            data-test="theme-settings"
            className="grid max-w-(--form-max) scroll-mt-4 gap-4"
        >
            <Section id="theme-settings-site-icon" title={__('Site icon')}>
                <DesignFieldInput
                    field={{
                        key: 'site_icon',
                        type: 'media',
                        label: __('Site icon'),
                        description: __(
                            'The favicon and home-screen icon. Empty uses the active theme’s default, when it has one.',
                        ),
                        options: {},
                        visibleWhen: {},
                        group: null,
                        min: null,
                        max: null,
                        step: null,
                        unit: null,
                    }}
                    value={design.designValues.site_icon}
                    media={media.site_icon}
                    error={design.errorOf('design', 'site_icon')}
                    onChange={(value) =>
                        design.change('design', 'site_icon', value)
                    }
                />
            </Section>

            {themeGroup === null ? (
                <p className="text-small text-ink-2">
                    {__(':name has no settings or menu places of its own.', {
                        name,
                    })}
                </p>
            ) : (
                <>
                    {sections()
                        .filter(
                            (section) => page.fieldsets[section.key].length > 0,
                        )
                        .map((section) => {
                            const visibleFields = page.fieldsets[
                                section.key
                            ].filter((field) =>
                                visible(field, design.themeValues),
                            );
                            const { top, groups } =
                                groupedFields(visibleFields);

                            return (
                                <Section
                                    key={section.key}
                                    id={`theme-settings-${section.key}`}
                                    title={section.label}
                                    action={
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="neutral"
                                            onClick={() =>
                                                void design.preview(
                                                    section.key,
                                                )
                                            }
                                        >
                                            {__('Preview')}
                                            <span className="sr-only">
                                                {' '}
                                                {section.label}
                                            </span>
                                        </Button>
                                    }
                                >
                                    {top.map((field) => (
                                        <DesignFieldInput
                                            key={field.key}
                                            field={field}
                                            value={
                                                design.themeValues[field.key]
                                            }
                                            media={media[field.key]}
                                            menus={page.menus}
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
                                    {groups.map((group) => (
                                        <Collapsible
                                            key={group.label}
                                            defaultOpen
                                            className="grid gap-3"
                                            data-test="setting-group"
                                        >
                                            <CollapsibleTrigger className="text-body text-ink flex items-center justify-between gap-2 font-medium">
                                                {group.label}
                                            </CollapsibleTrigger>
                                            <CollapsibleContent className="grid gap-5">
                                                {group.fields.map((field) => (
                                                    <DesignFieldInput
                                                        key={field.key}
                                                        field={field}
                                                        value={
                                                            design.themeValues[
                                                                field.key
                                                            ]
                                                        }
                                                        media={
                                                            media[field.key]
                                                        }
                                                        menus={page.menus}
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
                                            </CollapsibleContent>
                                        </Collapsible>
                                    ))}
                                </Section>
                            );
                        })}

                    {page.slots.length > 0 && (
                        <Section
                            id="theme-settings-menus"
                            test="design-menus"
                            title={__('Menus')}
                            action={
                                <Button size="sm" variant="neutral" asChild>
                                    <Link href={navigationIndex()}>
                                        {__('Edit menus')}
                                    </Link>
                                </Button>
                            }
                        >
                            <p className="text-small text-ink-2">
                                {__(
                                    'A menu you choose here shows once you publish, and replaces whatever Content › Navigation sets for that place. Places you don’t change here keep Navigation’s menu.',
                                )}
                            </p>
                            {page.slots.map((slot) => (
                                <Field
                                    key={slot.key}
                                    htmlFor={`design-${slot.key}`}
                                    label={slot.label}
                                    error={design.errorOf(themeGroup, slot.key)}
                                >
                                    <SelectField
                                        id={`design-${slot.key}`}
                                        className="w-full"
                                        value={text(
                                            design.themeValues[slot.key],
                                        )}
                                        emptyLabel={__('None')}
                                        options={page.menus.map((menu) => ({
                                            value: menu.id,
                                            label: menu.name,
                                        }))}
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
                        </Section>
                    )}
                </>
            )}

            {regions !== null && (
                <Section id="theme-settings-regions" title={__('Regions')}>
                    <ThemeRegions
                        regions={regions}
                        level={3}
                        intro={__('Regions are filled in Content › Regions.')}
                        canEdit={canEditRegions}
                        idPrefix="theme-settings-regions-list"
                    />
                </Section>
            )}
        </div>
    );
}
