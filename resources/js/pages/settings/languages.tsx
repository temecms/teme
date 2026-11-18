import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import type { HTMLAttributes } from 'react';
import LanguagesController from '@/actions/App/Http/Admin/Settings/LanguagesController';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { ResponsiveSheetContent } from '@/components/patterns/responsive-sheet';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOption } from '@/components/ui/combobox';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import {
    Sheet,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import {
    index as settingsIndex,
    show as showSettings,
} from '@/routes/settings';

/** One of the site's languages, as LanguagesScreen gives it. */
type SiteLanguageRow = {
    tag: string;
    name: string;
    native: string;
    prefix: string;
    shown: boolean;
    isDefault: boolean;
    items: number;
    terms: number;
};

/** A type or taxonomy, and whether it has versions in other languages. */
type TranslatedChoice = { key: string; label: string; translated: boolean };

type LanguagesPage = {
    languages: SiteLanguageRow[];
    choices: { tag: string; name: string; native: string }[];
    types: TranslatedChoice[];
    taxonomies: TranslatedChoice[];
    version: number;
};

/** The refusals an Operation may answer with, shown above the table. */
const REFUSALS = ['language', 'order', 'expected_version'] as const;

/** What a language holds, in words: "3 items · 1 term", or "Nothing yet". */
function holds(row: SiteLanguageRow): string {
    if (row.items === 0 && row.terms === 0) {
        return __('Nothing yet');
    }

    return [
        row.items > 0 && __n(':count item|:count items', row.items),
        row.terms > 0 && __n(':count term|:count terms', row.terms),
    ]
        .filter(Boolean)
        .join(' · ');
}

/** The sheet's form: a new language, or one being changed. */
function LanguageForm({
    editing,
    choices,
    version,
    onDone,
}: {
    editing: SiteLanguageRow | null;
    choices: LanguagesPage['choices'];
    version: number;
    onDone: () => void;
}) {
    const [chosen, setChosen] = useState<ComboboxOption | null>(null);
    const [prefix, setPrefix] = useState(editing?.prefix ?? '');
    const options = choices.map((choice): ComboboxOption => ({
        value: choice.tag,
        label: choice.name,
        description: choice.native,
        keywords: [choice.native, choice.tag],
    }));
    const action =
        editing === null
            ? LanguagesController.store.form()
            : LanguagesController.update.form({ tag: editing.tag });

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="flex min-h-0 flex-1 flex-col"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-5 px-6 pb-6">
                        <input
                            type="hidden"
                            name="expected_version"
                            value={version}
                        />
                        {errors.expected_version && (
                            <Notice tone="danger" role="alert">
                                {errors.expected_version}
                            </Notice>
                        )}
                        {editing === null && (
                            <Field
                                htmlFor="language-tag"
                                label={__('Language')}
                                error={errors.language}
                                required
                            >
                                <Combobox
                                    id="language-tag"
                                    name="tag"
                                    options={options}
                                    value={chosen}
                                    onValueChange={(option) => {
                                        setChosen(option);

                                        if (option !== null) {
                                            setPrefix(
                                                option.value.toLowerCase(),
                                            );
                                        }
                                    }}
                                    placeholder={__('Choose a language')}
                                    searchPlaceholder={__('Find a language')}
                                    emptyText={__('No language matches.')}
                                />
                            </Field>
                        )}
                        <Field
                            htmlFor="language-prefix"
                            label={__('Address prefix')}
                            help={__(
                                'The first part of every address in this language, such as /:prefix/about.',
                                { prefix: prefix === '' ? 'fr' : prefix },
                            )}
                            error={errors.prefix}
                            required
                        >
                            <Input
                                id="language-prefix"
                                name="prefix"
                                value={prefix}
                                maxLength={20}
                                autoComplete="off"
                                spellCheck={false}
                                onChange={(event) =>
                                    setPrefix(event.target.value)
                                }
                            />
                        </Field>
                        <FieldInline
                            htmlFor="language-shown"
                            label={__('Shown to readers')}
                            help={__(
                                'Hidden while it is translated: readers get nothing in it.',
                            )}
                        >
                            <Checkbox
                                id="language-shown"
                                name="shown"
                                value="1"
                                defaultChecked={editing?.shown ?? true}
                            />
                        </FieldInline>
                    </div>
                    <SheetFooter className="mt-auto">
                        <Button
                            type="button"
                            variant="neutral"
                            onClick={onDone}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            loading={processing}
                            data-test="language-save"
                        >
                            {editing === null
                                ? __('Add language')
                                : __('Save :name', { name: editing.name })}
                        </Button>
                    </SheetFooter>
                </>
            )}
        </Form>
    );
}

