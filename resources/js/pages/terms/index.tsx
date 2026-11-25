import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import TermController from '@/actions/App/Http/Admin/Taxonomy/TermController';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { NumberedPager } from '@/components/list/numbered-pager';
import type { RowAction } from '@/components/list/row-actions';
import { LanguageMarks } from '@/components/list/language-marks';
import { RowLine } from '@/components/list/row-line';
import { SearchField } from '@/components/list/search-field';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { SelectField } from '@/components/ui/select-field';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useSectionCrumb } from '@/lib/crumbs';
import { __, __n } from '@/lib/i18n';
import { inTreeOrder } from '@/lib/term-tree';
import { index as contentIndex } from '@/routes/content';
import { index as termsIndex } from '@/routes/terms';
import type { TermRow, TermsIndexPage } from '@/types';

type Declared = TermsIndexPage['declared'];
type CountedType = TermsIndexPage['types'][number];

/** A term list holds 50 rows a page (TermList). */
const PER_PAGE = 50;

/** Puts the cursor back in the add form's Name, ready for the next term. */
const focusName = () => document.getElementById('term-add-name')?.focus();

/**
 * Names joined as a sentence joins them: "Categories and tags", or
 * "2 posts, 1 page and 3 recipes"; each name as given.
 */
function andList(names: string[]): string {
    return names.length < 2
        ? (names[0] ?? '')
        : __(':list and :last', {
              list: names.slice(0, -1).join(', '),
              last: names[names.length - 1],
          });
}

type Use = { type: CountedType; count: number };

/** The types whose items carry the term, with how many each, leaving out the trash. */
function usesOf(row: TermRow, types: CountedType[]): Use[] {
    return types
        .map((type) => ({ type, count: row.counts[type.key] ?? 0 }))
        .filter(({ count }) => count > 0);
}

/** "12 movies, 4 tv shows and 9 games". */
function countsWords(uses: Use[]): string {
    return andList(
        uses.map(
            ({ type, count }) =>
                `${count.toLocaleString()} ${(count === 1 ? type.singular : type.plural).toLowerCase()}`,
        ),
    );
}

/**
 * What deleting a term does, said before it is done (I-225): how many items
 * of each type lose it, leaving out the trash; where the terms directly
 * under it go; and that it can't be undone.
 */
function deleteWarning(
    row: TermRow,
    declared: Declared,
    types: CountedType[],
): string {
    const singular = declared.singular.toLowerCase();
    const uses = usesOf(row, types);
    const one = uses.length === 1 && uses[0]?.count === 1;
    const where =
        row.parentName === null
            ? __('to the top')
            : __('to “:parent”', { parent: row.parentName });
    const lost = countsWords(uses);

    return [
        uses.length > 0
            ? one
                ? __(':items loses this :singular.', { items: lost, singular })
                : __(':items lose this :singular.', { items: lost, singular })
            : null,
        row.children === 1
            ? __(
                  'Its sub:singular moves up a level, :where. Nothing under it is deleted.',
                  { singular, where },
              )
            : null,
        row.children > 1
            ? __(
                  'Its :count sub:plural move up a level, :where. Nothing under them is deleted.',
                  {
                      count: row.children.toLocaleString(),
                      plural: declared.plural.toLowerCase(),
                      where,
                  },
              )
            : null,
        __('This can’t be undone.'),
    ]
        .filter((line) => line !== null)
        .join(' ');
}

/**
 * The fields a term is added or edited with: its name; its slug; its
 * parent, where terms nest, from every term but itself and those under
 * it; and its description. Add and Edit share them, so editing sends back
 * whatever it doesn't change.
 */
