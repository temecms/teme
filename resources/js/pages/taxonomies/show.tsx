import { Form, Head, Link } from '@inertiajs/react';
import { lazy, Suspense, useState } from 'react';
import TaxonomiesController from '@/actions/App/Http/Admin/TaxonomiesController';
import { ConfirmAction } from '@/components/confirm-action';
import { SettingsPanel } from '@/components/fields/settings-panel';
import { SettingsSaveBar } from '@/components/fields/settings-save-bar';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/list/page-header';
import {
    Fact,
    Facts,
    Field,
    Flag,
    Hint,
    SourceBadge,
    toggle,
} from '@/components/types/builder-parts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { navIcon } from '@/lib/nav-icons';
import { show as addresses } from '@/routes/settings/addresses';
import { index as taxonomiesIndex } from '@/routes/taxonomies';
import type { TaxonomyPage } from '@/types';

const IconPicker = lazy(() => import('@/components/types/icon-picker'));

/** "Films", "Posts and Films", "Movies, TV Shows and Games". */
function andList(names: string[]): string {
    return names.length < 2
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/**
 * One taxonomy: made here and edited, declared by code and only looked at,
 * or new. A locked site shows the same pages with nothing to change.
 */
export default function TaxonomiesShow(page: TaxonomyPage) {
    const title = page.mode === 'new' ? 'New taxonomy' : page.taxonomy.plural;

    return (
        <>
            <Head title={title} />

            <div className="max-w-[768px] min-w-0 space-y-6 p-6">
                <PageHeader
                    title={title}
                    badge={
                        <SourceBadge
                            mode={page.mode === 'new' ? 'new' : 'shown'}
                            source={page.source}
                        />
                    }
                    description={headerLine(page)}
                    action={
                        page.termsUrl !== null && (
                            <Button variant="outline" asChild>
                                <Link href={page.termsUrl}>
                                    Open {page.taxonomy.plural}
                                </Link>
                            </Button>
                        )
                    }
                />

                {page.mode === 'view' && page.source === 'code' && (
                    <CodeNote page={page} />
                )}
                {page.mode === 'view' && page.source !== 'code' && <LockNote />}
                {page.source === 'refused' && page.reason !== null && (
                    <p>Can’t be used: {page.reason}</p>
                )}

                {page.mode === 'new' || page.mode === 'edit' ? (
                    <TaxonomyForm
                        key={`${page.mode}:${page.taxonomy.key}`}
                        page={page}
                    />
                ) : (
                    <ViewPanels page={page} />
                )}

                {page.mode === 'edit' && <DeletePanel page={page} />}
            </div>
        </>
    );
}

TaxonomiesShow.layout = {
    breadcrumbs: [
        { title: 'Taxonomies', href: taxonomiesIndex() },
        { title: 'Taxonomy', href: taxonomiesIndex() },
    ],
};

function TaxonomyForm({ page }: { page: TaxonomyPage }) {
    const saved = page.taxonomy;
    const [singular, setSingular] = useState(saved.singular);
    const [plural, setPlural] = useState(saved.plural);
    const [key, setKey] = useState(saved.key);
    const [segment, setSegment] = useState(saved.segment);
    const [icon, setIcon] = useState(saved.icon);
    const [hierarchical, setHierarchical] = useState(saved.hierarchical);
    const [isPublic, setIsPublic] = useState(saved.public);
    const [base, setBase] = useState('');
    const [types, setTypes] = useState(saved.types);
    const [pickerMounted, setPickerMounted] = useState(false);
    const [pickerOpen, setPickerOpen] = useState(false);
    const creating = page.mode === 'new';
    const Icon = navIcon(icon);
    const lowered =
        singular.trim() === ''
            ? 'taxonomy'
            : singular.trim().toLocaleLowerCase();
    // Nested can't turn flat while any term sits under another.
    const arrangementLocked = !creating && saved.hierarchical && page.under > 0;
    // Whether terms have public pages can't change while there are terms.
    const publicLocked = !creating && page.terms > 0;
    const lockedTypes = page.options.types.filter((type) => type.locked);
    // A taxonomy that isn't public and has no terms can be made public: it
    // takes the base typed here, as a new taxonomy does.
    const needsBase = isPublic && (creating || !saved.public);
    const typed = base.trim() === '' ? 'genre' : base.trim();

    return (
        <>
            <Form
                {...(creating
                    ? TaxonomiesController.store.form()
                    : TaxonomiesController.update.form(saved.key))}
                className="grid gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.key} />
                        <InputError message={errors.taxonomy} />
                        <input type="hidden" name="icon" value={icon} />

                        <SettingsPanel
                            title="Names"
                            description="What the taxonomy is called across the admin."
                        >
                            <div className="grid gap-5 sm:grid-cols-2">
                                <Field
                                    id="taxonomy-singular"
                                    name="singular"
                                    label="Singular name"
                                    value={singular}
                                    error={errors.singular}
                                    onChange={setSingular}
                                    hint={`As in “Add ${lowered}”.`}
                                />
                                <Field
                                    id="taxonomy-plural"
                                    name="plural"
                                    label="Plural name"
                                    value={plural}
                                    error={errors.plural}
                                    onChange={setPlural}
                                    hint="The menu’s entry and the term screen’s title."
                                />
                            </div>
                            {creating ? (
                                <Field
                                    id="taxonomy-key"
                                    name="key"
                                    label="Key"
                                    value={key}
                                    error={errors.key}
                                    onChange={setKey}
                                    hint="Stored on every term, so it can’t be changed once the taxonomy is made. Lower-case letters, digits and underscores, starting with a letter."
                                />
                            ) : (
                                <div className="grid gap-2">
                                    <p className="text-sm font-medium">Key</p>
                                    <code id="taxonomy-key">{saved.key}</code>
                                    <Hint>
                                        Stored on every term, so it never
                                        changes.
                                    </Hint>
                                </div>
                            )}
                            <div className="grid gap-2">
                                <p className="text-sm font-medium">Icon</p>
                                <div className="flex items-center gap-2">
                                    <span className="bg-surface-2 flex size-8 items-center justify-center rounded-md border">
                                        <Icon aria-hidden className="size-4" />
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        data-test="taxonomy-icon-change"
                                        onClick={() => {
                                            setPickerMounted(true);
                                            setPickerOpen(true);
                                        }}
                                    >
                                        Change icon
                                    </Button>
                                </div>
                                <Hint>
                                    Beside the name on Site › Taxonomies.
                                </Hint>
                            </div>
                        </SettingsPanel>

                        <SettingsPanel
                            title="Terms"
                            description="Whether they sit under one another, and which types use them."
                        >
                            <div className="grid gap-2">
                                <p
                                    id="taxonomy-arrangement-label"
                                    className="text-sm font-medium"
                                >
                                    How terms are arranged
                                </p>
                                <div
                                    role="radiogroup"
                                    aria-labelledby="taxonomy-arrangement-label"
                                    className="grid gap-2"
                                >
                                    {(
                                        [
                                            [
                                                false,
                                                `Flat, as tags: each ${lowered} stands on its own`,
                                                'taxonomy-flat',
                                            ],
                                            [
                                                true,
                                                `Nested, as categories: a ${lowered} can sit under another`,
                                                'taxonomy-nested',
                                            ],
                                        ] as const
                                    ).map(([nested, label, id]) => (
                                        <div
                                            key={id}
                                            className="flex items-center gap-2"
                                        >
                                            <input
                                                id={id}
                                                type="radio"
                                                name="hierarchical"
                                                value={nested ? '1' : '0'}
                                                className="size-4"
                                                checked={
                                                    hierarchical === nested
                                                }
                                                disabled={arrangementLocked}
                                                onChange={() =>
                                                    setHierarchical(nested)
                                                }
                                            />
                                            <Label htmlFor={id}>{label}</Label>
                                        </div>
                                    ))}
                                </div>
                                {arrangementLocked && (
                                    // A disabled radio isn't sent: this carries the saved arrangement.
                                    <input
                                        type="hidden"
                                        name="hierarchical"
                                        value="1"
                                    />
                                )}
                                {arrangementLocked && (
                                    <p
                                        id="taxonomy-arrangement-reason"
                                        className="text-sm"
                                    >
                                        {flattenReason(
                                            saved.singular,
                                            saved.plural,
                                            page.under,
                                        )}
                                    </p>
                                )}
                                <Hint>
                                    In the editor, flat terms are typed, as tags
                                    are; nested ones are ticked in a list, as
                                    categories are.
                                </Hint>
                                <InputError message={errors.hierarchical} />
                            </div>
                            <div className="grid gap-2">
                                <p className="text-sm font-medium">Used by</p>
                                {page.options.types.map((type) => (
                                    <Flag
                                        key={type.key}
                                        id={`taxonomy-type-${type.key}`}
                                        name="types[]"
                                        value={type.key}
                                        label={type.plural}
                                        checked={types.includes(type.key)}
                                        disabled={type.locked}
                                        onCheckedChange={(checked) =>
                                            setTypes((current) =>
                                                toggle(
                                                    current,
                                                    type.key,
                                                    checked === true,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                                {lockedTypes.length > 0 && (
                                    <p
                                        id="taxonomy-types-reason"
                                        className="text-sm"
                                    >
                                        {takenOffReason(
                                            lockedTypes.map(
                                                (type) => type.plural,
                                            ),
                                            saved.plural,
                                        )}
                                    </p>
                                )}
                                <Hint>
                                    Their editors offer a {lowered} picker, and
                                    a {lowered}’s page lists their items. The
                                    same choice as each type’s Taxonomies, on
                                    Site › Types.
                                </Hint>
                                <InputError message={errors.types} />
                            </div>
                        </SettingsPanel>

                        <SettingsPanel
                            title="In the admin"
                            description="Where its term screen is."
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="taxonomy-segment">
                                    Admin address
                                </Label>
                                <div className="flex min-w-0 items-center gap-2">
                                    <code className="shrink-0">/admin/</code>
                                    <Input
                                        id="taxonomy-segment"
                                        name="segment"
                                        value={segment}
                                        onChange={(event) =>
                                            setSegment(event.target.value)
                                        }
                                    />
                                </div>
                                <Hint>
                                    Where the term screen is in the admin.
                                    Changing it moves no public page; only
                                    bookmarks to the old admin address stop
                                    working.
                                </Hint>
                                <InputError message={errors.segment} />
                            </div>
                        </SettingsPanel>

                        <SettingsPanel
                            title="On the site"
                            description="Whether each term has a page of its own."
                        >
                            <Flag
                                id="taxonomy-public"
                                name="public"
                                label="Terms have public pages"
                                checked={isPublic}
                                disabled={publicLocked}
                                error={errors.public}
                                onCheckedChange={(checked) =>
                                    setIsPublic(checked === true)
                                }
                                hint={`Each ${lowered} gets a page listing the published items of every type that uses it, newest first, with its feed.`}
                                reason={
                                    publicLocked
                                        ? `${saved.plural} have terms (${page.terms}), so whether they have public pages can’t change.`
                                        : undefined
                                }
                            />
                            {needsBase && (
                                <Field
                                    id="taxonomy-base"
                                    name="base"
                                    label="Address base"
                                    value={base}
                                    error={errors.base}
                                    onChange={setBase}
                                    placeholder="genre"
                                    hint={`The first part of each ${lowered}’s address, as in /${typed}/action/. Once the taxonomy is made, this changes in Settings › Addresses.`}
                                />
                            )}
                            {!creating && saved.public && isPublic && (
                                <AddressLine page={page} />
                            )}
                        </SettingsPanel>

                        <SettingsSaveBar
                            label={creating ? 'Add taxonomy' : 'Save taxonomy'}
                            processing={processing}
                            testId="taxonomy-save"
                        />
                    </>
                )}
            </Form>

            {pickerMounted && (
                <Suspense fallback={null}>
                    <IconPicker
                        open={pickerOpen}
                        onOpenChange={setPickerOpen}
                        value={icon}
                        onChoose={setIcon}
                    />
                </Suspense>
            )}
        </>
    );
}

function ViewPanels({ page }: { page: TaxonomyPage }) {
    const taxonomy = page.taxonomy;
    const used = page.options.types
        .filter((type) => taxonomy.types.includes(type.key))
        .map((type) => type.plural);

    return (
        <div className="grid gap-6">
            <SettingsPanel
                as="section"
                title="Names"
                description="What the taxonomy is called across the admin."
            >
                <Facts>
                    <Fact label="Singular name">{taxonomy.singular}</Fact>
                    <Fact label="Plural name">{taxonomy.plural}</Fact>
                    <Fact label="Key">
                        <code>{taxonomy.key}</code>
                    </Fact>
                </Facts>
            </SettingsPanel>
            <SettingsPanel
                as="section"
                title="Terms"
                description="Whether they sit under one another, and which types use them."
            >
                <Facts>
                    <Fact label="Arranged">
                        {taxonomy.hierarchical
                            ? 'Nested, as categories'
                            : 'Flat, as tags'}
                    </Fact>
                    <Fact label="Used by">
                        {used.length === 0 ? 'None' : andList(used)}
                    </Fact>
                    <Fact label="Terms">
                        {page.terms}
                        {page.termsUrl !== null && (
                            <>
                                {' '}
                                <Link
                                    href={page.termsUrl}
                                    className="text-accent-ink underline underline-offset-2"
                                >
                                    Open {taxonomy.plural}
                                </Link>
                            </>
                        )}
                    </Fact>
                </Facts>
            </SettingsPanel>
            <SettingsPanel
                as="section"
                title="On the site"
                description="Whether each term has a page of its own."
            >
                <Facts>
                    <Fact label="Public pages">
                        {taxonomy.public ? 'Yes' : 'No'}
                    </Fact>
                    {page.address !== null && (
                        <Fact label="Public address">
                            <code>{page.address}</code>{' '}
                            <Link
                                href={addresses.url()}
                                className="text-accent-ink underline underline-offset-2"
                            >
                                Change in Settings › Addresses
                            </Link>
                        </Fact>
                    )}
                </Facts>
            </SettingsPanel>
        </div>
    );
}

function AddressLine({ page }: { page: TaxonomyPage }) {
    return (
        <div className="grid gap-2">
            <p className="text-sm font-medium">Public address</p>
            <p className="text-pretty">
                <code>{page.address}</code>
                {page.example !== null && page.address !== null ? (
                    <>
                        {' '}
                        For example{' '}
                        {page.address.replace('{slug}', page.example)}.{' '}
                    </>
                ) : (
                    ' '
                )}
                <Link
                    href={addresses.url()}
                    className="text-accent-ink underline underline-offset-2"
                >
                    Change in Settings › Addresses
                </Link>
                , which keeps every old address working as a redirect.
            </p>
        </div>
    );
}

function DeletePanel({ page }: { page: TaxonomyPage }) {
    const plural = page.taxonomy.plural;
    const reason = deleteReason(plural, page.terms);

    return (
        <SettingsPanel as="section" title="Delete this taxonomy">
            {reason !== null && <p id="taxonomy-delete-reason">{reason}</p>}
            {page.termsUrl !== null && page.terms > 0 && (
                <p className="flex flex-wrap gap-3">
                    <Link
                        href={page.termsUrl}
                        className="text-accent-ink hover:underline"
                    >
                        Open {plural}
                    </Link>
                </p>
            )}
            {reason === null ? (
                <Form {...TaxonomiesController.destroy.form(page.taxonomy.key)}>
                    {() => (
                        <ConfirmAction
                            title={`Delete ${plural}?`}
                            description="It has no terms, so only the taxonomy goes."
                            confirmLabel={`Delete ${plural}`}
                        >
                            <Button
                                type="submit"
                                variant="destructive"
                                data-test="taxonomy-delete"
                            >
                                Delete {plural}
                            </Button>
                        </ConfirmAction>
                    )}
                </Form>
            ) : (
                <Button
                    type="button"
                    variant="destructive"
                    disabled
                    data-test="taxonomy-delete"
                    aria-describedby="taxonomy-delete-reason"
                >
                    Delete {plural}
                </Button>
            )}
        </SettingsPanel>
    );
}

function CodeNote({ page }: { page: TaxonomyPage }) {
    return (
        <p>
            {page.taxonomy.plural} are declared by code, so they can’t be
            changed or deleted here.
            {page.taxonomy.public && (
                <>
                    {' '}
                    Their address base can still change:{' '}
                    <Link
                        href={addresses.url()}
                        className="text-accent-ink underline underline-offset-2"
                    >
                        Settings › Addresses
                    </Link>
                    .
                </>
            )}
        </p>
    );
}

function LockNote() {
    return (
        <div className="grid gap-1 text-sm">
            <p>
                Taxonomies are locked on this site (TEME_TYPES_LOCKED): change
                them where they’re made, and bring them here with{' '}
                <code>php artisan teme:types:import</code>.
            </p>
            <p>
                You can still look at each taxonomy, and download
                teme-types.json.
            </p>
        </div>
    );
}

/** The line under the title, for the mode the page is in. */
function headerLine(page: TaxonomyPage): string {
    if (page.mode === 'new') {
        return 'A way of your own to group content, such as genres or studios. Its term screen appears in the menu as soon as it’s added.';
    }

    if (page.source === 'code') {
        return 'Declared by code, and shown here so you can see how it’s set up.';
    }

    const made =
        page.made === null ? 'Made here.' : `Made here on ${page.made}.`;
    const base = `${made} Terms: ${page.terms}.`;

    if (page.mode === 'view') {
        return base;
    }

    return `${base} Changes reach the menu, the editor and the site as soon as they’re saved.`;
}

/** Why a nested taxonomy with terms under others can't become flat. */
function flattenReason(
    singular: string,
    plural: string,
    under: number,
): string {
    return under === 1
        ? `1 ${singular.toLocaleLowerCase()} sits under others, so ${plural} can’t become flat. Move it to the top first.`
        : `${under} ${plural.toLocaleLowerCase()} sit under others, so ${plural} can’t become flat. Move them to the top first.`;
}

/** Why types whose items carry the taxonomy's terms can't be taken off. */
function takenOffReason(types: string[], plural: string): string {
    const names = andList(types);
    const lowered = plural.toLocaleLowerCase();

    if (types.length === 1) {
        return `${names} has items with ${lowered} chosen, so it can’t be taken off. Remove the ${lowered} from its items first.`;
    }

    return types.length === 2
        ? `${names} have items with ${lowered} chosen, so neither can be taken off. Remove the ${lowered} from their items first.`
        : `${names} have items with ${lowered} chosen, so none of them can be taken off. Remove the ${lowered} from their items first.`;
}

/** Why a taxonomy with terms can't be deleted, or null when it can. */
function deleteReason(plural: string, terms: number): string | null {
    if (terms === 0) {
        return null;
    }

    return terms === 1
        ? `${plural} have 1 term, so the taxonomy can’t be deleted. Delete it first.`
        : `${plural} have ${terms} terms, so the taxonomy can’t be deleted. Delete them first.`;
}
