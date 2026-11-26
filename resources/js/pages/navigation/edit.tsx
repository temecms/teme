import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
} from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';
import NavigationController from '@/actions/App/Http/Admin/NavigationController';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/list/empty-state';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import { SaveBar } from '@/components/patterns/save-bar';
import { AddItemPanel } from '@/components/navigation/add-item-panel';
import {
    newItem,
    toPayload,
    TreeEditor,
    withKeys,
} from '@/components/navigation/tree-editor';
import type { EditorItem } from '@/components/navigation/tree-editor';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import type { MenuItem, NavigationEditPage } from '@/types';

/** A place ticked or unticked on the page and not yet saved. */
type PendingPlace = { slot: string; language: string; checked: boolean };

const pendingKey = (language: string, slot: string) => `${language}:${slot}`;

/** "Header", or "Header and Footer". */
function placesOf(slots: { label: string }[]): string {
    const labels = slots.map((slot) => slot.label);

    return labels.length < 2
        ? (labels[0] ?? '')
        : __(':list and :last', {
              list: labels.slice(0, -1).join(', '),
              last: labels[labels.length - 1],
          });
}

function countOf(items: EditorItem[]): number {
    return items.reduce((sum, item) => sum + 1 + countOf(item.children), 0);
}

/**
 * Content › Navigation › a menu: where the theme shows it, ticked per
 * place; its items as a tree, arranged and saved together; and Delete
 * this menu at the foot.
 */

