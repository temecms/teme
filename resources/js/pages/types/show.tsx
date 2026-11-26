import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { lazy, Suspense, useId, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import TypesController from '@/actions/App/Http/Admin/TypesController';
import { ConfirmAction } from '@/components/confirm-action';
import {
    blankProse,
    CardProse,
    proseText,
} from '@/components/editor/cards/card-prose';
import type { CardProseValue } from '@/components/editor/cards/card-prose';
import InputError from '@/components/input-error';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import { SaveBar } from '@/components/patterns/save-bar';
import {
    Fact,
    Facts,
    Field,
    Flag,
    Hint,
    IconName,
    toggle,
} from '@/components/types/builder-parts';
import { Button } from '@/components/ui/button';
import { IconTile } from '@/components/ui/icon-tile';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { PluginMark } from '@/components/ui/plugin-mark';
import { RadioGroup, RadioGroupChoice } from '@/components/ui/radio-group';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useSectionCrumb } from '@/lib/crumbs';
import { __, __n } from '@/lib/i18n';
import { navIcon } from '@/lib/nav-icons';
import { show as addresses } from '@/routes/settings/addresses';
import { index as typesIndex, show } from '@/routes/types';
import type { TypeComments, TypePage } from '@/types';

/** Icon tile beside Singular and Plural (docs/architecture/design-system.md, section 4.48). */
const NAMES_STYLE = {
    '--names-cols': 'auto minmax(0, 1fr) minmax(0, 1fr)',
} as CSSProperties;

const IconPicker = lazy(() => import('@/components/types/icon-picker'));

/** A section's wrapper: a place an address can point to. */
function Section({ id, children }: { id: string; children: ReactNode }) {
    return (
        <div id={id} tabIndex={-1} className="scroll-mt-16">
            {children}
        </div>
    );
}

/**
 * One type: made here and edited, declared by code and only looked at, or
 * new. A locked site shows the same pages with nothing to change.
 */
export default function TypesShow(page: TypePage) {
    const title = page.mode === 'new' ? __('New type') : page.type.plural;
    const items = itemsPhrase(page.items, page.trashed);
    const siteCrumb = useSectionCrumb('site');

    setLayoutProps({
        breadcrumbs: [
            siteCrumb,
            { title: __('Types'), href: typesIndex() },
            {
                title,
                href: page.mode === 'new' ? typesIndex() : show(page.type.key),
            },
        ],
    });
    // Discard starts the form again from the saved type.
    const [discards, setDiscards] = useState(0);

    return (
        <FormPage
            title={title}
            description={headerLine(page)}
            actions={
                <>
                    <Button variant="ghost" asChild>
                        <Link href={typesIndex().url}>
                            <ArrowLeft aria-hidden="true" />
                            {__('All types')}
                        </Link>
                    </Button>
                    {page.fields.url !== null && (
                        <Button variant="neutral" asChild>
                            <Link href={page.fields.url}>
                                {__('Manage fields')}
                            </Link>
                        </Button>
                    )}
                </>
            }
        >
            <Head title={title} />

            <div className="grid gap-4">
                {page.mode === 'view' && <CodeNote page={page} />}
                {page.mode === 'locked' && <LockNote />}
                {page.mode === 'plugin' && <PluginNote page={page} />}
                {page.nameClash !== null && (
                    <Notice tone="warn">
                        <p>{page.nameClash}</p>
                    </Notice>
                )}
                {page.source === 'refused' && page.reason !== null && (
                    <Notice tone="danger">
                        <p>Can’t be used: {page.reason}</p>
                    </Notice>
                )}

                {page.mode === 'new' || page.mode === 'edit' ? (
                    <TypeForm
                        key={`${page.mode}:${page.type.key}:${discards}`}
                        page={page}
                        onDiscard={() => setDiscards((count) => count + 1)}
                    />
                ) : (
                    <ViewPanels page={page} items={items} />
                )}
            </div>
        </FormPage>
    );
}

/** What the form holds, beyond what is typed into it. */
type TypeFormState = {
    singular: string;
    plural: string;
    key: string;
    segment: string;
    icon: string;
    single: boolean;
    isPublic: boolean;
    archive: boolean;
    searchable: boolean;
    comments: TypeComments;
    taxonomies: string[];
    supports: string[];
    permissions: 'shared' | 'own';
    archiveHeading: string;
    archiveIntro: CardProseValue;
};

