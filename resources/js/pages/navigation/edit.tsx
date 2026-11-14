import { Form, Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import NavigationController from '@/actions/App/Http/Admin/NavigationController';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/list/empty-state';
import { PageHeader } from '@/components/list/page-header';
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
            { theme: themeCode ?? '', slot, navigation_id: navigationId },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <>
            <Head title={navigation.name} />

            <div className="space-y-6 p-6">
                <PageHeader
                    title={navigation.name}
                    description="Arrange the items, then save. An item that points at a page, post, category or tag takes its title unless you give it a label."
                    action={
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
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
                                    <Button variant="outline">Delete…</Button>
                                </ConfirmAction>
                            </Form>
                        </div>
                    }
                />

                {themeCode !== null && slots.length > 0 && (
                    <section
                        aria-labelledby="show-in"
                        className="grid max-w-xl gap-3"
                    >
                        <div className="grid gap-1">
                            <h2
                                id="show-in"
                                className="text-[15px] font-semibold"
                            >
                                Show in
                            </h2>
                            <p className="text-muted-foreground text-[13px]">
                                {`Where ${themeName ?? 'the site’s theme'} shows a menu. Each place shows one menu.`}
                            </p>
                        </div>
                        {slots.map((slot) => (
                            <div
                                key={slot.slot}
                                className="grid gap-2 sm:grid-cols-[140px_1fr] sm:items-center"
                            >
                                <Label htmlFor={`slot-${slot.slot}`}>
                                    {slot.label}
                                </Label>
                                <SelectField
                                    id={`slot-${slot.slot}`}
                                    value={slot.navigationId ?? ''}
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
                        ))}
                        <InputError
                            message={errors.slot ?? errors.navigation}
                        />
                    </section>
                )}

                <section aria-labelledby="items" className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="items" className="text-[15px] font-semibold">
                            Items
                        </h2>
                        <div className="flex items-center gap-2">
                            {dirty && (
                                <span className="text-muted-foreground text-[13px]">
                                    Unsaved changes
                                </span>
                            )}
                            <Button
                                variant="outline"
                                type="button"
                                onClick={() => setAdding(true)}
                            >
                                <Plus className="size-4" />
                                Add item
                            </Button>
                            <Button
                                type="button"
                                disabled={!dirty || saving}
                                onClick={save}
                            >
                                Save menu
                            </Button>
                        </div>
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
                                    variant="outline"
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

                    <p className="text-muted-foreground text-[13px]">
                        Move an item with its buttons, or with Alt and the arrow
                        keys: up and down to reorder, right to put it under the
                        item above, left to bring it out. Menus go three levels
                        deep.
                    </p>
                </section>
            </div>

            <AddItemPanel
                open={adding}
                onOpenChange={setAdding}
                types={types}
                taxonomies={taxonomies}
                onAdd={(item) => change([...tree, newItem(item)])}
            />

            <Sheet open={renaming} onOpenChange={setRenaming}>
                <SheetContent className="w-full sm:max-w-[480px]">
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
        </>
    );
}

// The last item is this screen, which its heading names: the trail draws the parents only.
NavigationEdit.layout = {
    breadcrumbs: [
        { title: 'Navigation', href: NavigationController.index() },
        { title: 'Menu', href: NavigationController.index() },
    ],
};
