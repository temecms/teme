import { Form, Head, Link } from '@inertiajs/react';
import NavigationController from '@/actions/App/Http/Admin/NavigationController';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { PageHeader } from '@/components/list/page-header';
import { RowLinks } from '@/components/list/row-links';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { MenuRow, NavigationIndexPage } from '@/types';

/** "Header and Footer", or "Not shown" for a menu no place shows. */
function shownIn(row: MenuRow): string {
    if (row.shownIn.length === 0) {
        return 'Not shown';
    }

    return row.shownIn.length === 1
        ? row.shownIn[0]
        : `${row.shownIn.slice(0, -1).join(', ')} and ${row.shownIn.at(-1)}`;
}

export default function NavigationIndex({ navigations }: NavigationIndexPage) {
    const columns: Column<MenuRow>[] = [
        {
            id: 'name',
            owner: 'core',
            header: 'Name',
            cell: (row) => (
                <>
                    <Link
                        href={NavigationController.edit({ id: row.id }).url}
                        className="font-semibold hover:underline"
                    >
                        {row.name}
                    </Link>
                    <RowLinks
                        label={`Actions for ${row.name}`}
                        more={`More actions for ${row.name}`}
                        items={[
                            {
                                id: 'edit',
                                owner: 'core',
                                label: 'Edit',
                                href: NavigationController.edit({ id: row.id })
                                    .url,
                            },
                            {
                                id: 'delete',
                                owner: 'core',
                                label: 'Delete…',
                                destructive: true,
                                form: NavigationController.destroy.form({
                                    id: row.id,
                                }),
                                wrap: (button) => (
                                    <ConfirmAction
                                        title={`Delete the menu “${row.name}”?`}
                                        description={
                                            row.shownIn.length === 0
                                                ? 'It isn’t shown anywhere. This cannot be undone.'
                                                : `${shownIn(row)} will show no menu until you choose another. This cannot be undone.`
                                        }
                                        confirmLabel="Delete menu"
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
            id: 'items',
            owner: 'core',
            header: 'Items',
            cell: (row) => (
                <span className="font-mono tabular-nums">
                    {row.itemCount.toLocaleString()}
                </span>
            ),
        },
        {
            id: 'shown',
            owner: 'core',
            header: 'Shown in',
            cell: (row) => (
                <span
                    className={
                        row.shownIn.length === 0
                            ? 'text-muted-foreground'
                            : undefined
                    }
                >
                    {shownIn(row)}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title="Navigation" />

            <div className="space-y-4 p-6">
                <PageHeader
                    title="Navigation"
                    description="The menus visitors use to find their way around the site. Build a menu, then choose where the theme shows it."
                />

                <Form
                    {...NavigationController.store.form()}
                    resetOnSuccess
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="new-menu">New menu</Label>
                                <Input
                                    id="new-menu"
                                    aria-describedby="new-menu-error"
                                    name="name"
                                    placeholder="Main menu"
                                    maxLength={120}
                                    required
                                />
                            </div>
                            <Button disabled={processing}>Create menu</Button>
                            <InputError
                                id="new-menu-error"
                                message={errors.name}
                            />
                        </>
                    )}
                </Form>

                <DataTable
                    columns={columns}
                    rows={navigations}
                    rowKey={(row) => row.id}
                    caption="Menus"
                    empty={
                        <EmptyState
                            kind="nothing-yet"
                            title="No menus yet"
                            description="A menu is a list of links to pages, posts, categories, the home page or any address."
                            action={
                                <Button
                                    variant="outline"
                                    type="button"
                                    onClick={() =>
                                        document
                                            .getElementById('new-menu')
                                            ?.focus()
                                    }
                                >
                                    Create a menu
                                </Button>
                            }
                        />
                    }
                />
            </div>
        </>
    );
}