function TermFields({
    prefix,
    declared,
    tree,
    errors,
    term,
}: {
    prefix: 'term-add' | 'term-edit';
    declared: Declared;
    tree: TermsIndexPage['tree'];
    errors: Partial<Record<string, string>>;
    term?: TermRow;
}) {
    const singular = declared.singular.toLowerCase();
    // A term can't sit inside its own branch: it and everything under it
    // leave the choices, as the server would refuse them.
    const parents = useMemo(() => {
        const rows = inTreeOrder(tree);
        const at = rows.findIndex((row) => row.term.id === term?.id);
        const depth = rows[at]?.depth ?? 0;
        const end =
            at < 0
                ? -1
                : rows.findIndex(
                      (row, index) => index > at && row.depth <= depth,
                  );

        return rows
            .filter(
                (_, index) =>
                    at < 0 || index < at || (end >= 0 && index >= end),
            )
            .map((row) => ({
                value: row.term.id,
                label: row.term.name,
                depth: row.depth,
            }));
    }, [tree, term?.id]);

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${prefix}-name`}>{__('Name')}</Label>
                <Input
                    id={`${prefix}-name`}
                    name="name"
                    required
                    autoComplete="off"
                    defaultValue={term?.name ?? ''}
                    aria-invalid={errors.name ? true : undefined}
                    aria-describedby={`${prefix}-name-error`}
                />
                <InputError id={`${prefix}-name-error`} message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${prefix}-slug`}>{__('Address')}</Label>
                <Input
                    id={`${prefix}-slug`}
                    name="slug"
                    autoComplete="off"
                    spellCheck={false}
                    className="font-mono"
                    defaultValue={term?.slug ?? ''}
                    aria-invalid={errors.slug ? true : undefined}
                    aria-describedby={`${prefix}-slug-hint ${prefix}-slug-error`}
                />
                <p
                    id={`${prefix}-slug-hint`}
                    className="text-ink-2 text-caption"
                >
                    {__(
                        'The last part of its address. Leave it empty to make one from the name.',
                    )}
                </p>
                <InputError id={`${prefix}-slug-error`} message={errors.slug} />
            </div>
            {declared.hierarchical && (
                <div className="grid gap-2">
                    <Label htmlFor={`${prefix}-parent`}>{__('Parent')}</Label>
                    {/* Later (I-1490): a searchable parent, through the shared combobox, for a taxonomy too large for a select. */}
                    <SelectField
                        id={`${prefix}-parent`}
                        className="w-full"
                        name="parent_id"
                        emptyLabel={__('None')}
                        defaultValue={term?.parentId ?? ''}
                        options={parents}
                        aria-describedby={`${prefix}-parent-error`}
                    />
                    <InputError
                        id={`${prefix}-parent-error`}
                        message={errors.parent}
                    />
                </div>
            )}
            <div className="grid gap-2">
                <Label htmlFor={`${prefix}-description`}>
                    {__('Description')}
                </Label>
                <Textarea
                    id={`${prefix}-description`}
                    name="description"
                    rows={3}
                    defaultValue={term?.description ?? ''}
                    aria-describedby={`${prefix}-description-hint ${prefix}-description-error`}
                />
                <p
                    id={`${prefix}-description-hint`}
                    className="text-ink-2 text-caption"
                >
                    {__(
                        'Some themes show it on the :singular’s page, and search engines may show it in results.',
                        { singular },
                    )}
                </p>
                <InputError
                    id={`${prefix}-description-error`}
                    message={errors.description}
                />
            </div>
        </>
    );
}

/** Where the term's own page is, and what it lists. */
function pageLine(row: TermRow, uses: Use[]): string {
    return uses.length === 0
        ? __('Its page: :address.', { address: row.address ?? '' })
        : __('Its page: :address, listing :items.', {
              address: row.address ?? '',
              items: countsWords(uses),
          });
}