/** The saved type as the form starts from it. */
function savedState(saved: TypePage['type']): TypeFormState {
    return {
        singular: saved.singular,
        plural: saved.plural,
        key: saved.key,
        segment: saved.segment,
        icon: saved.icon,
        single: saved.single,
        isPublic: saved.public,
        archive: saved.public && saved.archive,
        searchable: saved.public && saved.searchable,
        comments: saved.public ? saved.comments : null,
        taxonomies: saved.taxonomies,
        supports: saved.supports,
        permissions: saved.permissions,
        archiveHeading: saved.archive_heading ?? '',
        archiveIntro:
            (saved.archive_intro as CardProseValue | null) ?? blankProse(),
    };
}

// What the form holds, in one shape for the saved type and for the state; the
// ticked lists sorted, so unticking and ticking again leaves the form clean.
const snapshot = (value: TypeFormState): string =>
    JSON.stringify({
        ...value,
        taxonomies: [...value.taxonomies].sort(),
        supports: [...value.supports].sort(),
    });

function TypeForm({
    page,
    onDiscard,
}: {
    page: TypePage;
    onDiscard: () => void;
}) {
    const saved = page.type;
    const [singular, setSingular] = useState(saved.singular);
    const [plural, setPlural] = useState(saved.plural);
    const [key, setKey] = useState(saved.key);
    const [segment, setSegment] = useState(saved.segment);
    const [icon, setIcon] = useState(saved.icon);
    const [single, setSingle] = useState(saved.single);
    const [isPublic, setIsPublic] = useState(saved.public);
    // A type that isn't public has no archive, search or comments, whatever a
    // saved row said; its boxes are locked off and sent as off.
    const [archive, setArchive] = useState(saved.public && saved.archive);
    const [searchable, setSearchable] = useState(
        saved.public && saved.searchable,
    );
    const [comments, setComments] = useState<TypeComments>(
        saved.public ? saved.comments : null,
    );
    const [taxonomies, setTaxonomies] = useState(saved.taxonomies);
    const [supports, setSupports] = useState(saved.supports);
    const [permissions, setPermissions] = useState(saved.permissions);
    const [archiveHeading, setArchiveHeading] = useState(
        saved.archive_heading ?? '',
    );
    const [archiveIntro, setArchiveIntro] = useState<CardProseValue>(
        (saved.archive_intro as CardProseValue | null) ?? blankProse(),
    );
    const [pattern, setPattern] = useState('');
    const [pickerMounted, setPickerMounted] = useState(false);
    const [pickerOpen, setPickerOpen] = useState(false);
    const creating = page.mode === 'new';
    const publicLocked = page.items > 0;
    const singleLocked = page.items > 1 && !saved.single;
    const siteOff = !isPublic;
    const Icon = navIcon(icon);
    const named = plural.trim();
    const state: TypeFormState = {
        singular,
        plural,
        key,
        segment,
        icon,
        single,
        isPublic,
        archive,
        searchable,
        comments,
        taxonomies,
        supports,
        permissions,
        archiveHeading,
        archiveIntro,
    };

    return (
        <>
            <div className="grid gap-4">
                <Form
                    {...(creating
                        ? TypesController.store.form()
                        : TypesController.update.form(saved.key))}
                    setDefaultsOnSuccess
                    className="grid gap-4"
                >
                    {({ processing, errors, isDirty }) => (
                        <>
                            <InputError message={errors.key} />
                            <InputError message={errors.type} />
                            <input type="hidden" name="icon" value={icon} />

                            <Section id="type-section-names">
                                <FormPanel
                                    title="Names"
                                    description="What the type is called across the admin."
                                >
                                    <div
                                        className="grid items-start gap-5 sm:grid-cols-(--names-cols)"
                                        style={NAMES_STYLE}
                                    >
                                        <div className="grid justify-items-start gap-2">
                                            <IconTile icon={Icon} size="lg" />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                data-test="type-icon-change"
                                                onClick={() => {
                                                    setPickerMounted(true);
                                                    setPickerOpen(true);
                                                }}
                                            >
                                                {__('Change icon')}
                                            </Button>
                                        </div>
                                        <Field
                                            id="type-singular"
                                            name="singular"
                                            label="Singular name"
                                            value={singular}
                                            error={errors.singular}
                                            onChange={setSingular}
                                            hint={`As in “Add new ${singular.toLocaleLowerCase()}”.`}
                                        />
                                        <Field
                                            id="type-plural"
                                            name="plural"
                                            label="Plural name"
                                            value={plural}
                                            error={errors.plural}
                                            onChange={setPlural}
                                            hint="The menu’s entry and the list’s title."
                                        />
                                    </div>
                                    {creating ? (
                                        <Field
                                            id="type-key"
                                            name="key"
                                            label="Key"
                                            value={key}
                                            error={errors.key}
                                            onChange={setKey}
                                            hint="Stored on every item, so it can’t be changed once the type is made. Lower-case letters, digits and underscores, starting with a letter."
                                        />
                                    ) : (
                                        <div className="grid gap-1">
                                            <p className="text-small text-ink-2 font-medium">
                                                Key
                                            </p>
                                            <code id="type-key">
                                                {saved.key}
                                            </code>
                                            <Hint>
                                                Stored on every item, so it
                                                never changes.
                                            </Hint>
                                        </div>
                                    )}
                                </FormPanel>
                            </Section>

                            <Section id="type-section-admin">
                                <FormPanel
                                    title="In the admin"
                                    description="Where its list is, and how the menu opens it."
                                >
                                    <div className="grid gap-1">
                                        <Label htmlFor="type-segment">
                                            Admin address
                                        </Label>
                                        <div className="flex min-w-0 items-center gap-2">
                                            <code className="shrink-0">
                                                /admin/
                                            </code>
                                            <Input
                                                id="type-segment"
                                                name="segment"
                                                value={segment}
                                                onChange={(event) =>
                                                    setSegment(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <Hint>
                                            Where the list is in the admin.
                                            Changing it moves no public page;
                                            only bookmarks to the old admin
                                            address stop working.
                                        </Hint>
                                        <InputError message={errors.segment} />
                                    </div>
                                    <Flag
                                        id="type-single"
                                        control="switch"
                                        name="single"
                                        label="One item only"
                                        checked={single}
                                        disabled={singleLocked}
                                        error={errors.single}
                                        onCheckedChange={(checked) =>
                                            setSingle(checked === true)
                                        }
                                        hint="For something there’s one of, such as an About page: the menu opens that item straight in the editor, and no second one can be added."
                                        reason={
                                            singleLocked
                                                ? `${saved.plural} have ${page.items} items, so they can’t become one item only.`
                                                : undefined
                                        }
                                    />
                                </FormPanel>
                            </Section>

                            <Section id="type-section-site">
                                <FormPanel
                                    title="On the site"
                                    description="Whether its items have pages of their own, and what those pages offer."
                                >
                                    <Flag
                                        id="type-public"
                                        control="switch"
                                        name="public"
                                        label="Items have public addresses"
                                        checked={isPublic}
                                        disabled={publicLocked}
                                        error={errors.public}
                                        onCheckedChange={(checked) => {
                                            const next = checked === true;
                                            setIsPublic(next);

                                            if (!next) {
                                                setArchive(false);
                                                setSearchable(false);
                                                setComments(null);
                                            }
                                        }}
                                        hint="Each item gets its own page on the site. Without them, items are content that other screens use, as regions are."
                                        reason={
                                            publicLocked
                                                ? `${saved.plural} have items (${page.items}, trashed ones included), so whether they’re public can’t change.`
                                                : undefined
                                        }
                                    />
                                    {isPublic &&
                                        (creating ? (
                                            <Field
                                                id="type-pattern"
                                                name="pattern"
                                                label="Public address"
                                                value={pattern}
                                                error={errors.pattern}
                                                onChange={setPattern}
                                                placeholder={
                                                    segment.trim() === ''
                                                        ? '/{segment}/{slug}/'
                                                        : `/${segment}/{slug}/`
                                                }
                                                hint={
                                                    'Where each item’s page is. {slug} is the item’s own address; {year}, {month}, {day} and {id} can be used too. Once the type is made, this changes in Settings › Addresses.'
                                                }
                                            />
                                        ) : (
                                            <PatternLine page={page} />
                                        ))}
                                    <Flag
                                        id="type-archive"
                                        control="switch"
                                        name="archive"
                                        label="Has an archive page"
                                        checked={archive}
                                        disabled={siteOff}
                                        error={errors.archive}
                                        onCheckedChange={(checked) =>
                                            setArchive(checked === true)
                                        }
                                        hint="A page on the site listing every published item, newest first."
                                    />
                                    <Flag
                                        id="type-searchable"
                                        control="switch"
                                        name="searchable"
                                        label="Shown in site search"
                                        checked={searchable}
                                        disabled={siteOff}
                                        error={errors.searchable}
                                        onCheckedChange={(checked) =>
                                            setSearchable(checked === true)
                                        }
                                        hint="Search results link to each item’s own page."
                                    />
                                    <CommentsField
                                        value={comments}
                                        disabled={siteOff}
                                        error={errors.comments}
                                        onChange={setComments}
                                    />
                                </FormPanel>
                            </Section>

                            <Section id="type-section-archive">
                                <FormPanel
                                    title="Archive page"
                                    description="The heading and opening text of the page listing every published item. Themes draw them above the list."
                                >
                                    <Field
                                        id="type-archive-heading"
                                        name="archive_heading"
                                        label="Heading"
                                        value={archiveHeading}
                                        error={errors.archive_heading}
                                        onChange={setArchiveHeading}
                                        placeholder={plural}
                                        hint="Leave it empty to use the plural name."
                                    />
                                    <div className="grid gap-2">
                                        <Label>{__('Opening text')}</Label>
                                        <div className="border-line rounded-md border">
                                            <CardProse
                                                value={archiveIntro}
                                                label={__('Opening text')}
                                                placeholder={__(
                                                    'A few words above the list',
                                                )}
                                                onChange={setArchiveIntro}
                                            />
                                        </div>
                                        <input
                                            type="hidden"
                                            name="archive_intro"
                                            value={
                                                proseText(archiveIntro) === ''
                                                    ? ''
                                                    : JSON.stringify(
                                                          archiveIntro,
                                                      )
                                            }
                                        />
                                        <InputError
                                            message={errors.archive_intro}
                                        />
                                    </div>
                                </FormPanel>
                            </Section>

                            <Section id="type-section-editor">
                                <FormPanel
                                    title="In the editor"
                                    description="What the editor offers beside the writing."
                                >
                                    <div className="grid gap-2">
                                        <p className="text-small text-ink-2 font-medium">
                                            Taxonomies
                                        </p>
                                        {page.options.taxonomies.map(
                                            (taxonomy) => (
                                                <Flag
                                                    key={taxonomy.key}
                                                    id={`type-taxonomy-${taxonomy.key}`}
                                                    name="taxonomies[]"
                                                    value={taxonomy.key}
                                                    label={taxonomy.plural}
                                                    checked={taxonomies.includes(
                                                        taxonomy.key,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setTaxonomies(
                                                            (current) =>
                                                                toggle(
                                                                    current,
                                                                    taxonomy.key,
                                                                    checked ===
                                                                        true,
                                                                ),
                                                        )
                                                    }
                                                />
                                            ),
                                        )}
                                        <Hint>
                                            Their pickers sit in the editor’s
                                            side panel. One can’t be taken away
                                            while items still have its terms.
                                        </Hint>
                                        <InputError
                                            message={errors.taxonomies}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <p className="text-small text-ink-2 font-medium">
                                            Features
                                        </p>
                                        {page.options.features.map(
                                            (feature) => (
                                                <Flag
                                                    key={feature.key}
                                                    id={`type-feature-${feature.key}`}
                                                    name="supports[]"
                                                    value={feature.key}
                                                    label={feature.label}
                                                    checked={supports.includes(
                                                        feature.key,
                                                    )}
                                                    hint={
                                                        feature.hint ??
                                                        undefined
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setSupports((current) =>
                                                            toggle(
                                                                current,
                                                                feature.key,
                                                                checked ===
                                                                    true,
                                                            ),
                                                        )
                                                    }
                                                />
                                            ),
                                        )}
                                        <InputError message={errors.supports} />
                                    </div>
                                </FormPanel>
                            </Section>

                            <Section id="type-section-fields">
                                <FormPanel
                                    title="Fields"
                                    description="What the editor asks for on each item."
                                >
                                    <FieldsSummary fields={page.fields} />
                                </FormPanel>
                            </Section>

                            <Section id="type-section-permissions">
                                <FormPanel
                                    title="Permissions"
                                    description="Who may write, publish and delete its items."
                                >
                                    <PermissionsField
                                        saved={saved.permissions}
                                        value={permissions}
                                        error={errors.permissions}
                                        onChange={setPermissions}
                                    />
                                    <PermissionsTable
                                        page={page}
                                        caption={permissionsCaption(
                                            saved.permissions,
                                            named,
                                        )}
                                    />
                                </FormPanel>
                            </Section>

                            <SaveBar
                                dirty={
                                    isDirty ||
                                    snapshot(state) !==
                                        snapshot(savedState(saved))
                                }
                                processing={processing}
                                onDiscard={onDiscard}
                                saveLabel={creating ? 'Add type' : 'Save type'}
                                what={
                                    saved.plural.trim() === ''
                                        ? __('this type')
                                        : saved.plural
                                }
                                testId="type-save"
                            />
                        </>
                    )}
                </Form>

                {page.mode === 'edit' && (
                    <Section id="type-section-delete">
                        <DeletePanel page={page} />
                    </Section>
                )}
            </div>

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

function ViewPanels({ page, items }: { page: TypePage; items: string }) {
    const type = page.type;
    const taxonomies = page.options.taxonomies
        .filter((taxonomy) => type.taxonomies.includes(taxonomy.key))
        .map((taxonomy) => taxonomy.plural);
    const features = page.options.features
        .filter((feature) => type.supports.includes(feature.key))
        .map((feature) => feature.label);

    return (
        <>
            <div className="grid gap-4">
                <Section id="type-section-names">
                    <FormPanel
                        title="Names"
                        description="What the type is called across the admin."
                    >
                        <Facts>
                            <Fact label="Singular name">{type.singular}</Fact>
                            <Fact label="Plural name">{type.plural}</Fact>
                            <Fact label="Key">
                                <code>{type.key}</code>
                            </Fact>
                            <Fact label="Icon">
                                <IconName name={type.icon} />
                            </Fact>
                        </Facts>
                    </FormPanel>
                </Section>
                <Section id="type-section-admin">
                    <FormPanel
                        title="In the admin"
                        description="Where its list is, and how the menu opens it."
                    >
                        <Facts>
                            <Fact label="Admin address">
                                <code>{`/admin/${type.segment}`}</code>
                            </Fact>
                            <Fact label="One item only">
                                {yesNo(type.single)}
                            </Fact>
                            <Fact label="Items">
                                {items}
                                {page.listUrl !== null && (
                                    <>
                                        {' '}
                                        <Link
                                            href={page.listUrl}
                                            className="text-accent-ink underline underline-offset-3"
                                        >
                                            Open {type.plural}
                                        </Link>
                                    </>
                                )}
                            </Fact>
                        </Facts>
                    </FormPanel>
                </Section>
                <Section id="type-section-site">
                    <FormPanel
                        title="On the site"
                        description="Whether its items have pages of their own, and what those pages offer."
                    >
                        <Facts>
                            <Fact label="Public addresses">
                                {type.public
                                    ? 'Yes: each item has its own page'
                                    : 'No'}
                            </Fact>
                            <Fact label="Public address">
                                {type.pattern === null ? (
                                    'None'
                                ) : (
                                    <>
                                        <code>{type.pattern}</code>{' '}
                                        <Link
                                            href={addresses.url()}
                                            className="text-accent-ink underline underline-offset-3"
                                        >
                                            Change in Settings › Addresses
                                        </Link>
                                    </>
                                )}
                            </Fact>
                            <Fact label="Archive page">
                                {yesNo(type.archive)}
                            </Fact>
                            <Fact label="Site search">
                                {yesNo(type.searchable)}
                            </Fact>
                            <Fact label="Comments">
                                {commentsWords(type.comments)}
                            </Fact>
                        </Facts>
                    </FormPanel>
                </Section>
                <Section id="type-section-editor">
                    <FormPanel
                        title="In the editor"
                        description="What the editor offers beside the writing."
                    >
                        <Facts>
                            <Fact label="Taxonomies">
                                {taxonomies.length === 0
                                    ? 'None'
                                    : taxonomies.join(', ')}
                            </Fact>
                            <Fact label="Features">
                                {features.length === 0
                                    ? 'None'
                                    : features.join(', ')}
                            </Fact>
                        </Facts>
                    </FormPanel>
                </Section>
                <Section id="type-section-fields">
                    <FormPanel
                        title="Fields"
                        description="What the editor asks for on each item."
                    >
                        <FieldsSummary fields={page.fields} />
                    </FormPanel>
                </Section>
                <Section id="type-section-permissions">
                    <FormPanel
                        title="Permissions"
                        description="Who may write, publish and delete its items."
                    >
                        <p>
                            {type.permissions === 'own'
                                ? `${type.plural} have permissions of their own.`
                                : `${type.plural} use the content permissions every type shares.`}
                        </p>
                        <PermissionsTable
                            page={page}
                            caption={permissionsCaption(
                                type.permissions,
                                type.plural,
                            )}
                        />
                    </FormPanel>
                </Section>
            </div>
        </>
    );
}

function DeletePanel({ page }: { page: TypePage }) {
    const plural = page.type.plural;
    const reason = deleteReason(plural, page.items, page.trashed);

    return (
        <FormPanel title="Delete this type">
            {reason === null ? (
                <p>No items use it, so it can be deleted.</p>
            ) : (
                <p id="type-delete-reason">{reason}</p>
            )}
            {page.listUrl !== null && page.items > 0 && (
                <div className="flex flex-wrap gap-2">
                    <Button variant="neutral" asChild>
                        <Link href={page.listUrl}>{__('Open the list')}</Link>
                    </Button>
                    {page.trashed > 0 && (
                        <Button variant="ghost" asChild>
                            <Link href={`${page.listUrl}?tab=trash`}>
                                {__('Open the trash')}
                            </Link>
                        </Button>
                    )}
                </div>
            )}
            {reason === null ? (
                <Form {...TypesController.destroy.form(page.type.key)}>
                    {() => (
                        <ConfirmAction
                            title={`Delete ${plural}?`}
                            description="It has no items, so only the type goes."
                            confirmLabel={`Delete ${plural}`}
                        >
                            <Button
                                type="submit"
                                variant="danger-quiet"
                                size="sm"
                                data-test="type-delete"
                            >
                                Delete {plural}
                            </Button>
                        </ConfirmAction>
                    )}
                </Form>
            ) : null}
        </FormPanel>
    );
}

function PatternLine({ page }: { page: TypePage }) {
    return (
        <div className="grid gap-2">
            <p className="text-small text-ink-2 font-medium">Public address</p>
            {page.type.pattern === null ? (
                <p>None: not public</p>
            ) : (
                <p className="text-pretty">
                    <code>{page.type.pattern}</code>
                    {page.example !== null ? (
                        <> For example {page.example}. </>
                    ) : (
                        ' '
                    )}
                    <Link
                        href={addresses.url()}
                        className="text-accent-ink underline underline-offset-3"
                    >
                        Change in Settings › Addresses
                    </Link>
                    , which keeps every old address working as a redirect.
                </p>
            )}
        </div>
    );
}

/**
 * Names joined as the summary joins them: "A, B and C", with a comma
 * before the last when it holds an "and" of its own.
 */
function joined(names: string[]): string {
    if (names.length < 2) {
        return names[0] ?? '';
    }

    const last = names[names.length - 1];
    const glue = last.includes(' and ') ? ', and ' : ' and ';

    return `${names.slice(0, -1).join(', ')}${glue}${last}`;
}

/** The type's fields summed up, with the way to the Fields page (I-3320). */
function FieldsSummary({ fields }: { fields: TypePage['fields'] }) {
    if (fields.url === null) {
        return (
            <p className="text-small text-ink-2">
                Add fields once the type is saved.
            </p>
        );
    }

    return (
        <div
            className="grid justify-items-start gap-3"
            data-test="fields-summary"
        >
            <p>
                {fields.own.length === 0 ? (
                    'No fields of your own yet.'
                ) : (
                    <>
                        <b>
                            {fields.own.length === 1
                                ? '1 field of your own:'
                                : `${fields.own.length} fields of your own:`}
                        </b>{' '}
                        {joined(fields.own)}.
                    </>
                )}
                {fields.fromTeme.length > 0 && (
                    <>
                        {' '}
                        <b>From Teme:</b> {joined(fields.fromTeme)}.
                    </>
                )}
            </p>
            <Button variant="neutral" asChild>
                <Link href={fields.url}>Manage fields</Link>
            </Button>
        </div>
    );
}

function PermissionsField({
    saved,
    value,
    error,
    onChange,
}: {
    saved: 'shared' | 'own';
    value: 'shared' | 'own';
    error?: string;
    onChange: (value: 'shared' | 'own') => void;
}) {
    const options = [
        [
            'shared',
            'Use the standard content permissions',
            'type-permissions-shared',
        ],
        ['own', 'Give this type its own', 'type-permissions-own'],
    ] as const;

    return (
        <div className="grid gap-2">
            <p
                id="type-permissions-label"
                className="text-small text-ink-2 font-medium"
            >
                Which permissions it uses
            </p>
            <RadioGroup
                name="permissions"
                aria-labelledby="type-permissions-label"
                value={value}
                onValueChange={(next) => onChange(next as 'shared' | 'own')}
            >
                {options.map(([option, label, id]) => (
                    <RadioGroupChoice
                        key={id}
                        id={id}
                        value={option}
                        label={label}
                    />
                ))}
            </RadioGroup>
            {value !== saved && (
                <Hint>
                    {value === 'own'
                        ? 'When you save, each role starts with what it may do now, so nobody gains or loses anything.'
                        : 'When you save, the type’s own permissions are removed, and each role does with its items what it does with every type’s.'}
                </Hint>
            )}
            <InputError message={error} />
        </div>
    );
}

function PermissionsTable({
    page,
    caption,
}: {
    page: TypePage;
    caption: string;
}) {
    const captionId = useId();

    return (
        <div className="grid gap-2">
            <ScrollArea
                axis="x"
                role="region"
                aria-labelledby={captionId}
                tabIndex={0}
                className="focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
            >
                <table
                    data-test="type-permissions-table"
                    className="text-small w-full"
                >
                    <caption id={captionId} className="mb-2 text-left">
                        {caption}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" className="px-2 py-1 text-left">
                                Permission
                            </th>
                            {page.permissions.roles.map((role) => (
                                <th
                                    key={role.key}
                                    scope="col"
                                    className="px-2 py-1 text-left"
                                >
                                    {role.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {page.permissions.actions.map((action) => (
                            <tr key={action.key}>
                                <th
                                    scope="row"
                                    className="px-2 py-1 text-left font-medium"
                                >
                                    <span className="block">
                                        {action.label}
                                    </span>
                                    <code className="text-caption text-ink-2 font-mono break-all">
                                        {action.ability}
                                    </code>
                                </th>
                                {page.permissions.roles.map((role) => {
                                    const holds =
                                        page.permissions.holds[
                                            role.key
                                        ]?.includes(action.key) ?? false;

                                    return (
                                        <td
                                            key={role.key}
                                            className="px-2 py-1"
                                        >
                                            {holds ? (
                                                <>
                                                    <Check
                                                        aria-hidden
                                                        className="size-4"
                                                    />
                                                    <span className="sr-only">
                                                        Yes
                                                    </span>
                                                </>
                                            ) : (
                                                <>
                                                    <span aria-hidden>—</span>
                                                    <span className="sr-only">
                                                        No
                                                    </span>
                                                </>
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </ScrollArea>
            <Hint>
                Roles can’t be changed yet: this shows what each role may do. To
                change what someone can do, give them another role on People.
            </Hint>
        </div>
    );
}

function permissionsCaption(
    permissions: 'shared' | 'own',
    plural: string,
): string {
    return permissions === 'own'
        ? `What each role may do with ${plural}`
        : 'What each role may do with every type’s items';
}

function CommentsField({
    value,
    disabled,
    error,
    onChange,
}: {
    value: TypeComments;
    disabled: boolean;
    error?: string;
    onChange: (value: TypeComments) => void;
}) {
    const options = [
        ['', 'Don’t take comments', 'type-comments-none'],
        ['open', 'Take comments, open on each new one', 'type-comments-open'],
        [
            'closed',
            'Take comments, closed on each new one',
            'type-comments-closed',
        ],
    ] as const;

    return (
        <div className="grid gap-2">
            <p
                id="type-comments-label"
                className="text-small text-ink-2 font-medium"
            >
                Comments
            </p>
            <RadioGroup
                name="comments"
                aria-labelledby="type-comments-label"
                value={value ?? ''}
                disabled={disabled}
                onValueChange={(next) =>
                    onChange(next === '' ? null : (next as TypeComments))
                }
            >
                {options.map(([option, label, id]) => (
                    <RadioGroupChoice
                        key={id}
                        id={id}
                        value={option}
                        label={label}
                    />
                ))}
            </RadioGroup>
            <Hint>Each item can still be opened or closed on its own.</Hint>
            <InputError message={error} />
        </div>
    );
}

function CodeNote({ page }: { page: TypePage }) {
    return (
        <Notice tone="info">
            {page.type.plural} are declared by code, so they can’t be changed or
            deleted here.
            {page.type.public && (
                <>
                    {' '}
                    Their public address can still change:{' '}
                    <Link
                        href={addresses.url()}
                        className="text-accent-ink underline underline-offset-3"
                    >
                        Settings › Addresses
                    </Link>
                    .
                </>
            )}
        </Notice>
    );
}

function PluginNote({ page }: { page: TypePage }) {
    const plugin = page.plugin ?? '';

    return (
        <Notice tone="info">
            <p className="flex flex-wrap items-center gap-1">
                <PluginMark name={plugin} />
                <span>
                    {__(
                        'Comes from :plugin. Its key, names, addresses and fields change with the plugin’s updates, not here.',
                        { plugin },
                    )}
                </span>
            </p>
            {!page.pluginEnabled && (
                <p>
                    {__(
                        ':plugin isn’t enabled, so :plural are hidden from the menu, the editor and the site. Their items are kept, and come back when it’s enabled again.',
                        { plugin, plural: page.type.plural },
                    )}
                </p>
            )}
        </Notice>
    );
}

function LockNote() {
    return (
        <Notice tone="info">
            <p>
                Types are locked on this site (TEME_TYPES_LOCKED): change them
                where they’re made, and bring them here with{' '}
                <code>php artisan teme:types:import</code>.
            </p>
            <p>
                You can still look at each type, and download teme-types.json.
            </p>
        </Notice>
    );
}

function yesNo(value: boolean): string {
    return value ? 'Yes' : 'No';
}

function commentsWords(comments: TypeComments): string {
    if (comments === 'open') {
        return 'Take comments, open on each new one';
    }

    if (comments === 'closed') {
        return 'Take comments, closed on each new one';
    }

    return 'Don’t take comments';
}

/** The line under the title, for the mode the page is in. */
function headerLine(page: TypePage): string {
    if (page.mode === 'new') {
        return __(
            'A kind of content of your own, such as films or recipes. It appears in the menu as soon as it’s added.',
        );
    }

    if (page.mode === 'view') {
        return __(
            'Declared by code, and shown here so you can see how it’s set up.',
        );
    }

    const made =
        page.made === null
            ? __('Made here.')
            : __('Made here on :date.', { date: page.made });

    const count =
        page.items === 0
            ? __('No items yet.')
            : __n(':count item.|:count items.', page.items);

    if (page.mode === 'plugin') {
        return count;
    }

    return `${made} ${count}`;
}

/** None, the count, or how many of them are in the trash. */
function itemsPhrase(items: number, trashed: number): string {
    if (items === 0) {
        return 'None';
    }

    if (items === 1 && trashed === 1) {
        return '1, in the trash';
    }

    if (items > 1 && trashed === items) {
        return `${items}, all of them in the trash`;
    }

    if (trashed > 0) {
        return `${items}, ${trashed} of them in the trash`;
    }

    return String(items);
}

/** Why a type with items can't be deleted, or null when it can. */
function deleteReason(
    plural: string,
    items: number,
    trashed: number,
): string | null {
    if (items === 0) {
        return null;
    }

    if (items === 1) {
        return trashed === 1
            ? `${plural} have 1 item, in the trash, so the type can’t be deleted. Delete it from the trash first.`
            : `${plural} have 1 item, so the type can’t be deleted. Delete it first.`;
    }

    if (trashed === items) {
        return `${plural} have ${items} items, all of them in the trash, so the type can’t be deleted. Delete them from the trash first.`;
    }

    const some = trashed === 0 ? '' : `, ${trashed} of them in the trash`;

    return `${plural} have ${items} items${some}, so the type can’t be deleted. Delete them first, from the trash too.`;
}
