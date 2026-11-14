import { Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import type { CSSProperties } from 'react';
import SearchAndSocialController from '@/actions/App/Http/Admin/Settings/SearchAndSocialController';
import { PairsSetting } from '@/components/fields/pairs-setting';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { WhoPublishesSection } from '@/components/seo/who-publishes';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import { fillTitle } from '@/lib/title-template';
import { show as showSettings } from '@/routes/settings';
import type {
    FieldDefinition,
    SearchAndSocialPage,
    SearchAndSocialRow,
} from '@/types';

/** The files list's two columns from 640px: the name, then its address. */
const FILE_COLUMNS = {
    '--files-cols': '120px minmax(0, 1fr)',
} as CSSProperties;

const textareaClass =
    'border-edge bg-page focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-small focus-visible:ring-2 focus-visible:outline-none';

/**
 * Settings › Search and social (I-874): the group's sections in their order,
 * core's drawn as their settings ask, any other on the generic controls, and
 * the page's one save bar.
 */
export default function SearchAndSocialSettings({
    description,
    live,
    discouraged,
    files,
    sections,
    titles,
    robots,
    siteCodeUrl,
    notFoundLogUrl,
    values,
    media,
    publisher,
    version,
}: SearchAndSocialPage) {
    const [separator, setSeparator] = useState(
        typeof values.title_separator === 'string'
            ? values.title_separator
            : '',
    );
    const [templates, setTemplates] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            titles.rows.map((row) => [
                row.template,
                typeof values[row.template] === 'string'
                    ? (values[row.template] as string)
                    : '',
            ]),
        ),
    );
    const shownSeparator =
        separator.trim() === '' ? titles.separator : separator.trim();
    setLayoutProps({
        breadcrumbs: [
            {
                title: __('Search and social'),
                href: showSettings({ group: 'seo' }),
            },
        ],
    });
    const allFields = new Map<string, FieldDefinition>(
        sections.flatMap((section) =>
            section.fields.map((field) => [field.key, field] as const),
        ),
    );

    return (
        <>
            <Head title="Search and social settings" />

            <SettingsPage title="Search and social" description={description}>
                {!live && (
                    <Notice
                        tone="info"
                        data-test="seo-not-live"
                        className="mb-4"
                    >
                        <p>
                            <span className="font-medium">
                                This copy isn’t the live site.
                            </span>{' '}
                            It asks search engines to stay away, whatever these
                            settings say. The live site follows them.
                        </p>
                    </Notice>
                )}

                <SettingsPanel
                    as="section"
                    title="What search engines and feed readers fetch"
                    className="mb-4"
                >
                    <dl
                        className="text-body grid gap-2 sm:grid-cols-(--files-cols)"
                        style={FILE_COLUMNS}
                    >
                        <dt className="font-medium">Sitemap</dt>
                        <dd>
                            {files.sitemap === null ? (
                                <span className="text-ink-2">
                                    Switched off while search engines are
                                    discouraged
                                </span>
                            ) : (
                                <FileLink href={files.sitemap} />
                            )}
                        </dd>
                        <dt className="font-medium">robots.txt</dt>
                        <dd>
                            <FileLink href={files.robots} />
                        </dd>
                        <dt className="font-medium">Feed</dt>
                        <dd>
                            <FileLink href={files.feed} />
                            <span className="text-ink-2 block">
                                Each category, tag and author has one too: its
                                own address, then /feed/.
                            </span>
                        </dd>
                    </dl>
                    <p className="text-small text-ink-2">
                        Teme writes all three from the settings below, so none
                        of them is a file to edit.
                    </p>
                </SettingsPanel>

                <SettingsForm
                    action={SearchAndSocialController.update.form()}
                    saveLabel={__('Save search and social settings')}
                    what={__(':page settings', {
                        page: __('Search and social'),
                    })}
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

                            {sections.map((section) => (
                                <SettingsPanel
                                    key={section.key}
                                    id={`section-${section.key}`}
                                    title={section.label}
                                >
                                    {section.key === 'titles-and-listing' && (
                                        <>
                                            {discouraged && (
                                                <Notice
                                                    tone="warn"
                                                    data-test="seo-titles-discouraged"
                                                >
                                                    <p className="font-medium">
                                                        Search engines are asked
                                                        to stay away from the
                                                        whole site.
                                                    </p>
                                                    <p>
                                                        Every page asks search
                                                        engines not to list it,
                                                        whatever these rows say.
                                                    </p>
                                                </Notice>
                                            )}

                                            <div className="grid gap-2">
                                                <Label htmlFor="field-title_separator">
                                                    Separator
                                                </Label>
                                                <Input
                                                    id="field-title_separator"
                                                    name="values[title_separator]"
                                                    maxLength={5}
                                                    value={separator}
                                                    onChange={(event) =>
                                                        setSeparator(
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder={
                                                        titles.separator
                                                    }
                                                    autoComplete="off"
                                                    aria-describedby="field-title_separator-hint field-title_separator-error"
                                                    className="w-24"
                                                />
                                                <p
                                                    id="field-title_separator-hint"
                                                    className="text-small text-ink-2"
                                                >
                                                    Goes between the parts of
                                                    every title. Empty uses the
                                                    one {titles.themeName}{' '}
                                                    suggests, “
                                                    {titles.separator}”.
                                                </p>
                                                <InputError
                                                    id="field-title_separator-error"
                                                    message={
                                                        errors[
                                                            'values.title_separator'
                                                        ]
                                                    }
                                                />
                                            </div>

                                            <p className="text-small">
                                                Titles are made from templates.
                                                Tokens:{' '}
                                                {titles.tokens.map(
                                                    (token, index) => (
                                                        <span key={token}>
                                                            {index > 0 && ' '}
                                                            <code className="font-mono">{`{${token}}`}</code>
                                                        </span>
                                                    ),
                                                )}
                                                . {'{title}'} is the page’s own
                                                heading: an item’s title, an
                                                archive’s name, or what was
                                                searched for. {'{page}'} gives
                                                “Page 2 of 7” from the second
                                                page on, and empty tokens and
                                                doubled separators are dropped.
                                                An item’s own SEO title takes
                                                the place of its template, word
                                                for word.
                                            </p>

                                            {section.note !== null && (
                                                <p className="text-small text-ink-2">
                                                    {section.note}
                                                </p>
                                            )}
                                            <div
                                                role="group"
                                                aria-label="Titles, and hiding from search engines, by kind of page"
                                                className="border-line divide-line divide-y rounded-lg border"
                                            >
                                                {titles.rows.map((row) => (
                                                    <TitleRow
                                                        key={row.key}
                                                        row={row}
                                                        typed={
                                                            templates[
                                                                row.template
                                                            ] ?? ''
                                                        }
                                                        onType={(text) =>
                                                            setTemplates(
                                                                (current) => ({
                                                                    ...current,
                                                                    [row.template]:
                                                                        text,
                                                                }),
                                                            )
                                                        }
                                                        separator={
                                                            shownSeparator
                                                        }
                                                        hideLabel={
                                                            row.hide === null
                                                                ? ''
                                                                : (allFields.get(
                                                                      row.hide,
                                                                  )?.label ??
                                                                  '')
                                                        }
                                                        hidden={
                                                            row.hide !== null &&
                                                            values[row.hide] ===
                                                                true
                                                        }
                                                        error={
                                                            errors[
                                                                `values.${row.template}`
                                                            ]
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        </>
                                    )}

                                    {section.key === 'site-verification' && (
                                        <>
                                            {section.note !== null && (
                                                <p className="text-small text-ink-2">
                                                    {section.note} Scripts
                                                    belong in{' '}
                                                    {siteCodeUrl === null ? (
                                                        'Site code'
                                                    ) : (
                                                        <a
                                                            href={siteCodeUrl}
                                                            className="underline underline-offset-2"
                                                        >
                                                            Site code
                                                        </a>
                                                    )}
                                                    .
                                                </p>
                                            )}
                                            {section.fields.map((field) => (
                                                <div
                                                    key={field.key}
                                                    className="grid gap-2"
                                                >
                                                    <Label
                                                        htmlFor={`field-${field.key}`}
                                                    >
                                                        {field.label}
                                                    </Label>
                                                    <PairsSetting
                                                        field={field}
                                                        name={`values[${field.key}]`}
                                                        value={
                                                            values[field.key]
                                                        }
                                                        addLabel="Add a tag"
                                                        rowLabel={(n) =>
                                                            `Verification tag ${n}`
                                                        }
                                                        emptyLabel="No verification tags."
                                                        removeLabel={(n) =>
                                                            `verification tag ${n}`
                                                        }
                                                        namePlaceholder="google-site-verification"
                                                    />
                                                    <p
                                                        id={`field-${field.key}-hint`}
                                                        className="text-small text-ink-2"
                                                    >
                                                        {field.description}
                                                    </p>
                                                    <InputError
                                                        id={`field-${field.key}-error`}
                                                        message={
                                                            errors[
                                                                `values.${field.key}`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                            ))}
                                        </>
                                    )}

                                    {section.key === 'robots-txt' && (
                                        <>
                                            {section.fields.map((field) =>
                                                field.key ===
                                                'robots_extra_rules' ? (
                                                    <div
                                                        key={field.key}
                                                        className="grid gap-2"
                                                    >
                                                        <Label
                                                            htmlFor={`field-${field.key}`}
                                                        >
                                                            {field.label}
                                                        </Label>
                                                        <textarea
                                                            id={`field-${field.key}`}
                                                            name={`values[${field.key}]`}
                                                            rows={4}
                                                            maxLength={
                                                                field.maxLength ??
                                                                undefined
                                                            }
                                                            defaultValue={
                                                                typeof values[
                                                                    field.key
                                                                ] === 'string'
                                                                    ? (values[
                                                                          field
                                                                              .key
                                                                      ] as string)
                                                                    : ''
                                                            }
                                                            spellCheck={false}
                                                            placeholder={
                                                                'User-agent: *\nDisallow: /print/'
                                                            }
                                                            aria-describedby={`field-${field.key}-hint field-${field.key}-error`}
                                                            className={`${textareaClass} font-mono`}
                                                        />
                                                        <p
                                                            id={`field-${field.key}-hint`}
                                                            className="text-small text-ink-2"
                                                        >
                                                            {field.description}
                                                        </p>
                                                        <InputError
                                                            id={`field-${field.key}-error`}
                                                            message={
                                                                errors[
                                                                    `values.${field.key}`
                                                                ]
                                                            }
                                                        />
                                                    </div>
                                                ) : (
                                                    <SettingsField
                                                        key={field.key}
                                                        field={field}
                                                        value={
                                                            values[field.key]
                                                        }
                                                        media={
                                                            media[field.key] ??
                                                            null
                                                        }
                                                        error={
                                                            errors[
                                                                `values.${field.key}`
                                                            ]
                                                        }
                                                    />
                                                ),
                                            )}
                                            <p className="text-small text-ink-2">
                                                Teme’s list:{' '}
                                                {robots.crawlers.join(', ')}.
                                            </p>
                                            <details
                                                data-test="seo-robots-preview"
                                                className="border-line rounded-md border"
                                            >
                                                <summary className="text-small cursor-pointer px-4 py-2 font-medium">
                                                    What robots.txt says now
                                                </summary>
                                                <div className="grid gap-2 px-4 pb-4">
                                                    <pre className="text-caption font-mono break-all whitespace-pre-wrap">
                                                        {robots.text}
                                                    </pre>
                                                    <p className="text-small text-ink-2">
                                                        As last saved.
                                                    </p>
                                                    {discouraged && (
                                                        <p className="text-small text-ink-2">
                                                            It doesn’t change
                                                            while search engines
                                                            are discouraged: a
                                                            search engine kept
                                                            out couldn’t read
                                                            each page’s request
                                                            not to be listed.
                                                        </p>
                                                    )}
                                                </div>
                                            </details>
                                        </>
                                    )}

                                    {section.key === 'who-publishes' && (
                                        <WhoPublishesSection
                                            section={section}
                                            values={values}
                                            media={media}
                                            errors={errors}
                                            publisher={publisher}
                                        />
                                    )}

                                    {section.key !== 'titles-and-listing' &&
                                        section.key !== 'site-verification' &&
                                        section.key !== 'robots-txt' &&
                                        section.key !== 'who-publishes' &&
                                        section.fields.map((field) => (
                                            <SettingsField
                                                key={field.key}
                                                field={field}
                                                value={values[field.key]}
                                                media={media[field.key] ?? null}
                                                error={
                                                    errors[
                                                        `values.${field.key}`
                                                    ]
                                                }
                                            />
                                        ))}

                                    {section.note !== null &&
                                        section.key !== 'site-verification' &&
                                        section.key !==
                                            'titles-and-listing' && (
                                            <p className="text-small text-ink-2">
                                                {section.note}
                                            </p>
                                        )}

                                    {section.key === 'not-found-log' &&
                                        notFoundLogUrl !== null && (
                                            <p className="text-small">
                                                <a
                                                    href={notFoundLogUrl}
                                                    className="underline underline-offset-2"
                                                >
                                                    Open the 404 log
                                                </a>
                                            </p>
                                        )}
                                </SettingsPanel>
                            ))}
                        </>
                    )}
                </SettingsForm>
            </SettingsPage>
        </>
    );
}

function FileLink({ href }: { href: string }) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener"
            className="text-small inline-flex min-h-6 items-center font-mono break-all underline underline-offset-2"
        >
            {href}
        </a>
    );
}

type TitleRowProps = {
    row: SearchAndSocialRow;
    typed: string;
    onType: (text: string) => void;
    separator: string;
    hideLabel: string;
    hidden: boolean;
    error?: string;
};

/** One kind of page: its template, the example as typed, and its hide box or note. */
function TitleRow({
    row,
    typed,
    onType,
    separator,
    hideLabel,
    hidden,
    error,
}: TitleRowProps) {
    const template = typed.trim() === '' ? row.default : typed;

    return (
        <div className="grid items-start gap-2 p-3 md:grid-cols-[170px_minmax(0,1fr)]">
            <Label htmlFor={`field-${row.template}`} className="pt-2">
                {row.label}
            </Label>
            <div className="grid gap-1">
                <Input
                    id={`field-${row.template}`}
                    name={`values[${row.template}]`}
                    value={typed}
                    onChange={(event) => onType(event.target.value)}
                    className="font-mono"
                    spellCheck={false}
                    autoComplete="off"
                    placeholder={row.default}
                    aria-describedby={`example-${row.template} field-${row.template}-error`}
                />
                <p
                    id={`example-${row.template}`}
                    data-test="seo-row-example"
                    className="text-caption text-ink-3 break-words"
                >
                    Shows as: {fillTitle(template, row.tokens, separator)}
                </p>
                {row.pageTwo !== null && (
                    <p
                        data-test="seo-row-page-two"
                        className="text-caption text-ink-3 break-words"
                    >
                        Page 2 shows as:{' '}
                        {fillTitle(template, row.pageTwo, separator)}
                    </p>
                )}
                <InputError
                    id={`field-${row.template}-error`}
                    message={error}
                />
                {row.hide !== null ? (
                    <div className="text-small flex min-h-6 items-center gap-2 pt-1">
                        <input
                            type="hidden"
                            name={`values[${row.hide}]`}
                            value="0"
                        />
                        <Checkbox
                            id={`field-${row.hide}`}
                            name={`values[${row.hide}]`}
                            value="1"
                            defaultChecked={hidden}
                        />
                        <Label htmlFor={`field-${row.hide}`}>{hideLabel}</Label>
                    </div>
                ) : (
                    row.note !== null && (
                        <p className="text-caption text-ink-3">{row.note}</p>
                    )
                )}
            </div>
        </div>
    );
}