export default function TermsIndex({
    taxonomy,
    declared,
    types,
    rows,
    paging,
    search,
    tree,
    languages = [],
    versions = null,
}: TermsIndexPage) {
    const [editing, setEditing] = useState<string | null>(null);
    // What opened the edit sheet, to take the focus back to when it closes.
    const opener = useRef<HTMLElement | null>(null);
    const singular = declared.singular.toLowerCase();
    const plural = declared.plural.toLowerCase();
    const editingRow = rows.find((row) => row.id === editing);
    const withSearch: Record<string, string> = search ? { search } : {};
    const emptyKind = search ? 'nothing-matches' : 'nothing-yet';
    // Whole branches, a child under its parent; a search lists its matches
    // by name, each with its path.
    const branches = declared.hierarchical && search === '';
    const heading = declared.plural;
    const contentCrumb = useSectionCrumb('content');
    // Under the first type that lists it: Content / Posts / Categories.
    const home = types[0];

    setLayoutProps({
        breadcrumbs: [
            contentCrumb,
            ...(home !== undefined
                ? [
                      {
                          title: home.plural,
                          href: contentIndex({ type: home.segment }),
                      },
                  ]
                : []),
            { title: heading, href: termsIndex({ taxonomy }) },
        ],
    });

    // The search filters as it is typed, a moment after the last key.
    const [words, setWords] = useState(search);
    useEffect(() => {
        if (words.trim() === search) {
            return;
        }

        const timer = window.setTimeout(
            () =>
                router.get(
                    termsIndex(
                        { taxonomy },
                        {
                            query:
                                words.trim() === ''
                                    ? {}
                                    : { search: words.trim() },
                        },
                    ).url,
                    {},
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            250,
        );

        return () => window.clearTimeout(timer);
    }, [words, search, taxonomy]);
    const description = declared.hierarchical
        ? __(
              ':plural can sit under one another. Counts leave out the trash. Choose a count to list its items, with those in its sub:lower.',
              { plural: declared.plural, lower: plural },
          )
        : __('Counts leave out the trash. Choose a count to list its items.');
    const edit = (row: TermRow) => {
        opener.current = document.activeElement as HTMLElement | null;
        setEditing(row.id);
    };

    /** Under the name: Edit, and Delete in red, asked first, saying what it does (I-225). */
    const lineActions = (row: TermRow): RowAction[] => [
        {
            id: 'edit',
            owner: 'core',
            label: __('Edit'),
            onSelect: () => edit(row),
        },
        {
            id: 'delete',
            owner: 'core',
            label: __('Delete'),
            destructive: true,
            form: TermController.destroy.form({
                taxonomy,
                term: row.id,
            }),
            wrap: (button) => (
                <ConfirmAction
                    title={__('Delete “:name”?', { name: row.name })}
                    description={deleteWarning(row, declared, types)}
                    confirmLabel={__('Delete :singular', { singular })}
                >
                    {button}
                </ConfirmAction>
            ),
        },
    ];

    const columns: Column<TermRow>[] = [
        {
            id: 'name',
            owner: 'core',
            header: __('Name'),
            minWidth: 240,
            className: 'whitespace-normal',
            cell: (row) => (
                <div className="grid gap-1">
                    {branches ? (
                        <span className="text-body text-ink leading-snug font-semibold">
                            {row.depth > 0 && (
                                <span
                                    aria-hidden="true"
                                    className="text-ink-2 font-normal"
                                >
                                    {'— '.repeat(row.depth)}
                                </span>
                            )}
                            <span data-test="term-name" data-depth={row.depth}>
                                {row.name}
                            </span>
                            {row.parentName !== null && (
                                <span className="sr-only">
                                    {', '}
                                    {__('under :parent', {
                                        parent: row.parentName,
                                    })}
                                </span>
                            )}
                        </span>
                    ) : (
                        <span
                            data-test="term-name"
                            data-depth={0}
                            className="text-body text-ink leading-snug font-semibold"
                        >
                            {row.path}
                        </span>
                    )}
                    {languages.length > 0 && (
                        <LanguageMarks
                            languages={languages}
                            states={versions?.[row.id] ?? {}}
                        />
                    )}
                    <RowLine title={row.name} items={lineActions(row)} />
                </div>
            ),
        },
        {
            id: 'slug',
            owner: 'core',
            header: __('Address'),
            cell: (row) => (
                <span className="text-ink-2 text-small font-mono">
                    {row.slug}
                </span>
            ),
        },
        // One column for each listed type that uses the taxonomy (I-225).
        ...types.map((type): Column<TermRow> => ({
            id: `count-${type.key}`,
            owner: 'core',
            header: type.plural,
            className: 'text-end tabular-nums',
            cell: (row) => {
                const count = row.counts[type.key] ?? 0;

                if (count === 0) {
                    return <span className="text-ink-3 tabular-nums">0</span>;
                }

                return (
                    <Link
                        data-test="term-count"
                        href={
                            contentIndex(
                                { type: type.segment },
                                { query: { [declared.key]: row.id } },
                            ).url
                        }
                        className="text-accent-ink focus-visible:outline-ring inline-flex min-h-6 min-w-6 items-center justify-end rounded-sm font-semibold tabular-nums hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        {count.toLocaleString()}
                        <span className="sr-only">
                            {' '}
                            {__(':items in :name', {
                                items: (count === 1
                                    ? type.singular
                                    : type.plural
                                ).toLowerCase(),
                                name: row.name,
                            })}
                        </span>
                    </Link>
                );
            },
        })),
    ];

    return (
        <ListPage title={heading} description={description}>
            <Head title={declared.plural} />

            <div className="flex flex-col items-start gap-6 lg:flex-row">
                <Panel
                    data-test="term-add"
                    aria-labelledby="term-add-heading"
                    className="w-full border-t first:border-t lg:w-75 lg:shrink-0"
                >
                    <PanelHeader>
                        <h2 id="term-add-heading">
                            {__('New :singular', { singular })}
                        </h2>
                    </PanelHeader>
                    <PanelBody>
                        <Form
                            {...TermController.store.form({ taxonomy })}
                            // The parent stays, so the next sibling is a name away.
                            resetOnSuccess={['name', 'slug', 'description']}
                            onSuccess={focusName}
                            className="grid gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <TermFields
                                        prefix="term-add"
                                        declared={declared}
                                        tree={tree}
                                        errors={errors}
                                    />
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="justify-self-start"
                                    >
                                        {__('Add :singular', { singular })}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>

                <div className="grid w-full min-w-0 flex-1 gap-3">
                    <div role="search" className="flex flex-wrap gap-2">
                        <SearchField
                            value={words}
                            onChange={(event) => setWords(event.target.value)}
                            placeholder={__('Search by name')}
                            aria-label={__('Search :plural', { plural })}
                            className="w-full sm:w-70"
                        />
                        {words !== '' && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setWords('')}
                            >
                                {__('Clear the search')}
                            </Button>
                        )}
                    </div>

                    <DataTable
                        columns={columns}
                        rows={rows}
                        rowKey={(row) => row.id}
                        caption={declared.plural}
                        pinned={{ start: ['name'], end: [] }}
                        cards={{
                            titleColumn: 'name',
                            facts: [
                                'slug',
                                ...types.map((type) => `count-${type.key}`),
                            ],
                        }}
                        wideNote={__('More columns: scroll sideways')}
                        empty={
                            <EmptyState
                                kind={emptyKind}
                                title={
                                    search
                                        ? __(
                                              'No :plural start with “:search”',
                                              {
                                                  plural,
                                                  search,
                                              },
                                          )
                                        : __('No :plural yet', { plural })
                                }
                                description={
                                    search
                                        ? undefined
                                        : __(
                                              'Use the form to add the first one.',
                                          )
                                }
                                clearHref={termsIndex({ taxonomy }).url}
                            />
                        }
                        footer={
                            paging.last > 1 || paging.more ? (
                                <NumberedPager
                                    pages={paging}
                                    perPage={PER_PAGE}
                                    href={(query) =>
                                        termsIndex(
                                            { taxonomy },
                                            {
                                                query: {
                                                    ...withSearch,
                                                    ...query,
                                                },
                                            },
                                        ).url
                                    }
                                />
                            ) : (
                                <p
                                    data-test="term-foot"
                                    className="text-small text-ink-3 px-5 py-4"
                                >
                                    {__n(
                                        ':count :singular. Counts leave out the trash.|:count :plural. Counts leave out the trash.',
                                        rows.length,
                                        { singular, plural },
                                    )}
                                </p>
                            )
                        }
                    />
                </div>
            </div>

            <Sheet
                open={editingRow !== undefined}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <SheetContent
                    className="w-full sm:max-w-120"
                    onCloseAutoFocus={(event) => {
                        if (opener.current?.isConnected) {
                            event.preventDefault();
                            opener.current.focus();
                        }
                    }}
                >
                    <SheetHeader>
                        <SheetTitle>
                            {__('Edit :singular', { singular })}
                        </SheetTitle>
                        <SheetDescription>{editingRow?.path}</SheetDescription>
                    </SheetHeader>
                    {editingRow && (
                        <Form
                            key={editingRow.id}
                            {...TermController.update.form({
                                taxonomy,
                                term: editingRow.id,
                            })}
                            onSuccess={() => setEditing(null)}
                            className="grid gap-4 px-4 pb-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <TermFields
                                        prefix="term-edit"
                                        declared={declared}
                                        tree={tree}
                                        errors={errors}
                                        term={editingRow}
                                    />
                                    {editingRow.address !== null && (
                                        <p
                                            data-test="term-page"
                                            className="text-ink-2 text-caption"
                                        >
                                            {pageLine(
                                                editingRow,
                                                usesOf(editingRow, types),
                                            )}
                                        </p>
                                    )}
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {__('Save')}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            type="button"
                                            onClick={() => setEditing(null)}
                                        >
                                            {__('Cancel')}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </SheetContent>
            </Sheet>
        </ListPage>
    );
}
