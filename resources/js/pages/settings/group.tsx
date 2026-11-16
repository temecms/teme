import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import SettingsController from '@/actions/App/Http/Admin/Settings/SettingsController';
import { BrandColourSetting } from '@/components/fields/brand-colour-setting';
import { HomePageSetting } from '@/components/fields/home-page-setting';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import {
    SettingsPanel,
    SettingsPanelBody,
    SettingsPanelFooter,
} from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { show as showSettings } from '@/routes/settings';
import type { SettingsGroupPage, SettingsWordsSection } from '@/types';

/** A plugin that takes hosts from its settings (docs/plans/plugin-sockets-1.md, A2). */
type PluginHosts = {
    /** The plugin's label. */
    plugin: string;
    /** The keys of the settings it takes hosts from. */
    settings: string[];
    /** The hosts already agreed in those settings. */
    agreed: string[];
    /** The hosts its manifest asks for that were agreed, wildcards included. */
    declared: string[];
};

/** The exact host names a url or urls value names, each once. */
function hostsIn(value: string): string[] {
    const hosts = new Set<string>();

    for (const line of value.split(/\r?\n/)) {
        try {
            const host = new URL(line.trim()).hostname.toLowerCase();

            if (
                /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/.test(
                    host,
                )
            ) {
                hosts.add(host);
            }
        } catch {
            // Not an address yet: it names no host.
        }
    }

    return [...hosts];
}

/** Whether an agreed host, or a wildcard above it, covers this one. */
function covered(agreed: string[], host: string): boolean {
    return agreed.some(
        (pattern) =>
            pattern === host ||
            (pattern.startsWith('*.') && host.endsWith(pattern.slice(1))),
    );
}

/**
 * A setting a plugin takes hosts from: the field, then, only while its
 * value names a host the plugin may not reach yet, a line saying so and a
 * box to agree, sent as `agreedHosts[]`. The server stays the judge.
 */
function HostsAgreement({
    hosts,
    settingKey,
    initial,
    children,
}: {
    hosts: PluginHosts;
    settingKey: string;
    initial: unknown;
    children: React.ReactNode;
}) {
    const [value, setValue] = useState(() =>
        Array.isArray(initial)
            ? initial.filter((line) => typeof line === 'string').join('\n')
            : typeof initial === 'string'
              ? initial
              : '',
    );
    const [agree, setAgree] = useState(false);
    const unagreed = hostsIn(value).filter(
        (host) =>
            !hosts.agreed.includes(host) && !covered(hosts.declared, host),
    );
    const id = `agree-hosts-${settingKey}`;

    return (
        <div
            className="contents"
            onInput={(event) => {
                const target = event.target as
                    | HTMLInputElement
                    | HTMLTextAreaElement;

                if (target.name === `values[${settingKey}]`) {
                    setValue(target.value);
                }
            }}
        >
            {children}
            {unagreed.length > 0 && (
                <div className="grid gap-2" data-test="hosts-agreement">
                    <p className="text-small text-ink-2">
                        {__('Saving lets :plugin connect to :hosts.', {
                            plugin: hosts.plugin,
                            hosts: unagreed.join(', '),
                        })}
                    </p>
                    <div className="flex items-start gap-2">
                        <div className="flex h-lh items-center">
                            <Checkbox
                                id={id}
                                checked={agree}
                                onCheckedChange={(checked) =>
                                    setAgree(checked === true)
                                }
                            />
                        </div>
                        <Label htmlFor={id} className="text-body text-ink">
                            {__('Let :plugin connect to these hosts', {
                                plugin: hosts.plugin,
                            })}
                        </Label>
                    </div>
                    {agree &&
                        unagreed.map((host) => (
                            <input
                                key={host}
                                type="hidden"
                                name="agreedHosts[]"
                                value={host}
                            />
                        ))}
                </div>
            )}
        </div>
    );
}

