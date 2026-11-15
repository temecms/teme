import { Form, Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { __ } from '@/lib/i18n';
import type { MenuItem, NavigationEditPage } from '@/types';

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

    const change = (next: EditorItem[]) => {
        setTree(next);
        setDirty(true);
        setShowErrors(false);
    };

    const save = () => {
        setSaving(true);
        router.put(
            NavigationController.updateItems.url({ id: navigation.id }),
            { tree: toPayload(tree) },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    setTree(withKeys(page.props.items as MenuItem[]));
                    setDirty(false);
                },
                onFinish: () => {
                    setSaving(false);
                    setShowErrors(true);
                },
            },
        );
    };

    const bind = (slot: string, navigationId: string) =>
        router.put(
            NavigationController.updateSlots.url({ id: navigation.id }),
            {
                theme: themeCode ?? '',
                slot,
                navigation_id: navigationId,
                language: readersOf === defaultLanguage ? '' : readersOf,
            },
            { preserveState: true, preserveScroll: true },
        );

    setLayoutProps({
        breadcrumbs: [
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
            description="Arrange the items, then save. An item that points at a page, post, category or tag takes its title unless you give it a label."
            actions={
                <>
                    <Button
                        variant="neutral"
                        type="button"
                        onClick={() => setRenaming(true)}
                    >
                        Rename
                    </Button>
                    <Form
                        {...NavigationController.destroy.form({
                            id: navigation.id,
                        })}
                    >
                        <ConfirmAction
                            title={`Delete the menu “${navigation.name}”?`}
                            description={
                                shownIn.length === 0
                                    ? 'It isn’t shown anywhere. This cannot be undone.'
                                    : `${shownIn.map((slot) => slot.label).join(' and ')} will show no menu until you choose another. This cannot be undone.`
                            }
                            confirmLabel="Delete menu"
                        >
                            <Button variant="neutral">Delete…</Button>
                        </ConfirmAction>
                    </Form>
                </>
            }
        >
            <Head title={navigation.name} />

            <div className="grid gap-4">
                {themeCode !== null && slots.length > 0 && (
                    <FormPanel
                        title="Show in"
                        description={`Where ${themeName ?? 'the site’s theme'} shows a menu. Each place shows one menu.`}
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
                                        onValueChange={setReadersOf}
                                        aria-describedby="menu-readers-of-help"
                                    />
                                    <p
                                        id="menu-readers-of-help"
                                        className="text-caption text-ink-3 mt-1"
                                    >
                                        {readersOf === defaultLanguage
                                            ? __(
                                                  'The places below show these menus to readers of the default language.',
                                              )
                                            : __(
                                                  'A place set to None shows readers of this language the default language’s menu.',
                                              )}
                                    </p>
                                </div>
                            </div>
                        )}
                        {slots.map((slot) => (
                            <div
                                key={slot.slot}
                                className="grid gap-2 sm:grid-cols-3 sm:items-center"
                            >
                                <Label htmlFor={`slot-${slot.slot}`}>
                                    {slot.label}
                                </Label>
                                <div className="min-w-0 sm:col-span-2">
                                    <SelectField
                                        id={`slot-${slot.slot}`}
                                        value={shownTo(slot) ?? ''}
                                        emptyLabel="None"
                                        options={menus.map((menu) => ({
                                            value: menu.id,
                                            label:
                                                menu.id === navigation.id
                                                    ? `${menu.name} (this menu)`
                                                    : menu.name,
                                        }))}
                                        onValueChange={(value) =>
                                            bind(slot.slot, value)
                                        }
                                    />
                                </div>
                            </div>
                        ))}
                        <InputError
                            message={errors.slot ?? errors.navigation}
                        />
                    </FormPanel>
                )}

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        save();
                    }}
                >
                    <FormPanel title="Items">
                        <div>
                            <Button
                                variant="neutral"
                                type="button"
                                onClick={() => setAdding(true)}
                            >
                                <Plus aria-hidden className="size-4" />
                                Add item
                            </Button>
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
                            Move an item with its buttons, or with Alt and the
                            arrow keys: up and down to reorder, right to put it
                            under the item above, left to bring it out. Menus go
                            three levels deep.
                        </p>
                    </FormPanel>
                    <SaveBar
                        dirty={dirty}
                        processing={saving}
                        saveLabel={__('Save menu')}
                        testId="menu-save"
                        what={__('“:name”', { name: navigation.name })}
                        onDiscard={() => {
                            setTree(withKeys(items));
                            setDirty(false);
                            setShowErrors(true);
                        }}
                    />
                </form>
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

// The menu's own name takes the last place once the page draws (setLayoutProps above).
NavigationEdit.layout = {
    breadcrumbs: [
        { title: 'Navigation', href: NavigationController.index() },
        { title: 'Menu', href: NavigationController.index() },
    ],
};
