import { Form, Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import TermController from '@/actions/App/Http/Admin/Taxonomy/TermController';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { PageHeader } from '@/components/list/page-header';
import { Pager } from '@/components/list/pager';
import { RowLinks } from '@/components/list/row-links';
import { TabStrip } from '@/components/list/tab-strip';
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
import { inTreeOrder } from '@/lib/term-tree';
import { index as contentIndex } from '@/routes/content';
import { index as termsIndex } from '@/routes/terms';
import type { TermRow, TermsIndexPage } from '@/types';

type Declared = TermsIndexPage['declared'];
type CountedType = TermsIndexPage['types'][number];

/** A text area drawn as the admin's inputs are. */
const AREA =
    'border-edge bg-page focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-[13px] outline-none focus-visible:ring-2';

/** Puts the cursor back in the add form's Name, ready for the next term. */
const focusName = () => document.getElementById('term-add-name')?.focus();

/**
 * Names joined as a sentence joins them: "Categories and tags", or
 * "2 posts, 1 page and 3 recipes"; each name as given.
 */
function andList(names: string[]): string {
    return names.length < 2
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
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
    const lose = uses.length === 1 && uses[0]?.count === 1 ? 'loses' : 'lose';
    const where =
        row.parentName === null ? 'to the top' : `to “${row.parentName}”`;
    const lost = countsWords(uses);

    return [
        uses.length > 0 ? `${lost} ${lose} this ${singular}.` : null,
        row.children === 1
            ? `Its sub${singular} moves up a level, ${where}. Nothing under it is deleted.`
            : null,
        row.children > 1
            ? `Its ${row.children.toLocaleString()} sub${declared.plural.toLowerCase()} move up a level, ${where}. Nothing under them is deleted.`
            : null,
        'This can’t be undone.',
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
            <div className="grid gap-1.5">
                <Label htmlFor={`${prefix}-name`}>Name</Label>
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
            <div className="grid gap-1.5">
                <Label htmlFor={`${prefix}-slug`}>Address</Label>
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
                <p id={`${prefix}-slug-hint`} className="text-ink-2 text-xs">
                    {
                        'The last part of its address. Leave it empty to make one from the name.'
                    }
                </p>
                <InputError id={`${prefix}-slug-error`} message={errors.slug} />
            </div>
            {declared.hierarchical && (
                <div className="grid gap-1.5">
                    <Label htmlFor={`${prefix}-parent`}>Parent</Label>
                    {/* Later (I-1490): a searchable parent, through the shared combobox, for a taxonomy too large for a select. */}
                    <SelectField
                        id={`${prefix}-parent`}
                        name="parent_id"
                        emptyLabel="None"
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
            <div className="grid gap-1.5">
                <Label htmlFor={`${prefix}-description`}>Description</Label>
                <textarea
                    id={`${prefix}-description`}
                    name="description"
                    rows={3}
                    className={AREA}
                    defaultValue={term?.description ?? ''}
                    aria-describedby={`${prefix}-description-hint ${prefix}-description-error`}
                />
                <p
                    id={`${prefix}-description-hint`}
                    className="text-ink-2 text-xs"
                >
                    {`Some themes show it on the ${singular}’s page, and search engines may show it in results.`}
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
        ? `Its page: ${row.address}.`
        : `Its page: ${row.address}, listing ${countsWords(uses)}.`;
}

export default function TermsIndex({
    taxonomy,
    declared,
    tabs,
    types,
    rows,
    newer,
    older,
    search,
    tree,
    setup,
}: TermsIndexPage) {
    const [editing, setEditing] = useState<string | null>(null);
    const singular = declared.singular.toLowerCase();
    const plural = declared.plural.toLowerCase();
    const editingRow = rows.find((row) => row.id === editing);
    const withSearch = search ? { search } : {};
    const emptyKind = search ? 'nothing-matches' : 'nothing-yet';
    // Whole branches, a child under its parent; a search lists its matches
    // by name, each with its path.
    const branches = declared.hierarchical && search === '';
    const heading = andList(
        tabs.map((tab, at) => (at === 0 ? tab.label : tab.label.toLowerCase())),
    );
    const description = declared.hierarchical
        ? `${declared.plural} can sit under one another. Counts leave out the trash. Choose a count to list its items, with those in its sub${plural}.`
        : 'Counts leave out the trash. Choose a count to list its items.';

    const columns: Column<TermRow>[] = [
        {
            id: 'name',
            owner: 'core',
            header: 'Name',
            cell: (row) => (
                <>
                    {branches ? (
                        <span className="font-semibold">
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
                                <span className="sr-only">{`, under ${row.parentName}`}</span>
                            )}
                        </span>
                    ) : (
                        <span
                            data-test="term-name"
                            data-depth={0}
                            className="font-semibold"
                        >
                            {row.path}
                        </span>
                    )}
                    <RowLinks
                        label={`Actions for ${row.name}`}
                        more={`More actions for ${row.name}`}
                        items={[
                            {
                                id: 'edit',
                                owner: 'core',
                                label: 'Edit',
                                onSelect: () => setEditing(row.id),
                            },
                            {
                                id: 'delete',
                                owner: 'core',
                                label: 'Delete',
                                destructive: true,
                                form: TermController.destroy.form({
                                    taxonomy,
                                    term: row.id,
                                }),
                                wrap: (button) => (
                                    <ConfirmAction
                                        title={`Delete “${row.name}”?`}
                                        description={deleteWarning(
                                            row,
                                            declared,
                                            types,
                                        )}
                                        confirmLabel={`Delete ${singular}`}
                                    >
                                        {button}
                                    </ConfirmAction>
                                ),
                            },
                        ]}
                    />
                </>
            ),
        },
        {
            id: 'slug',
            owner: 'core',
            header: 'Address',
            cell: (row) => (
                <span className="text-ink-2 font-mono text-xs">{row.slug}</span>
            ),
        },
        // One column for each listed type that uses the taxonomy (I-225).
        ...types.map((type): Column<TermRow> => ({
            id: `count-${type.key}`,
            owner: 'core',
            header: type.plural,
            className: 'text-right',
            cell: (row) => {
                const count = row.counts[type.key] ?? 0;

                if (count === 0) {
                    return (
                        <span className="text-ink-2 font-mono tabular-nums">
                            0
                        </span>
                    );
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
                        className="inline-flex min-h-6 min-w-6 items-center justify-end font-mono tabular-nums hover:underline"
                    >
                        {count.toLocaleString()}
                        <span className="sr-only">{` ${(count === 1 ? type.singular : type.plural).toLowerCase()} in ${row.name}`}</span>
                    </Link>
                );
            },
        })),
    ];

    return (
        <>
            <Head title={declared.plural} />

            <div className="space-y-4 p-6">
                <PageHeader
                    title={heading}
                    description={description}
                    action={
                        setup !== null && (
                            <Button variant="outline" asChild>
                                <Link href={setup} data-test="term-setup">
                                    Set up {declared.plural}
                                </Link>
                            </Button>
                        )
                    }
                />

                {tabs.length > 1 && (
                    <TabStrip
                        label="Kind"
                        current={taxonomy}
                        tabs={tabs.map((tab) => ({
                            key: tab.segment,
                            label: tab.label,
                            count: tab.count,
                            href: termsIndex({ taxonomy: tab.segment }),
                        }))}
                    />
                )}

                <div className="grid items-start gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
                    <Panel
                        data-test="term-add"
                        aria-labelledby="term-add-heading"
                        className="border-t first:border-t"
                    >
                        <PanelHeader>
                            <h2 id="term-add-heading">New {singular}</h2>
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
                                            Add {singular}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>

                    <div className="grid min-w-0 gap-3">
                        <form method="get" role="search" className="flex gap-2">
                            <Input
                                name="search"
                                defaultValue={search}
                                placeholder={`Find a ${singular} by name`}
                                aria-label="Search"
                                className="max-w-xs"
                            />
                            <Button variant="outline">Search</Button>
                        </form>

                        <DataTable
                            columns={columns}
                            rows={rows}
                            rowKey={(row) => row.id}
                            caption={declared.plural}
                            empty={
                                <EmptyState
                                    kind={emptyKind}
                                    title={
                                        search
                                            ? `No ${plural} start with “${search}”`
                                            : `No ${plural} yet`
                                    }
                                    description={
                                        search
                                            ? undefined
                                            : 'Use the form to add the first one.'
                                    }
                                    clearHref={termsIndex({ taxonomy }).url}
                                />
                            }
                        />

                        <Pager
                            labels={{ newer: 'Previous', older: 'Next' }}
                            newer={
                                newer
                                    ? termsIndex(
                                          { taxonomy },
                                          {
                                              query: {
                                                  before: newer,
                                                  ...withSearch,
                                              },
                                          },
                                      ).url
                                    : null
                            }
                            older={
                                older
                                    ? termsIndex(
                                          { taxonomy },
                                          {
                                              query: {
                                                  after: older,
                                                  ...withSearch,
                                              },
                                          },
                                      ).url
                                    : null
                            }
                        />
                    </div>
                </div>
            </div>

            <Sheet
                open={editingRow !== undefined}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <SheetContent className="w-full sm:max-w-[480px]">
                    <SheetHeader>
                        <SheetTitle>Edit {singular}</SheetTitle>
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
                                            className="text-ink-2 text-xs"
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
                                            Save
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            type="button"
                                            onClick={() => setEditing(null)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}
