import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import NavigationController from '@/actions/App/Http/Admin/NavigationController';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import type { RowAction } from '@/components/list/row-actions';
import { RowLine } from '@/components/list/row-line';
import { RowMenu } from '@/components/list/row-menu';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import { index as navigationIndex } from '@/routes/navigation';
import type { MenuRow, NavigationIndexPage } from '@/types';

/** "Header", "Header and Footer", or "Header, Footer and Sidebar". */
function places(row: MenuRow): string {
    return row.shownIn.length < 2
        ? (row.shownIn[0] ?? '')
        : __(':list and :last', {
              list: row.shownIn.slice(0, -1).join(', '),
              last: row.shownIn[row.shownIn.length - 1],
          });
}

/**
 * Content › Navigation: every menu, its item count and where the theme shows
 * it, with Edit under each name and Delete in its "…" menu. New menu asks
 * for a name in a dialog, then opens the menu's editor.
 */
export default function NavigationIndex({ navigations }: NavigationIndexPage) {
    const title = __('Navigation');
    const contentCrumb = useSectionCrumb('content');
    const [creating, setCreating] = useState(false);

    setLayoutProps({
        breadcrumbs: [contentCrumb, { title, href: navigationIndex() }],
    });

    const columns: Column<MenuRow>[] = [
        {
            id: 'name',
            owner: 'core',
            header: __('Name'),
            cell: (row) => (
                <div className="grid min-w-0 gap-0.5">
                    <Link
                        href={NavigationController.edit({ id: row.id }).url}
                        className="text-ink focus-visible:outline-ring justify-self-start rounded-sm font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        {row.name}
                    </Link>
                    <RowLine
                        title={row.name}
                        items={[
                            {
                                id: 'edit',
                                owner: 'core',
                                label: __('Edit'),
                                href: NavigationController.edit({ id: row.id })
                                    .url,
                            },
                        ]}
                    />
                </div>
            ),
        },
        {
            id: 'items',
            owner: 'core',
            header: __('Items'),
            className: 'w-24 text-end tabular-nums',
            cell: (row) => row.itemCount.toLocaleString(),
        },
        {
            id: 'shown',
            owner: 'core',
            header: __('Shown in'),
            className: 'w-48',
            cell: (row) =>
                row.shownIn.length === 0 ? (
                    <span className="text-ink-3">{__('Not shown')}</span>
                ) : (
                    places(row)
                ),
        },
        {
            id: 'menu',
            owner: 'core',
            width: 56,
            header: <span className="sr-only">{__('Actions')}</span>,
            cell: (row) => (
                <RowMenu title={row.name} items={menuActions(row)} />
            ),
        },
    ];

    return (
        <ListPage
            title={title}
            description={__(
                'The menus visitors use to find their way around the site. Build a menu, then choose where the theme shows it.',
            )}
            actions={
                <Button
                    type="button"
                    variant="primary"
                    data-test="navigation-new"
                    onClick={() => setCreating(true)}
                >
                    <Plus aria-hidden="true" />
                    {__('New menu')}
                </Button>
            }
        >
            <Head title={title} />

            <DataTable
                columns={columns}
                rows={navigations}
                rowKey={(row) => row.id}
                caption={__('Menus')}
                empty={
                    <EmptyState
                        kind="nothing-yet"
                        title={__('No menus yet')}
                        description={__(
                            'A menu is a list of links to pages, posts, categories, the home page or any address.',
                        )}
                        action={
                            <Button
                                variant="neutral"
                                type="button"
                                onClick={() => setCreating(true)}
                            >
                                {__('Create a menu')}
                            </Button>
                        }
                    />
                }
            />

            <NewMenuDialog open={creating} onOpenChange={setCreating} />
        </ListPage>
    );
}

/** The "…" menu of a menu: Edit, then Delete, asked first. */
function menuActions(row: MenuRow): RowAction[] {
    return [
        {
            id: 'edit',
            owner: 'core',
            label: __('Edit'),
            href: NavigationController.edit({ id: row.id }).url,
        },
        {
            id: 'delete',
            owner: 'core',
            label: __('Delete menu…'),
            destructive: true,
            form: NavigationController.destroy.form({ id: row.id }),
            wrap: (button) => (
                <ConfirmAction
                    title={__('Delete the menu “:name”?', { name: row.name })}
                    description={
                        row.shownIn.length === 0
                            ? __(
                                  'It isn’t shown anywhere. This cannot be undone.',
                              )
                            : __(
                                  ':places will show no menu until you choose another. This cannot be undone.',
                                  { places: places(row) },
                              )
                    }
                    confirmLabel={__('Delete menu')}
                >
                    {button}
                </ConfirmAction>
            ),
        },
    ];
}

/** New menu: its name, then the editor, where its places are chosen. */
function NewMenuDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent data-test="new-menu-dialog">
                <Form
                    {...NavigationController.store.form()}
                    resetOnSuccess
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>{__('New menu')}</DialogTitle>
                                <DialogDescription>
                                    {__(
                                        'You choose where the theme shows it on the next screen.',
                                    )}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-2">
                                <Label htmlFor="new-menu">{__('Name')}</Label>
                                <Input
                                    id="new-menu"
                                    aria-describedby="new-menu-error"
                                    name="name"
                                    placeholder={__('Main menu')}
                                    maxLength={120}
                                    autoComplete="off"
                                    required
                                />
                                <InputError
                                    id="new-menu-error"
                                    message={errors.name}
                                />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="neutral">
                                        {__('Cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                >
                                    {__('Create menu')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