export default function NavigationEdit({
    navigation,
    items,
    themeCode,
    themeName,
    slots,
    menus,
    types,
    taxonomies,
    readers,
}: NavigationEditPage) {
    const [tree, setTree] = useState<EditorItem[]>(() => withKeys(items));
    const [dirty, setDirty] = useState(false);
    // Places ticked or unticked since the last save, by language and place;
    // Save writes them after the items.
    const [pending, setPending] = useState<Record<string, PendingPlace>>({});
    const [placeError, setPlaceError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [adding, setAdding] = useState(false);
    const [renaming, setRenaming] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;
    // The last save's errors name items by place, so a later edit may move
    // them: they go until the next save.
    const [showErrors, setShowErrors] = useState(true);
    const shownIn = slots.filter((slot) => slot.navigationId === navigation.id);
    const readerLanguages = readers?.languages ?? [];
    const defaultLanguage = readerLanguages[0]?.tag ?? '';
    const [readersOf, setReadersOf] = useState(
        readers?.language ?? defaultLanguage,
    );
    // The default language's readers see the places' own menus; another's, its words for them (I-3360).
    const shownTo = (slot: { slot: string; navigationId: string | null }) =>
        readersOf === defaultLanguage
            ? slot.navigationId
            : (readers?.bindings[readersOf]?.[slot.slot] ?? null);

    const languageOfPlaces = readersOf === defaultLanguage ? '' : readersOf;

    const change = (next: EditorItem[]) => {
        setTree(next);
        setDirty(true);
        setShowErrors(false);
    };

    const placeChanges = Object.values(pending);
    const unsaved = dirty || placeChanges.length > 0;

    /** Tick or untick a place on the page; nothing is sent until Save. */
    const tick = (slot: string, language: string, checked: boolean) => {
        const key = pendingKey(language, slot);
        const holder =
            language === ''
                ? (slots.find((place) => place.slot === slot)?.navigationId ??
                  null)
                : (readers?.bindings[language]?.[slot] ?? null);

        setPending((current) => {
            const next = { ...current };

            if ((holder === navigation.id) === checked) {
                delete next[key];
            } else {
                next[key] = { slot, language, checked };
            }

            return next;
        });
        setPlaceError(null);
    };

    /** Binds the changed places one after another, stopping at the first the server refuses. */
    const savePlaces = (queue: PendingPlace[]) => {
        const [place, ...rest] = queue;

        if (place === undefined) {
            setSaving(false);
            setShowErrors(true);

            return;
        }

        router.put(
            NavigationController.updateSlots.url({ id: navigation.id }),
            {
                theme: themeCode ?? '',
                slot: place.slot,
                navigation_id: place.checked ? navigation.id : '',
                language: place.language,
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setPending((current) => {
                        const next = { ...current };
                        delete next[pendingKey(place.language, place.slot)];

                        return next;
                    });
                    savePlaces(rest);
                },
                onError: (found) => {
                    const label =
                        slots.find((slot) => slot.slot === place.slot)?.label ??
                        place.slot;
                    setPlaceError(
                        __(':place was not saved: :reason', {
                            place: label,
                            reason: found.slot ?? found.navigation ?? '',
                        }),
                    );
                    setSaving(false);
                },
            },
        );
    };

    const save = () => {
        const places = Object.values(pending);

        setSaving(true);
        setPlaceError(null);

        if (!dirty) {
            savePlaces(places);

            return;
        }

        router.put(
            NavigationController.updateItems.url({ id: navigation.id }),
            {
                tree: toPayload(tree),
                // The places' own message follows, so this one stays quiet.
                then_places: places.length > 0,
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    setTree(withKeys(page.props.items as MenuItem[]));
                    setDirty(false);
                    savePlaces(places);
                },
                onError: () => {
                    setSaving(false);
                    setShowErrors(true);
                },
            },
        );
    };

    /** The menu's own language, saved through Rename with its name kept; the places then show that language's menus. */
    const chooseReaders = (language: string) => {
        setReadersOf(language);
        router.put(
            NavigationController.update.url({ id: navigation.id }),
            { name: navigation.name, language },
            { preserveState: true, preserveScroll: true },
        );
    };

    const contentCrumb = useSectionCrumb('content');

    setLayoutProps({
        breadcrumbs: [
            contentCrumb,
            { title: __('Navigation'), href: NavigationController.index() },
            {
                title: navigation.name,
                href: NavigationController.edit({ id: navigation.id }),
            },
        ],
    });

    return (
        <FormPage
            title={navigation.name}
            description={__(
                'Arrange the items, then save. An item that points at a page, post, category or tag takes its title unless you give it a label.',
            )}
            actions={
                <>
                    <Button variant="ghost" asChild>
                        <Link href={NavigationController.index().url}>
                            <ArrowLeft aria-hidden="true" />
                            {__('All menus')}
                        </Link>
                    </Button>
                    <Button
                        variant="neutral"
                        type="button"
                        onClick={() => setRenaming(true)}
                    >
                        {__('Rename…')}
                    </Button>
                </>
            }
        >
            <Head title={navigation.name} />

            <div className="grid gap-4">
                {themeCode !== null && slots.length > 0 && (
                    <FormPanel
                        title={__('Show in')}
                        description={__(
                            'Places come from the active theme, :theme. A place shows one menu; choosing it here replaces the menu it shows now.',
                            { theme: themeName ?? __('the site’s theme') },
                        )}
                    >
                        {readerLanguages.length > 0 && (
                            <div className="grid gap-2 sm:grid-cols-3 sm:items-center">
                                <Label htmlFor="menu-readers-of">
                                    {__('For readers of')}
                                </Label>
                                <div className="min-w-0 sm:col-span-2">
                                    <SelectField
                                        id="menu-readers-of"
                                        value={readersOf}
                                        options={readerLanguages.map(
                                            (language) => ({
                                                value: language.tag,
                                                label: language.name,
                                            }),
                                        )}
                                        onValueChange={chooseReaders}
                                        aria-describedby="menu-readers-of-help"
                                    />
                                    <p
                                        id="menu-readers-of-help"
                                        className="text-caption text-ink-3 mt-1"
                                    >
                                        {readersOf === defaultLanguage
                                            ? __(
                                                  'This menu is in the default language. The places below show these menus to its readers.',
                                              )
                                            : __(
                                                  'This menu is in this language. A place set to None shows its readers the default language’s menu.',
                                              )}
                                    </p>
                                </div>
                            </div>
                        )}
                        <div className="grid gap-3">
                            {slots.map((slot) => {
                                const holder = shownTo(slot);
                                const other =
                                    holder !== null && holder !== navigation.id
                                        ? menus.find(
                                              (menu) => menu.id === holder,
                                          )
                                        : undefined;

                                return (
                                    <div
                                        key={slot.slot}
                                        className="flex flex-wrap items-center gap-x-3 gap-y-1"
                                    >
                                        <label className="text-body flex items-center gap-2">
                                            <Checkbox
                                                id={`slot-${slot.slot}`}
                                                checked={
                                                    pending[
                                                        pendingKey(
                                                            languageOfPlaces,
                                                            slot.slot,
                                                        )
                                                    ]?.checked ??
                                                    holder === navigation.id
                                                }
                                                aria-describedby={
                                                    other === undefined
                                                        ? undefined
                                                        : `slot-${slot.slot}-holder`
                                                }
                                                onCheckedChange={(checked) =>
                                                    tick(
                                                        slot.slot,
                                                        languageOfPlaces,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            {slot.label}
                                        </label>
                                        {other !== undefined && (
                                            <span
                                                id={`slot-${slot.slot}-holder`}
                                                className="text-small text-ink-3"
                                            >
                                                {__(
                                                    'Shows :menu now. Ticking this takes its place.',
                                                    { menu: other.name },
                                                )}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                        <InputError
                            message={
                                placeError ?? errors.slot ?? errors.navigation
                            }
                        />
                    </FormPanel>
                )}

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        save();
                    }}
                >
                    <FormPanel>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-lead text-ink flex items-baseline gap-2 font-semibold">
                                {__('Items')}
                                <span className="text-ink-3 font-normal tabular-nums">
                                    {countOf(tree).toLocaleString()}
                                </span>
                            </h2>
                            {tree.length > 0 && (
                                <Button
                                    variant="neutral"
                                    size="sm"
                                    type="button"
                                    onClick={() => setAdding(true)}
                                >
                                    <Plus aria-hidden="true" />
                                    {__('Add an item')}
                                </Button>
                            )}
                        </div>

                        {showErrors && (
                            <InputError
                                message={errors.tree ?? errors.navigation}
                            />
                        )}

                        {tree.length === 0 ? (
                            <EmptyState
                                kind="nothing-yet"
                                title="This menu has no items yet"
                                description="Add the home page, pages, posts, categories, tags or any address."
                                action={
                                    <Button
                                        variant="neutral"
                                        type="button"
                                        onClick={() => setAdding(true)}
                                    >
                                        Add an item
                                    </Button>
                                }
                            />
                        ) : (
                            <TreeEditor
                                items={tree}
                                onChange={change}
                                errors={showErrors ? errors : {}}
                            />
                        )}

                        <p className="text-small text-ink-2">
                            {__(
                                'Drag an item by its handle, or use the arrows (on a touch screen, the “…” menu on each item). The arrows work with the keyboard, too: Alt and up or down to reorder, right to put it under the item above, left to bring it out. Menus go three levels deep.',
                            )}
                        </p>
                    </FormPanel>
                    <SaveBar
                        dirty={unsaved}
                        processing={saving}
                        saveLabel={__('Save menu')}
                        testId="menu-save"
                        what={__('“:name”', { name: navigation.name })}
                        onDiscard={() => {
                            setTree(withKeys(items));
                            setDirty(false);
                            setPending({});
                            setPlaceError(null);
                            setShowErrors(true);
                        }}
                    />
                </form>

                <FormPanel title={__('Delete this menu')}>
                    <p className="text-small text-ink-2">
                        {shownIn.length === 0
                            ? __('This cannot be undone.')
                            : __(
                                  ':places will show no menu until you choose another. This cannot be undone.',
                                  { places: placesOf(shownIn) },
                              )}
                    </p>
                    <Form
                        {...NavigationController.destroy.form({
                            id: navigation.id,
                        })}
                        className="justify-self-start"
                    >
                        <ConfirmAction
                            title={__('Delete the menu “:name”?', {
                                name: navigation.name,
                            })}
                            description={
                                shownIn.length === 0
                                    ? __(
                                          'It isn’t shown anywhere. This cannot be undone.',
                                      )
                                    : __(
                                          ':places will show no menu until you choose another. This cannot be undone.',
                                          { places: placesOf(shownIn) },
                                      )
                            }
                            confirmLabel={__('Delete menu')}
                        >
                            <Button
                                variant="danger-quiet"
                                size="sm"
                                data-test="menu-delete"
                            >
                                {__('Delete menu…')}
                            </Button>
                        </ConfirmAction>
                    </Form>
                </FormPanel>
            </div>

            <AddItemPanel
                open={adding}
                onOpenChange={setAdding}
                types={types}
                taxonomies={taxonomies}
                onAdd={(item) => change([...tree, newItem(item)])}
                multilingual={readerLanguages.length > 0}
            />

            <Sheet open={renaming} onOpenChange={setRenaming}>
                <SheetContent>
                    <SheetHeader>
                        <SheetTitle>Rename the menu</SheetTitle>
                        <SheetDescription>
                            Only the name changes. Themes that show this menu
                            keep showing it.
                        </SheetDescription>
                    </SheetHeader>
                    <Form
                        {...NavigationController.update.form({
                            id: navigation.id,
                        })}
                        options={{ preserveState: true, preserveScroll: true }}
                        onSuccess={() => setRenaming(false)}
                        className="grid gap-4 px-4 pb-4"
                    >
                        {({ processing, errors: formErrors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="menu-name">Name</Label>
                                    <Input
                                        id="menu-name"
                                        name="name"
                                        defaultValue={navigation.name}
                                        maxLength={120}
                                        aria-describedby="menu-name-error"
                                        required
                                    />
                                    <InputError
                                        id="menu-name-error"
                                        message={formErrors.name}
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button disabled={processing}>
                                        Rename menu
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        type="button"
                                        onClick={() => setRenaming(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </SheetContent>
            </Sheet>
        </FormPage>
    );
}