/** Which types and taxonomies have versions in other languages, saved by the page's one save bar. */
function TranslatedContent({
    types,
    taxonomies,
    version,
    defaultName,
}: {
    types: TranslatedChoice[];
    taxonomies: TranslatedChoice[];
    version: number;
    defaultName: string;
}) {
    const [untranslatedTypes, setUntranslatedTypes] = useState(() =>
        types.filter((type) => !type.translated).map((type) => type.key),
    );
    const [untranslatedTaxonomies, setUntranslatedTaxonomies] = useState(() =>
        taxonomies
            .filter((taxonomy) => !taxonomy.translated)
            .map((taxonomy) => taxonomy.key),
    );

    const toggle = (
        list: string[],
        set: (next: string[]) => void,
        key: string,
        on: boolean,
    ) => set(on ? list.filter((each) => each !== key) : [...list, key]);

    const group = (
        legend: string,
        hint: string,
        choices: TranslatedChoice[],
        list: string[],
        set: (next: string[]) => void,
        prefix: string,
        field: string,
    ) => (
        <fieldset className="grid content-start gap-2">
            <legend className="text-small text-ink mb-2 font-medium">
                {legend}
            </legend>
            {/* What is not ticked is what the server stores: the untranslated keys. */}
            {list.map((key) => (
                <input
                    key={key}
                    type="hidden"
                    name={`${field}[]`}
                    value={key}
                />
            ))}
            {choices.map((choice) => (
                <label
                    key={choice.key}
                    htmlFor={`${prefix}-${choice.key}`}
                    className="text-body text-ink flex items-center gap-2"
                >
                    <Checkbox
                        id={`${prefix}-${choice.key}`}
                        checked={!list.includes(choice.key)}
                        onCheckedChange={(on) =>
                            toggle(list, set, choice.key, on === true)
                        }
                    />
                    {choice.label}
                </label>
            ))}
            <p className="text-caption text-ink-3">{hint}</p>
        </fieldset>
    );

    return (
        <SettingsForm
            action={LanguagesController.translated.form()}
            what={__('Translated content')}
        >
            {({ errors }) => {
                const refusal =
                    errors.untranslated_types ??
                    errors.untranslated_taxonomies ??
                    errors.expected_version;

                return (
                    <SettingsPanel
                        as="section"
                        id="translated-content"
                        title={__('Translated content')}
                        description={__(
                            'Choose what can have versions in other languages. Anything not ticked is always in :language, and its addresses have no prefix.',
                            { language: defaultName },
                        )}
                    >
                        <input
                            type="hidden"
                            name="expected_version"
                            value={version}
                        />
                        {refusal !== undefined && (
                            <Notice tone="danger" role="alert">
                                {refusal}
                            </Notice>
                        )}
                        <div className="grid gap-6 sm:grid-cols-2">
                            {group(
                                __('Content types'),
                                __(
                                    'Each translated type gets a version per language, linked to the others.',
                                ),
                                types,
                                untranslatedTypes,
                                setUntranslatedTypes,
                                'translated-type',
                                'untranslated_types',
                            )}
                            {taxonomies.length > 0 &&
                                group(
                                    __('Taxonomies'),
                                    __(
                                        'Terms of a taxonomy that isn’t translated are shared by every language.',
                                    ),
                                    taxonomies,
                                    untranslatedTaxonomies,
                                    setUntranslatedTaxonomies,
                                    'translated-taxonomy',
                                    'untranslated_taxonomies',
                                )}
                        </div>
                    </SettingsPanel>
                );
            }}
        </SettingsForm>
    );
}

/**
 * Settings › Languages (docs/proposals/multilingual-screens.md): the site's
 * languages in one table, the default first and in the switcher's order,
 * each with one status and one worded action, the rare ones under "⋯"; a
 * language added or changed in a sheet; then which content is translated.
 */
