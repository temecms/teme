import type { FormDataConvertible } from '@inertiajs/core';
import { Head, setLayoutProps } from '@inertiajs/react';
import SettingsController from '@/actions/App/Http/Admin/Settings/SettingsController';
import { BrandColourSetting } from '@/components/fields/brand-colour-setting';
import { HomePageSetting } from '@/components/fields/home-page-setting';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { __ } from '@/lib/i18n';
import { show as showSettings } from '@/routes/settings';
import type { SettingsGroupPage } from '@/types';

/**
 * A Settings page drawn from a group's declaration: the page's header, and
 * the group's form, under a heading of its own where the page names one
 * (People's Sign-in rules), saved through SaveSettingsPage by the one save
 * bar. Reading's form says the theme's own number of items a page and holds
 * the addresses group's Home page; General's holds the design group's Brand
 * colour (I-3437). Media's form says which image widths Teme makes.
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
    setLayoutProps({
        breadcrumbs: [
            {
                title: heading.label,
                href: showSettings({ group: heading.key }),
            },
        ],
    });

    /** Leaves out a lent section that did not change (I-3437): saving the brand colour also drops one drafted in Design. */
    const transform = (
        data: Record<string, FormDataConvertible>,
    ): Record<string, FormDataConvertible> => {
        const sent = { ...data };

        if (
            brandColour === null ||
            (typeof sent.accent === 'string'
                ? sent.accent
                : ''
            ).toLowerCase() === brandColour.value.toLowerCase()
        ) {
            delete sent.accent;
            delete sent.accent_version;
        }

        if (
            homePage === null ||
            (sent.front_shows === homePage.frontShows &&
                sent.front_page_id === homePage.frontPageId &&
                sent.posts_page_id === homePage.postsPageId)
        ) {
            delete sent.front_shows;
            delete sent.front_page_id;
            delete sent.posts_page_id;
        }

        return sent;
    };

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
    const extras = (
        <>
            {themePerPage !== null && (
                <p
                    className="text-caption text-ink-3"
                    data-test="theme-per-page"
                >
                    {themePerPage.theme === null
                        ? `The default is ${themePerPage.number}.`
                        : `${themePerPage.theme}’s own number is ${themePerPage.number}.`}
                </p>
            )}

            {imageWidths !== null && (
                <div className="grid gap-1" data-test="image-widths">
                    <p className="text-small text-ink-2 font-medium">
                        {imageWidths.label}
                    </p>
                    <p className="text-body">{imageWidths.value}</p>
                    <p className="text-caption text-ink-3">
                        {imageWidths.hint}
                    </p>
                </div>
            )}
        </>
    );

    return (
        <>
            <Head title={`${heading.label} settings`} />

            <SettingsPage
                title={heading.label}
                description={heading.description}
            >
                <SettingsForm
                    action={SettingsController.update.form({
                        group: group.key,
                    })}
                    saveLabel={__('Save :page settings', {
                        page: heading.label.toLowerCase(),
                    })}
                    what={__(':page settings', { page: heading.label })}
                    askInPlace={group.sensitive}
                    transform={transform}
                >
                    {({ errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="expected_version"
                                value={version}
                            />
                            <InputError
                                id="expected-version-error"
                                message={errors.expected_version}
                            />

                            {panels !== null ? (
                                panels.map((panel, index) => (
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
                                ))
                            ) : (
                                <SettingsPanel
                                    label={heading.label}
                                    title={section ?? undefined}
                                    description={
                                        section !== null
                                            ? group.description
                                            : undefined
                                    }
                                >
                                    {fields.map((field) => (
                                        <div
                                            key={field.key}
                                            className="contents"
                                        >
                                            {fieldFor(field, errors)}
                                            {themePerPage !== null &&
                                                field.key ===
                                                    'posts_per_page' &&
                                                extras}
                                        </div>
                                    ))}
                                    {themePerPage === null && extras}
                                </SettingsPanel>
                            )}

                            {homePage !== null && (
                                <HomePageSetting
                                    home={homePage}
                                    errors={errors}
                                />
                            )}

                            {brandColour !== null && (
                                <BrandColourSetting
                                    brand={brandColour}
                                    error={errors.accent}
                                />
                            )}
                        </>
                    )}
                </SettingsForm>
            </SettingsPage>
        </>
    );
}
