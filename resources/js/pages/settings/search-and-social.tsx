import { Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import type { CSSProperties, FormEvent, ReactNode } from 'react';
import SearchAndSocialController from '@/actions/App/Http/Admin/Settings/SearchAndSocialController';
import { PairsSetting } from '@/components/fields/pairs-setting';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { WhoPublishesSection } from '@/components/seo/who-publishes';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { fillTitle } from '@/lib/title-template';
import {
    index as settingsIndex,
    show as showSettings,
} from '@/routes/settings';
import type {
    FieldDefinition,
    SearchAndSocialPage,
    SearchAndSocialSection,
} from '@/types';

/** The files list's two columns from 640px: the name, then its address. */
const FILE_COLUMNS = {
    '--files-cols': '120px minmax(0, 1fr)',
} as CSSProperties;

const SEPARATOR = 'values[title_separator]';
const POST_TEMPLATE = 'values[title_type_post]';

/** The hint's own look, as SettingsField draws it under a control. */
const hintClass = 'text-caption text-ink-3 text-pretty';

/** The one muted line under a section's heading, by section; plugins' sections have none. */
function describe(key: string): string | undefined {
    switch (key) {
        case 'titles':
            return __(
                'How each kind of page is named in search results and browser tabs. Tokens in braces are filled in for each page.',
            );
        case 'listing':
            return __(
                'Every kind of page can be kept out of search results, one kind at a time.',
            );
        case 'site-verification':
            return __(
                'Proves to a search engine that you own this site. Each service gives you a name and a value to paste here.',
            );
        case 'social-cards':
            return __('What appears when a link to the site is shared.');
        case 'feeds':
            return __('What readers’ apps receive.');
        case 'robots-txt':
            return __(
                'Teme writes the file; these lines are added after its own rules.',
            );
        case 'apps-and-ai':
            return __('Other programs reading the site.');
        case 'not-found-log':
            return __(
                'Teme keeps a log of the missing addresses visitors try, so you can add redirects.',
            );
        case 'who-publishes':
            return __(
                'Search engines are told who publishes the site and every article on it.',
            );
        case 'page-and-article-types':
            return __(
                'What search engines are told each kind of item is, unless one says otherwise in the editor.',
            );
        default:
            return undefined;
    }
}

/**
 * Settings › Search and social: the group's sections in their order, core's
 * drawn as the design draws them, any other on the generic controls, and
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
    values,
    media,
    publisher,
    version,
}: SearchAndSocialPage) {
    setLayoutProps({
        breadcrumbs: [
            { title: __('Settings'), href: settingsIndex() },
            {
                title: __('Search and social'),
                href: showSettings({ group: 'seo' }),
            },
        ],
    });

    return (
        <>
            <Head title={__('Search and social settings')} />

            <SettingsPage
                title={__('Search and social')}
                description={description}
            >
                {!live && (
                    <Notice
                        tone="info"
                        data-test="seo-not-live"
                        className="mb-4"
                    >
                        <p>
                            <span className="font-medium">
                                {__('This copy isn’t the live site.')}
                            </span>{' '}
                            {__(
                                'It asks search engines to stay away, whatever these settings say. The live site follows them.',
                            )}
                        </p>
                    </Notice>
                )}

                <SettingsPanel
                    as="section"
                    label={__('What search engines and feed readers fetch')}
                    className="mb-4"
                >
                    <p className="text-body text-ink font-semibold">
                        {__('What search engines and feed readers fetch')}
                    </p>
                    <dl
                        className="text-body grid gap-2 sm:grid-cols-(--files-cols)"
                        style={FILE_COLUMNS}
                    >
                        <dt className="font-medium">{__('Sitemap')}</dt>
                        <dd>
                            {files.sitemap === null ? (
                                <span className="text-ink-2">
                                    {__(
                                        'Switched off while search engines are discouraged',
                                    )}
                                </span>
                            ) : (
                                <FileLink href={files.sitemap} />
                            )}
                        </dd>
                        <dt className="font-medium">{__('robots.txt')}</dt>
                        <dd>
                            <FileLink href={files.robots} />
                        </dd>
                        <dt className="font-medium">{__('Feed')}</dt>
                        <dd>
                            <FileLink href={files.feed} />
                            <span className="text-ink-2 block">
                                {__(
                                    'Each category, tag and author has one too: its own address, then /feed/.',
                                )}
                            </span>
                        </dd>
                    </dl>
                    <p className="text-small text-ink-2">
                        {__(
                            'Teme writes all three from the settings below, so none of them is a file to edit.',
                        )}
                    </p>
                </SettingsPanel>

                <SettingsForm
                    action={SearchAndSocialController.update.form()}
                    what={__(':page settings', {
                        page: __('Search and social'),
                    })}
                >
                    {({ errors }) => (
                        <SearchAndSocialSections
                            discouraged={discouraged}
                            sections={sections}
                            titles={titles}
                            robots={robots}
                            siteCodeUrl={siteCodeUrl}
                            values={values}
                            media={media}
                            publisher={publisher}
                            version={version}
                            errors={errors}
                        />
                    )}
                </SettingsForm>
            </SettingsPage>
        </>
    );
}

type SectionsProps = Pick<
    SearchAndSocialPage,
    | 'discouraged'
    | 'sections'
    | 'titles'
    | 'robots'
    | 'siteCodeUrl'
    | 'values'
    | 'media'
    | 'publisher'
    | 'version'
> & { errors: Record<string, string> };

/**
 * The form's sections: drawn inside SettingsForm, so Discard draws them
 * again from the page's props. The typed separator and post template are
 * kept to fill the title example.
 */
function SearchAndSocialSections({
    discouraged,
    sections,
    titles,
    robots,
    siteCodeUrl,
    values,
    media,
    publisher,
    version,
    errors,
}: SectionsProps) {
    const [separator, setSeparator] = useState(
        typeof values.title_separator === 'string'
            ? values.title_separator
            : '',
    );
    const [postTemplate, setPostTemplate] = useState(
        typeof values.title_type_post === 'string'
            ? values.title_type_post
            : '',
    );
    const allFields = new Map<string, FieldDefinition>(
        sections.flatMap((section) =>
            section.fields.map((field) => [field.key, field] as const),
        ),
    );
    const error = (key: string) => errors[`values.${key}`];
    const generic = (field: FieldDefinition) => (
        <SettingsField
            key={field.key}
            field={field}
            value={values[field.key]}
            media={media[field.key] ?? null}
            error={error(field.key)}
        />
    );
    const keep = (event: FormEvent<HTMLDivElement>) => {
        const input = event.target as HTMLInputElement;

        if (input.name === SEPARATOR) {
            setSeparator(input.value);
        } else if (input.name === POST_TEMPLATE) {
            setPostTemplate(input.value);
        }
    };
    const postRow =
        titles.rows.find((row) => row.key === 'type:post') ??
        titles.rows.find((row) => row.key.startsWith('type:'));
    const shownSeparator =
        separator.trim() === '' ? titles.separator : separator.trim();
    const shownTemplate =
        postRow === undefined
            ? ''
            : postTemplate.trim() === ''
              ? postRow.default
              : postTemplate;

    const panel = (
        key: string,
        title: string,
        children: ReactNode,
        id: string = key,
    ) => (
        <SettingsPanel
            key={key}
            id={`section-${id}`}
            title={title}
            description={describe(key)}
        >
            {children}
        </SettingsPanel>
    );
    const titlesPanel = (section: SearchAndSocialSection) =>
        panel(
            'titles',
            section.label,
            // The inputs are the generic ones; their typing is read from the form's own events.
            <div className="grid gap-5" onInput={keep}>
                {allFields.has('title_separator') &&
                    generic(allFields.get('title_separator')!)}
                {titles.rows.map((row) => {
                    const field = allFields.get(row.template);

                    return field === undefined ? null : generic(field);
                })}
                <p className="text-small text-ink-2">
                    {__('Tokens:')}{' '}
                    {titles.tokens.map((token, index) => (
                        <span key={token}>
                            {index > 0 && ' '}
                            <code className="font-mono">{`{${token}}`}</code>
                        </span>
                    ))}
                </p>
                {postRow !== undefined && (
                    <div
                        role="status"
                        aria-live="polite"
                        data-test="seo-title-example"
                        className="bg-surface-3 grid gap-1 rounded-md px-4 py-3"
                    >
                        <span className="text-caption text-ink-3 font-semibold uppercase">
                            {__('A post, as search engines see it')}
                        </span>
                        <span className="text-small font-mono break-words">
                            {fillTitle(
                                shownTemplate,
                                postRow.tokens,
                                shownSeparator,
                            )}
                        </span>
                    </div>
                )}
            </div>,
            'titles',
        );
    const listingPanel = () => {
        const discourage = sections.find(
            (section) => section.key === 'whole-site',
        )?.fields[0];

        return panel(
            'listing',
            __('What search engines may list'),
            <>
                {discouraged && (
                    <Notice tone="warn" data-test="seo-titles-discouraged">
                        <p className="font-medium">
                            {__('Search engines are asked to keep away.')}
                        </p>
                        <p>
                            {__(
                                'Every page asks not to be listed and the sitemap is switched off, so the site drops out of search results.',
                            )}
                        </p>
                    </Notice>
                )}
                {discourage !== undefined && generic(discourage)}
                {titles.rows.map((row) => {
                    const field =
                        row.hide === null ? undefined : allFields.get(row.hide);

                    return field === undefined ? null : generic(field);
                })}
            </>,
        );
    };

    return (
        <>
            <input type="hidden" name="expected_version" value={version} />
            <InputError
                id="expected-version-error"
                message={errors.expected_version}
            />

            {sections.map((section) => {
                switch (section.key) {
                    case 'whole-site':
                        return null;
                    case 'titles-and-listing':
                        return [titlesPanel(section), listingPanel()];
                    case 'site-verification':
                        return panel(
                            section.key,
                            section.label,
                            section.fields.map((field) => (
                                <div key={field.key} className="grid gap-1">
                                    <Label htmlFor={`field-${field.key}`}>
                                        {field.label}
                                    </Label>
                                    <PairsSetting
                                        field={field}
                                        name={`values[${field.key}]`}
                                        value={values[field.key]}
                                        addLabel={__('Add a service')}
                                        rowLabel={(n) =>
                                            __('Service :number', {
                                                number: n,
                                            })
                                        }
                                        emptyLabel=""
                                        removeLabel={(n) =>
                                            __('service :number', {
                                                number: n,
                                            })
                                        }
                                        namePlaceholder={__('Name')}
                                    />
                                    <p
                                        id={`field-${field.key}-hint`}
                                        className={hintClass}
                                    >
                                        {field.description}
                                    </p>
                                    {siteCodeUrl !== null && (
                                        <p className={hintClass}>
                                            {__('Scripts belong in')}{' '}
                                            <a
                                                href={siteCodeUrl}
                                                className="underline underline-offset-2"
                                            >
                                                {__('Site code')}
                                            </a>
                                            .
                                        </p>
                                    )}
                                    <InputError
                                        id={`field-${field.key}-error`}
                                        message={error(field.key)}
                                    />
                                </div>
                            )),
                        );
                    case 'robots-txt':
                        return panel(
                            section.key,
                            section.label,
                            <>
                                {section.fields.map((field) =>
                                    field.key === 'robots_extra_rules' ? (
                                        <div
                                            key={field.key}
                                            className="grid gap-1"
                                        >
                                            <Label
                                                htmlFor={`field-${field.key}`}
                                            >
                                                {field.label}
                                            </Label>
                                            <Textarea
                                                id={`field-${field.key}`}
                                                name={`values[${field.key}]`}
                                                rows={4}
                                                maxLength={
                                                    field.maxLength ?? undefined
                                                }
                                                defaultValue={
                                                    typeof values[field.key] ===
                                                    'string'
                                                        ? (values[
                                                              field.key
                                                          ] as string)
                                                        : ''
                                                }
                                                spellCheck={false}
                                                placeholder={__(
                                                    'User-agent: *\nDisallow: /print/',
                                                )}
                                                aria-describedby={`field-${field.key}-hint field-${field.key}-error`}
                                                className="bg-surface border-edge font-mono"
                                            />
                                            <p
                                                id={`field-${field.key}-hint`}
                                                className={hintClass}
                                            >
                                                {field.description}
                                            </p>
                                            <InputError
                                                id={`field-${field.key}-error`}
                                                message={error(field.key)}
                                            />
                                        </div>
                                    ) : (
                                        generic(field)
                                    ),
                                )}
                                <div
                                    data-test="seo-robots-preview"
                                    className="grid gap-1"
                                >
                                    <p
                                        id="robots-preview-label"
                                        className="text-small text-ink-2 font-medium"
                                    >
                                        {__('What readers’ robots will see')}
                                    </p>
                                    <pre
                                        aria-labelledby="robots-preview-label"
                                        className="bg-surface-3 text-small rounded-md px-4 py-3 font-mono break-all whitespace-pre-wrap"
                                    >
                                        {robots.text}
                                    </pre>
                                    <p className={hintClass}>
                                        {__('As last saved.')}
                                        {discouraged &&
                                            ` ${__(
                                                'It doesn’t change while search engines are discouraged: a search engine kept out couldn’t read each page’s request not to be listed.',
                                            )}`}
                                    </p>
                                </div>
                            </>,
                        );
                    case 'apps-and-ai':
                        return panel(
                            section.key,
                            section.label,
                            <>
                                {section.fields.map(generic)}
                                <p className={hintClass}>
                                    {__('Teme’s list:')}{' '}
                                    {robots.crawlers.join(', ')}.
                                </p>
                            </>,
                        );
                    case 'who-publishes':
                        return panel(
                            section.key,
                            section.label,
                            <WhoPublishesSection
                                section={section}
                                values={values}
                                media={media}
                                errors={errors}
                                publisher={publisher}
                            />,
                        );
                    case 'page-and-article-types':
                        return panel(
                            section.key,
                            section.label,
                            pairUp(section.fields).map((row) => (
                                <div
                                    key={row[0].key}
                                    className="grid gap-5 sm:grid-cols-2"
                                >
                                    {row.map(generic)}
                                </div>
                            )),
                        );
                    default:
                        return panel(
                            section.key,
                            section.label,
                            section.fields.map(generic),
                        );
                }
            })}
        </>
    );
}

/**
 * The structured data selects in rows of two: each type's page kind, then
 * its article kind, a type with no article kind on a row of its own.
 */
function pairUp(fields: FieldDefinition[]): FieldDefinition[][] {
    const rows: FieldDefinition[][] = [];

    for (const field of fields) {
        const article = /^schema_article_type_(.+)$/.exec(field.key);
        const last = rows[rows.length - 1];

        if (article && last?.[0]?.key === `schema_page_type_${article[1]}`) {
            last.push(field);
        } else {
            rows.push([field]);
        }
    }

    return rows;
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
