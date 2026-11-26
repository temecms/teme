import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router, setLayoutProps } from '@inertiajs/react';
import { Fragment, useEffect, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import SettingsController from '@/actions/App/Http/Admin/Settings/SettingsController';
import { BrandColourSetting } from '@/components/fields/brand-colour-setting';
import type { WaitingScript } from '@/components/fields/code-field';
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
import { RadioGroup, RadioGroupChoice } from '@/components/ui/radio-group';
import { SoonTag } from '@/components/ui/soon-tag';
import { Textarea } from '@/components/ui/textarea';
import {
    AreaScriptsProvider,
    useScriptApproval,
} from '@/components/plugins/area/area-form';
import {
    CodeSetting,
    Hidden,
    PluginActionsPanel,
    PluginListsPanel,
    RepeaterSetting,
    SettingsConditions,
} from '@/components/plugins/area/settings-parts';
import { __ } from '@/lib/i18n';
import {
    index as settingsIndex,
    show as showSettings,
} from '@/routes/settings';
import type { SettingsGroupPage, SettingsWordsSection } from '@/types';

/** A plugin's code settings' scripts that wait for approval, by the field's path. */
type PluginScripts = {
    code: string;
    waiting: Record<string, WaitingScript[]>;
    /** Whether this person may approve them here. */
    approver: boolean;
};

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
    const lineId = `hosts-line-${settingKey}`;
    const showing = unagreed.length > 0;

    // The line is read with its field: its id joins the field's aria-describedby while it shows.
    useEffect(() => {
        const field = document.getElementById(`field-${settingKey}`);

        if (field === null || !showing) {
            return;
        }

        const before = field.getAttribute('aria-describedby') ?? '';
        field.setAttribute('aria-describedby', `${before} ${lineId}`.trim());

        return () => {
            field.setAttribute(
                'aria-describedby',
                (field.getAttribute('aria-describedby') ?? '')
                    .split(' ')
                    .filter((part) => part !== lineId && part !== '')
                    .join(' '),
            );
        };
    }, [settingKey, lineId, showing]);

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
            {showing && (
                <div className="grid gap-2" data-test="hosts-agreement">
                    <p id={lineId} className="text-small text-ink-2">
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
 * People's pictures (I-3410): the picture beside a person's name until they
 * choose their own. Initials are what Teme draws; Gravatar is drawn as the
 * approved design has it and waits to be built, so it can't be chosen yet.
 */
function PeoplesPictures() {
    return (
        <SettingsPanel
            as="section"
            title={__('People’s pictures')}
            description={__(
                'The picture beside a person’s name until they choose their own.',
            )}
        >
            <div className="grid gap-1" data-test="peoples-pictures">
                <p
                    id="peoples-pictures-label"
                    className="text-small text-ink-2 font-medium"
                >
                    {__('People’s pictures')}
                </p>
                <RadioGroup
                    value="initials"
                    aria-labelledby="peoples-pictures-label"
                    aria-describedby="peoples-pictures-hint"
                >
                    <RadioGroupChoice
                        id="peoples-pictures-initials"
                        value="initials"
                        label={__('Initials')}
                        description={__(
                            'A person’s initials on a plain circle.',
                        )}
                    />
                    <RadioGroupChoice
                        id="peoples-pictures-gravatar"
                        value="gravatar"
                        disabled
                        label={
                            <span className="inline-flex items-center gap-2">
                                {__('Gravatar')}
                                <SoonTag />
                            </span>
                        }
                        description={__(
                            'Shows the picture a person has set at Gravatar for their email address. Teme’s own server fetches it, so nobody’s browser contacts Gravatar.',
                        )}
                    />
                </RadioGroup>
                <p
                    id="peoples-pictures-hint"
                    className="text-caption text-ink-3 text-pretty"
                >
                    {__(
                        'Anyone can upload their own picture from their profile; it replaces the default.',
                    )}
                </p>
            </div>
        </SettingsPanel>
    );
}

/** A list of terms: the term's column 120 to 200 wide, the value taking the rest. */
const DEFLIST = {
    '--deflist-cols': 'minmax(120px, 200px) minmax(0, 1fr)',
} as CSSProperties;

/** Media's fixed image widths, as a list of terms: nothing here is a setting. */
function ImageWidths({
    widths,
}: {
    widths: NonNullable<SettingsGroupPage['imageWidths']>;
}) {
    return (
        <SettingsPanel
            as="section"
            title={__('Image widths')}
            description={__(
                'Fixed in Teme, so there is nothing to change here.',
            )}
        >
            <div className="grid gap-2" data-test="image-widths">
                <dl
                    className="grid grid-cols-[minmax(0,1fr)] gap-x-6 gap-y-2 sm:grid-cols-(--deflist-cols)"
                    style={DEFLIST}
                >
                    {widths.rows.map((row) => (
                        <Fragment key={row.term}>
                            <dt className="text-small text-ink-3">
                                {row.term}
                            </dt>
                            <dd className="text-body text-ink">{row.value}</dd>
                        </Fragment>
                    ))}
                </dl>
                <p className="text-caption text-ink-3 text-pretty">
                    {widths.hint}
                </p>
            </div>
        </SettingsPanel>
    );
}

/**
 * A Settings page drawn from a group's declaration: the page's header, and
 * the group's form in the sections the page is split into, saved through
 * SaveSettingsPage by the one save bar. Reading opens with the addresses
 * group's Home page; General ends with the design group's Brand colour
 * (I-3437); Media ends with the image widths Teme makes; People opens with
 * the line on its password and ends with People's pictures. A plugin's page
 * names its plugin with the plug mark.
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
    pluginActions = [],
    pluginLists = [],
    pluginScripts = null,
    notice = null,
    plugin = null,
    readOnly = null,
    bare = false,
}: SettingsGroupPage & {
    pluginHosts: PluginHosts | null;
    pluginScripts?: PluginScripts | null;
    /** Draw only the form body, for a plugin area's Settings tab. */
    bare?: boolean;
}) {
    const pluginCode = group.key.startsWith('plugin.')
        ? group.key.slice('plugin.'.length)
        : null;
    const approval = useScriptApproval(pluginScripts?.code ?? '');
    const scripts = {
        waiting: pluginScripts?.waiting ?? {},
        approve: pluginScripts?.approver ? approval.approve : undefined,
        busy: approval.busy,
    };
    const people = heading.key === 'people';

    if (!bare) {
        setLayoutProps({
            breadcrumbs: [
                { title: __('Settings'), href: settingsIndex() },
                {
                    title: heading.label,
                    href: showSettings({ group: heading.key }),
                },
            ],
        });
    }

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

    /** Media's Largest upload names the server's own limit (v10: "(128 MB here)"). */
    const described = (field: (typeof fields)[number]) =>
        imageWidths !== null && field.key === 'max_upload_mb'
            ? {
                  ...field,
                  description: field.description.replace(
                      __('Your server’s own upload limit must'),
                      __('Your server’s own upload limit (:limit here) must', {
                          limit: imageWidths.uploadLimit,
                      }),
                  ),
              }
            : field;

    const fieldFor = (
        field: (typeof fields)[number],
        errors: Record<string, string>,
        shown?: Set<string>,
    ) => {
        // Only a setting with a condition can hide; it alone is wrapped (Hidden).
        const conditional =
            shown !== undefined &&
            Object.keys(field.visibleWhen ?? {}).length > 0;
        const wrap = (node: ReactNode) =>
            conditional ? (
                <Hidden key={field.key} hidden={!shown.has(field.key)}>
                    {node}
                </Hidden>
            ) : (
                <Fragment key={field.key}>{node}</Fragment>
            );

        if (field.type === 'repeater' || field.type === 'code') {
            return wrap(
                field.type === 'repeater' ? (
                    <RepeaterSetting
                        field={field}
                        value={values[field.key]}
                        errors={errors}
                    />
                ) : (
                    <CodeSetting
                        field={field}
                        value={values[field.key]}
                        error={errors[`values.${field.key}`]}
                    />
                ),
            );
        }

        const drawn = (
            <SettingsField
                key={field.key}
                field={described(field)}
                value={values[field.key]}
                secret={secrets[field.key]}
                media={media[field.key] ?? null}
                sensitive={group.sensitive && readOnly === null}
                disabled={readOnly !== null}
                error={
                    errors[`values.${field.key}`] ??
                    errors[`secrets.${field.key}`]
                }
            />
        );

        const agreed = pluginHosts?.settings.includes(field.key) ? (
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

        return conditional ? wrap(agreed) : agreed;
    };

    const themeLine = themePerPage !== null && (
        <p className="text-caption text-ink-3" data-test="theme-per-page">
            {themePerPage.theme === null
                ? __('The default is :number.', {
                      number: themePerPage.number,
                  })
                : __(':theme’s own number is :number.', {
                      theme: themePerPage.theme,
                      number: themePerPage.number,
                  })}
        </p>
    );

    // Every setting in exactly one section: one no section names, such as a
    // plugin's added to a core group, follows in a panel of its own.
    const placed = new Set((panels ?? []).flatMap((panel) => panel.keys));
    const loose = fields.filter((field) => !placed.has(field.key));
    const sections = [
        ...(panels ?? []),
        ...(loose.length > 0
            ? [
                  {
                      title: panels === null ? (section ?? '') : '',
                      description:
                          panels === null && section !== null
                              ? group.description
                              : '',
                      keys: loose.map((field) => field.key),
                  },
              ]
            : []),
    ];

    const body = (
        <>
            {readOnly !== null && (
                <Notice
                    tone="info"
                    role="status"
                    data-test="settings-view-only"
                >
                    <strong className="font-semibold">
                        {__('You can see these settings, but not change them.')}
                    </strong>{' '}
                    {__(
                        'Changing :page settings needs the “:permission” permission, which :holders hold.',
                        {
                            page: heading.label,
                            permission: readOnly.permission,
                            holders: readOnly.holders,
                        },
                    )}
                </Notice>
            )}
            {notice !== null && (
                <Notice tone="warn" role="status" data-test="settings-notice">
                    {notice}
                </Notice>
            )}
            {people && readOnly === null && (
                <Notice tone="info" data-test="settings-password-banner">
                    <strong className="font-semibold">
                        {__(
                            'Changing a sign-in rule asks you to confirm your password.',
                        )}
                    </strong>{' '}
                    {__('Teme asks once, then not again for 15 minutes.')}
                </Notice>
            )}
            <AreaScriptsProvider value={scripts}>
                <SettingsForm
                    action={SettingsController.update.form({
                        group: group.key,
                    })}
                    what={__(':page settings', { page: heading.label })}
                    askInPlace={group.sensitive || pluginHosts !== null}
                    transform={transform}
                    readOnly={readOnly !== null}
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

                            {homePage !== null && (
                                <HomePageSetting
                                    home={homePage}
                                    errors={errors}
                                />
                            )}

                            <SettingsConditions fields={fields} values={values}>
                                {(shown) =>
                                    sections.map((panel, index) => (
                                        <SettingsPanel
                                            key={`${index}-${panel.title}`}
                                            title={
                                                panel.title !== ''
                                                    ? panel.title
                                                    : undefined
                                            }
                                            label={
                                                panel.title === ''
                                                    ? heading.label
                                                    : undefined
                                            }
                                            description={
                                                panel.description !== ''
                                                    ? panel.description
                                                    : undefined
                                            }
                                        >
                                            {panel.keys.map((key) => {
                                                const field = fields.find(
                                                    (candidate) =>
                                                        candidate.key === key,
                                                );

                                                return field ===
                                                    undefined ? null : (
                                                    <Fragment key={key}>
                                                        {fieldFor(
                                                            field,
                                                            errors,
                                                            shown,
                                                        )}
                                                        {key ===
                                                            'posts_per_page' &&
                                                            themeLine}
                                                    </Fragment>
                                                );
                                            })}
                                        </SettingsPanel>
                                    ))
                                }
                            </SettingsConditions>

                            {imageWidths !== null && (
                                <ImageWidths widths={imageWidths} />
                            )}

                            {brandColour !== null && (
                                <BrandColourSetting
                                    brand={brandColour}
                                    error={errors.accent}
                                />
                            )}

                            {people && <PeoplesPictures />}
                        </>
                    )}
                </SettingsForm>

                {pluginCode !== null && (
                    <>
                        {/* On an area Settings tab the Overview already draws these actions. */}
                        {!bare && (
                            <PluginActionsPanel
                                code={pluginCode}
                                actions={pluginActions}
                            />
                        )}
                        <PluginListsPanel lists={pluginLists} />
                    </>
                )}

                {words != null && (
                    <WordsInOtherLanguages group={group.key} words={words} />
                )}
            </AreaScriptsProvider>
        </>
    );

    if (bare) {
        return (
            <>
                {pluginScripts !== null && approval.password}
                {body}
            </>
        );
    }

    return (
        <>
            <Head title={`${heading.label} settings`} />
            {pluginScripts !== null && approval.password}

            <SettingsPage
                title={heading.label}
                description={heading.description}
                plugin={plugin ?? undefined}
            >
                {body}
            </SettingsPage>
        </>
    );
}