/**
 * A group's words in other languages (docs/proposals/multilingual-screens.md,
 * 6): under the words, a row per other language for each word, every
 * language shown at once; an empty field shows the default language's
 * words as its placeholder and falls back to them. Saved by its own Save.
 */
function WordsInOtherLanguages({
    group,
    words,
}: {
    group: string;
    words: SettingsWordsSection;
}) {
    const [values, setValues] = useState(() =>
        Object.fromEntries(
            words.languages.map((language) => [
                language.tag,
                Object.fromEntries(
                    words.fields.map((field) => [
                        field.key,
                        words.values[language.tag]?.[field.key] ?? '',
                    ]),
                ),
            ]),
        ),
    );
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const change = (tag: string, key: string, value: string) =>
        setValues((current) => ({
            ...current,
            [tag]: { ...current[tag], [key]: value },
        }));

    const save = () =>
        router.put(
            SettingsController.words.url({ group }),
            { words: values, expected_version: words.version },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (next) => setErrors(next),
                onSuccess: () => setErrors({}),
            },
        );

    const refusal = Object.values(errors)[0];

    return (
        <SettingsPanel
            as="section"
            bare
            id="words-in-other-languages"
            title={__('In other languages')}
            description={__(
                'An empty field shows the default language’s words to readers of that language.',
            )}
        >
            <SettingsPanelBody>
                {refusal !== undefined && (
                    <Notice
                        tone="danger"
                        role="alert"
                        data-test="words-refusal"
                    >
                        {refusal}
                    </Notice>
                )}
                {words.fields.map((field) => (
                    <fieldset
                        key={field.key}
                        className="grid gap-3"
                        data-test="words-field"
                        data-key={field.key}
                    >
                        <legend className="text-small text-ink-2 mb-2 font-medium">
                            {field.label}
                        </legend>
                        {words.languages.map((language) => {
                            const id = `words-${language.tag}-${field.key}`;
                            const props = {
                                id,
                                value: values[language.tag]?.[field.key] ?? '',
                                placeholder: words.defaults[field.key] ?? '',
                                onChange: (
                                    event: React.ChangeEvent<
                                        HTMLInputElement | HTMLTextAreaElement
                                    >,
                                ) =>
                                    change(
                                        language.tag,
                                        field.key,
                                        event.target.value,
                                    ),
                                'data-language': language.tag,
                            };

                            return (
                                <Field
                                    key={language.tag}
                                    htmlFor={id}
                                    label={language.name}
                                >
                                    {field.multiline ? (
                                        <Textarea {...props} />
                                    ) : (
                                        <Input {...props} />
                                    )}
                                </Field>
                            );
                        })}
                    </fieldset>
                ))}
            </SettingsPanelBody>
            <SettingsPanelFooter>
                <Button
                    type="button"
                    variant="primary"
                    loading={saving}
                    onClick={save}
                    data-test="words-save"
                >
                    {__('Save the words in other languages')}
                </Button>
            </SettingsPanelFooter>
        </SettingsPanel>
    );
}

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
    words,
    pluginHosts,
}: SettingsGroupPage & { pluginHosts: PluginHosts | null }) {
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
    ) => {
        const drawn = (
            <SettingsField
                key={field.key}
                field={field}
                value={values[field.key]}
                secret={secrets[field.key]}
                media={media[field.key] ?? null}
                error={
                    errors[`values.${field.key}`] ??
                    errors[`secrets.${field.key}`]
                }
            />
        );

        return pluginHosts?.settings.includes(field.key) ? (
            <HostsAgreement
                key={field.key}
                hosts={pluginHosts}
                settingKey={field.key}
                initial={values[field.key]}
            >
                {drawn}
            </HostsAgreement>
        ) : (
            drawn
        );
    };
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
                    askInPlace={group.sensitive || pluginHosts !== null}
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

                {words != null && (
                    <WordsInOtherLanguages group={group.key} words={words} />
                )}
            </SettingsPage>
        </>
    );
}