export default function LanguagesSettings({
    languages,
    choices,
    types,
    taxonomies,
    version,
}: LanguagesPage) {
    const [sheet, setSheet] = useState<'new' | SiteLanguageRow | null>(null);
    const [removing, setRemoving] = useState<SiteLanguageRow | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const others = languages.filter((row) => !row.isDefault);
    const defaultLanguage = languages.find((row) => row.isDefault);
    const defaultName = defaultLanguage?.name ?? '';

    setLayoutProps({
        breadcrumbs: [
            { title: __('Settings'), href: settingsIndex() },
            {
                title: __('Languages'),
                href: LanguagesController.show.url(),
            },
        ],
    });

    const answered = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) =>
            setRefusal(
                REFUSALS.map((key) => errors[key]).find(Boolean) ??
                    Object.values(errors)[0] ??
                    null,
            ),
        onSuccess: () => setRefusal(null),
    };

    const move = (row: SiteLanguageRow, by: -1 | 1) => {
        const tags = others.map((other) => other.tag);
        const from = tags.indexOf(row.tag);
        const to = from + by;

        if (to < 0 || to >= tags.length) {
            return;
        }

        [tags[from], tags[to]] = [tags[to], tags[from]];
        router.put(
            LanguagesController.reorder.url(),
            { tags, expected_version: version },
            answered,
        );
    };

    const remove = (row: SiteLanguageRow) =>
        router.delete(LanguagesController.destroy.url({ tag: row.tag }), {
            data: { expected_version: version },
            ...answered,
        });

    const columns: Column<SiteLanguageRow>[] = [
        {
            id: 'language',
            owner: 'core',
            header: __('Language'),
            minWidth: 200,
            cell: (row) => (
                <div className="grid gap-1 whitespace-normal">
                    <span lang={row.tag} className="text-ink font-semibold">
                        {row.name}
                    </span>
                    <span lang={row.tag} className="text-small text-ink-3">
                        {row.native}
                    </span>
                </div>
            ),
        },
        {
            id: 'prefix',
            owner: 'core',
            header: __('Addresses'),
            width: 104,
            cell: (row) => (
                <code className="text-small">{`/${row.prefix}/…`}</code>
            ),
        },
        {
            id: 'readers',
            owner: 'core',
            header: __('Readers'),
            width: 112,
            cell: (row) => (
                <StatusPill tone={row.shown ? 'ok' : 'neutral'}>
                    {row.shown ? __('Shown') : __('Hidden')}
                </StatusPill>
            ),
        },
        {
            id: 'content',
            owner: 'core',
            header: __('Content'),
            width: 144,
            cell: (row) => holds(row),
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">{__('Actions')}</span>,
            label: __('Actions'),
            width: 240,
            cell: (row) => (
                <div className="flex items-center justify-end gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        data-test="language-move-up"
                        disabled={others[0]?.tag === row.tag}
                        aria-label={__('Move :name up', { name: row.name })}
                        onClick={() => move(row, -1)}
                    >
                        <ArrowUp aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        data-test="language-move-down"
                        disabled={others[others.length - 1]?.tag === row.tag}
                        aria-label={__('Move :name down', { name: row.name })}
                        onClick={() => move(row, 1)}
                    >
                        <ArrowDown aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="neutral"
                        size="sm"
                        data-test="language-edit"
                        onClick={(event) => {
                            opener.current = event.currentTarget;
                            setSheet(row);
                        }}
                    >
                        {__('Change')}
                        <span className="sr-only"> {row.name}</span>
                    </Button>
                    <Button
                        type="button"
                        variant="danger-quiet"
                        size="sm"
                        data-test="language-remove"
                        onClick={(event) => {
                            opener.current = event.currentTarget;
                            setRemoving(row);
                        }}
                    >
                        {__('Remove')}
                        <span className="sr-only"> {row.name}</span>
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={__('Languages')} />

            <SettingsPage
                title={__('Languages')}
                description={__(
                    'The language your site is written in, and the other languages it publishes in.',
                )}
            >
                <div className="grid gap-4">
                    {refusal !== null && (
                        <Notice
                            tone="danger"
                            role="alert"
                            data-test="languages-refusal"
                            onDismiss={() => setRefusal(null)}
                        >
                            {refusal}
                        </Notice>
                    )}

                    <SettingsPanel
                        as="section"
                        id="default-language"
                        title={__('Default language')}
                        description={__(
                            'Every item that names no other language is in it. Its addresses have no prefix.',
                        )}
                    >
                        <dl className="text-body grid gap-2">
                            <div className="flex flex-wrap gap-x-6 gap-y-1">
                                <dt className="text-ink-3 w-24">
                                    {__('Language')}
                                </dt>
                                <dd className="text-ink flex flex-wrap items-center gap-2">
                                    <span lang={defaultLanguage?.tag}>
                                        {defaultName}
                                    </span>
                                    <StatusPill
                                        tone="neutral"
                                        flat
                                        data-test="language-default"
                                    >
                                        {__('Default')}
                                    </StatusPill>
                                </dd>
                            </div>
                            <div className="flex flex-wrap gap-x-6 gap-y-1">
                                <dt className="text-ink-3 w-24">
                                    {__('Addresses')}
                                </dt>
                                <dd className="text-ink font-mono">
                                    {__('/about/ (no prefix)')}
                                </dd>
                            </div>
                        </dl>
                        <div>
                            <Button variant="neutral" size="sm" asChild>
                                <Link
                                    href={`${showSettings.url({ group: 'general' })}#field-language`}
                                >
                                    {__('Change it in General')}
                                </Link>
                            </Button>
                        </div>
                    </SettingsPanel>

                    <SettingsPanel
                        as="section"
                        id="other-languages"
                        title={__('Other languages')}
                        description={__(
                            'Each has its own address prefix. Hide one while you translate it: readers get nothing in it until you show it.',
                        )}
                    >
                        {others.length > 0 ? (
                            <div data-test="languages-table">
                                <DataTable
                                    columns={columns}
                                    rows={others}
                                    rowKey={(row) => row.tag}
                                    caption={__('Your site’s languages')}
                                    rowProps={(row) =>
                                        ({
                                            'data-language': row.tag,
                                        }) as HTMLAttributes<HTMLTableRowElement>
                                    }
                                />
                            </div>
                        ) : (
                            <p className="text-body text-ink-2">
                                {__(
                                    'Your site publishes in one language. Add another to translate posts and pages into it.',
                                )}
                            </p>
                        )}
                        <div>
                            <Button
                                type="button"
                                variant="neutral"
                                data-test="language-add"
                                disabled={choices.length === 0}
                                onClick={(event) => {
                                    opener.current = event.currentTarget;
                                    setSheet('new');
                                }}
                            >
                                <Plus aria-hidden="true" />
                                {__('Add a language')}
                            </Button>
                        </div>
                    </SettingsPanel>

                    <TranslatedContent
                        key={version}
                        types={types}
                        taxonomies={taxonomies}
                        version={version}
                        defaultName={defaultName}
                    />
                </div>

                <Sheet
                    open={sheet !== null}
                    onOpenChange={(open) => !open && setSheet(null)}
                >
                    <ResponsiveSheetContent
                        side="right"
                        data-test="language-sheet"
                        onCloseAutoFocus={(event) => {
                            if (opener.current?.isConnected) {
                                event.preventDefault();
                                opener.current.focus();
                            }
                        }}
                    >
                        <SheetHeader>
                            <SheetTitle>
                                {sheet === null || sheet === 'new'
                                    ? __('Add a language')
                                    : __('Change :name', { name: sheet.name })}
                            </SheetTitle>
                            <SheetDescription>
                                {sheet === null || sheet === 'new'
                                    ? __(
                                          'It is added last, after the other languages.',
                                      )
                                    : __(
                                          'Changing the prefix moves every address in this language. The old addresses redirect to the new ones.',
                                      )}
                            </SheetDescription>
                        </SheetHeader>
                        {sheet !== null && (
                            <LanguageForm
                                key={sheet === 'new' ? 'new' : sheet.tag}
                                editing={sheet === 'new' ? null : sheet}
                                choices={choices}
                                version={version}
                                onDone={() => setSheet(null)}
                            />
                        )}
                    </ResponsiveSheetContent>
                </Sheet>

                <Dialog
                    open={removing !== null}
                    onOpenChange={(open) => !open && setRemoving(null)}
                >
                    <ResponsiveDialogContent
                        phone="foot"
                        data-test="language-remove-dialog"
                        onCloseAutoFocus={(event) => {
                            if (opener.current?.isConnected) {
                                event.preventDefault();
                                opener.current.focus();
                            }
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                {__('Remove :name?', {
                                    name: removing?.name ?? '',
                                })}
                            </DialogTitle>
                            <DialogDescription>
                                {__(
                                    'Its address prefix is freed, and readers can no longer choose it. A language that still has content can’t be removed.',
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button variant="neutral">
                                    {__('Keep it')}
                                </Button>
                            </DialogClose>
                            <Button
                                variant="danger"
                                data-test="language-remove-confirm"
                                onClick={() => {
                                    if (removing !== null) {
                                        remove(removing);
                                    }

                                    setRemoving(null);
                                }}
                            >
                                {__('Remove language')}
                            </Button>
                        </DialogFooter>
                    </ResponsiveDialogContent>
                </Dialog>
            </SettingsPage>
        </>
    );
}
