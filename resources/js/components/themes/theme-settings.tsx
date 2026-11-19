import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    DesignFieldInput,
    text,
    visible,
} from '@/components/themes/design-field';
import type { useDesignDraft } from '@/components/themes/use-design-draft';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Panel } from '@/components/ui/panel';
import { SelectField } from '@/components/ui/select-field';
import { SoonTag } from '@/components/ui/soon-tag';
import { __ } from '@/lib/i18n';
import { index as navigationIndex } from '@/routes/navigation';
import type { DesignFieldset, DesignPage } from '@/types';

/** The settings sections, in the order Themes shows them (I-442). */
function sections(): { key: DesignFieldset; label: string }[] {
    return [
        { key: 'site', label: __('Site') },
        { key: 'latest', label: __('Latest posts') },
        { key: 'content', label: __('Content') },
        { key: 'archive', label: __('Archive') },
    ];
}

/** One section of the theme's settings: its heading with its button beside it, then its fields. */
function Section({
    id,
    title,
    action,
    children,
    test,
}: {
    id: string;
    title: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    test?: string;
}) {
    return (
        <Panel
            aria-labelledby={id}
            data-test={test}
            className="grid gap-5 p-6 max-sm:p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 id={id} className="text-body text-ink font-semibold">
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </Panel>
    );
}

/**
 * The chosen theme's own settings and menu places, on Themes under the
 * theme (v10): the one previewed, or else the one in use. Every change is
 * saved as a draft as it is made; each section previews its page on the
 * real site, and nothing reaches readers until Publish.
 */
export function ThemeSettings({
    page,
    design,
    name,
}: {
    page: DesignPage;
    design: ReturnType<typeof useDesignDraft>;
    name: string;
}) {
    const themeGroup = page.themeGroup;

    return (
        <section
            id="theme-settings"
            aria-labelledby="theme-settings-heading"
            data-test="theme-settings"
            className="grid scroll-mt-4 gap-4"
        >
            <div className="grid gap-1">
                <h2
                    id="theme-settings-heading"
                    className="text-lead text-ink font-semibold"
                >
                    {__(':name settings', { name })}
                </h2>
                {page.preview !== null && (
                    <p className="text-small text-ink-2">
                        {__('Visitors see these once you activate :name.', {
                            name: page.preview.name,
                        })}
                    </p>
                )}
            </div>

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
                        .map((section) => (
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
                                            void design.preview(section.key)
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
                                {page.fieldsets[section.key]
                                    .filter((field) =>
                                        visible(field, design.themeValues),
                                    )
                                    .map((field) => (
                                        <DesignFieldInput
                                            key={field.key}
                                            field={field}
                                            value={
                                                design.themeValues[field.key]
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
                            </Section>
                        ))}

                    <Section
                        id="theme-settings-appearance"
                        title={
                            <>
                                {__('Appearance')}
                                <SoonTag />
                            </>
                        }
                    >
                        <p className="text-small text-ink-2">
                            {__(
                                'Colors, fonts and spacing, with a live preview, come with the Appearance screen. Until then a theme sets them.',
                            )}
                        </p>
                    </Section>

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
        </section>
    );
}
