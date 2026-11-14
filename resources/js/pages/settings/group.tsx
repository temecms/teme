import { Form, Head } from '@inertiajs/react';
import SettingsController from '@/actions/App/Http/Admin/Settings/SettingsController';
import { BrandColourSetting } from '@/components/fields/brand-colour-setting';
import { HomePageSetting } from '@/components/fields/home-page-setting';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsPanel } from '@/components/fields/settings-panel';
import { SettingsSaveBar } from '@/components/fields/settings-save-bar';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { SettingsGroupPage } from '@/types';

/**
 * A Settings page drawn from a group's declaration: the page's heading, and
 * the group's form, under a heading of its own where the page names one
 * (People's Sign-in rules), saved through UpdateSettings. Reading's form says
 * the theme's own number of items a page, and Reading draws the addresses
 * group's Home page after it, with its own Save. General draws the design group's Brand colour after its form, with its own
 * Save. Media's form says which image widths Teme makes.
 */
export default function SettingsGroup({
    heading,
    section,
    group,
    fields,
    values,
    secrets,
    media,
    version,
    themePerPage,
    homePage,
    brandColour,
    imageWidths,
    panels,
}: SettingsGroupPage) {
    const fieldFor = (
        field: (typeof fields)[number],
        errors: Record<string, string>,
    ) => (
        <SettingsField
            key={field.key}
            field={field}
            value={values[field.key]}
            secret={secrets[field.key]}
            media={media[field.key] ?? null}
            error={
                errors[`values.${field.key}`] ?? errors[`secrets.${field.key}`]
            }
        />
    );
    const barNote =
        heading.key === 'general'
            ? 'Saves The site, and Time and language. Brand colour has its own Save.'
            : heading.key === 'reading'
              ? 'Saves the settings above. Home page has its own Save.'
              : undefined;
    const extras = (
        <>
            {themePerPage !== null && (
                <p
                    className="text-muted-foreground text-[13px]"
                    data-test="theme-per-page"
                >
                    {themePerPage.theme === null
                        ? `The default is ${themePerPage.number}.`
                        : `${themePerPage.theme}’s own number is ${themePerPage.number}.`}
                </p>
            )}

            {imageWidths !== null && (
                <div className="grid gap-1" data-test="image-widths">
                    <p className="text-sm font-medium">{imageWidths.label}</p>
                    <p className="text-[13px]">{imageWidths.value}</p>
                    <p className="text-muted-foreground text-[13px]">
                        {imageWidths.hint}
                    </p>
                </div>
            )}
        </>
    );
    const form = (
        <Form
            {...SettingsController.update.form({ group: group.key })}
            options={{ preserveScroll: true }}
            className="grid gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="expected_version"
                        value={version}
                    />
                    <InputError message={errors.expected_version} />

                    {panels !== null ? (
                        <>
                            {panels.map((panel, index) => (
                                <SettingsPanel
                                    key={panel.title}
                                    title={panel.title}
                                    description={panel.description}
                                >
                                    {panel.keys.map((key) => {
                                        const field = fields.find(
                                            (candidate) =>
                                                candidate.key === key,
                                        );

                                        return field === undefined
                                            ? null
                                            : fieldFor(field, errors);
                                    })}
                                    {index === panels.length - 1 && extras}
                                </SettingsPanel>
                            ))}
                        </>
                    ) : (
                        <SettingsPanel
                            label={heading.label}
                            title={section ?? undefined}
                            description={
                                section !== null ? group.description : undefined
                            }
                        >
                            {fields.map((field) => (
                                <div key={field.key} className="contents">
                                    {fieldFor(field, errors)}
                                    {themePerPage !== null &&
                                        field.key === 'posts_per_page' &&
                                        extras}
                                </div>
                            ))}
                            {themePerPage === null && extras}
                        </SettingsPanel>
                    )}

                    <SettingsSaveBar
                        label={`Save ${heading.label.toLowerCase()} settings`}
                        note={barNote}
                        processing={processing}
                        testId="settings-save"
                    />
                </>
            )}
        </Form>
    );

    return (
        <>
            <Head title={`${heading.label} settings`} />

            <div className="grid max-w-[960px] gap-6">
                <Heading
                    level={1}
                    title={heading.label}
                    description={heading.description}
                />

                {form}

                {homePage !== null && <HomePageSetting home={homePage} />}

                {brandColour !== null && (
                    <BrandColourSetting brand={brandColour} />
                )}
            </div>
        </>
    );
}
