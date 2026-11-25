import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft, GripVertical, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import TypeFieldsController from '@/actions/App/Http/Admin/TypeFieldsController';
import { ListPage } from '@/components/patterns/list-page';
import { FieldSheet } from '@/components/types/field-sheet';
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
import { Notice } from '@/components/ui/notice';
import { Panel } from '@/components/ui/panel';
import { PluginMark } from '@/components/ui/plugin-mark';
import { StatusPill } from '@/components/ui/status-pill';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as typesIndex } from '@/routes/types';
import { index as fieldsIndex } from '@/routes/types/fields';
import type {
    FieldFromTeme,
    FieldRow,
    FieldsPage,
    FieldsPageType,
} from '@/types';

/** A row's action, drawn as words under its name. */
const LINK = 'text-accent-ink';

/** How many items hold a value, as the removed list and the dialog say it. */
function holdingPhrase(type: FieldsPageType, holding: number): string {
    if (holding === 0) {
        return `No ${type.noun} holds a value`;
    }

    return holding === 1
        ? `1 ${type.noun} holds a value`
        : `${holding} ${type.plural.toLowerCase()} hold a value`;
}

/**
 * Site › Types › {type} › Fields (I-3320): the type's own fields in order,
 * then Teme's, then removed ones, and any that can't be read. Each field is
 * added and edited in the side sheet and saved on its own; a locked site
 * shows the same page with nothing to change.
 */
export default function TypeFields(page: FieldsPage) {
    const { type, locked, fields } = page;
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<FieldRow | null>(null);
    const [removing, setRemoving] = useState<FieldRow | null>(null);
    const [dragging, setDragging] = useState<string | null>(null);
    const [refocus, setRefocus] = useState<string | null>(null);

    const siteCrumb = useSectionCrumb('site');

    setLayoutProps({
        breadcrumbs: [
            siteCrumb,
            { title: __('Types'), href: typesIndex() },
            { title: type.plural, href: type.typeUrl },
            { title: __('Fields'), href: fieldsIndex({ key: type.key }) },
        ],
    });

    // After a move, focus stays on the moved row's button, or its other one
    // when that is gone at the top or the bottom.
    useEffect(() => {
        if (refocus === null) {
            return;
        }

        const [key, action] = refocus.split(':');
        const other = action === 'up' ? 'down' : 'up';
        const button =
            document.querySelector<HTMLButtonElement>(
                `[data-move="${key}:${action}"]`,
            ) ??
            document.querySelector<HTMLButtonElement>(
                `[data-move="${key}:${other}"]`,
            );
        button?.focus();
        setRefocus(null);
    }, [fields, refocus]);

    const move = (row: FieldRow, position: number, action?: 'up' | 'down') =>
        router.put(
            TypeFieldsController.move.url({ key: type.key, field: row.key }),
            { position },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    if (action !== undefined) {
                        setRefocus(`${row.key}:${action}`);
                    }
                },
            },
        );

    return (
        <ListPage
            title={`${type.singular} fields`}
            showTitle
            description={`What the editor asks for on each ${type.noun}, in this order. A saved change works at once.`}
            actions={
                <>
                    <Button variant="ghost" asChild>
                        <Link href={type.typeUrl}>
                            <ArrowLeft aria-hidden="true" />
                            {__('Back to :plural', { plural: type.plural })}
                        </Link>
                    </Button>
                    {!locked && (
                        <Button
                            type="button"
                            variant="primary"
                            onClick={() => {
                                setEditing(null);
                                setAdding(true);
                            }}
                        >
                            <Plus aria-hidden="true" />
                            {__('Add field')}
                        </Button>
                    )}
                </>
            }
        >
            <Head title={`${type.singular} fields`} />

            {locked && (
                <Notice tone="info">
                    The types are locked on this site, so fields can’t be added
                    or changed here.
                </Notice>
            )}

            <Card>
                {fields.length === 0 ? (
                    <p className="text-body text-ink-2 p-4">
                        No fields of your own yet. Add one, and the editor shows
                        it in Details at once.
                    </p>
                ) : (
                    <table className="text-body w-full">
                        <thead>
                            <tr className="text-left">
                                <Th className="w-8">
                                    <span className="sr-only">Order</span>
                                </Th>
                                <Th>Field</Th>
                                <Th className="hidden sm:table-cell">
                                    Field type
                                </Th>
                                <Th className="hidden md:table-cell">
                                    Site search
                                </Th>
                                <Th className="hidden md:table-cell">
                                    Filterable
                                </Th>
                            </tr>
                        </thead>
                        <tbody>
                            {fields.map((row, at) => (
                                <tr
                                    key={row.key}
                                    data-test={`field-row-${row.handle}`}
                                    draggable={!locked && row.plugin === null}
                                    onDragStart={(event) => {
                                        setDragging(row.key);
                                        event.dataTransfer.effectAllowed =
                                            'move';
                                        event.dataTransfer.setData(
                                            'text/plain',
                                            row.key,
                                        );
                                    }}
                                    onDragEnd={() => setDragging(null)}
                                    onDragOver={(event) => {
                                        if (dragging !== null) {
                                            event.preventDefault();
                                        }
                                    }}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        const from = fields.find(
                                            (field) => field.key === dragging,
                                        );
                                        setDragging(null);

                                        if (
                                            from !== undefined &&
                                            from.key !== row.key
                                        ) {
                                            move(from, row.position);
                                        }
                                    }}
                                    className={cn(
                                        'align-top',
                                        dragging === row.key && 'opacity-50',
                                    )}
                                >
                                    <td className="w-8 py-3 pl-3">
                                        {!locked && row.plugin === null && (
                                            <span
                                                title="Drag to reorder"
                                                className="text-ink-2 inline-flex cursor-grab"
                                                data-test="field-grip"
                                            >
                                                <GripVertical
                                                    aria-hidden
                                                    className="size-4"
                                                />
                                            </span>
                                        )}
                                    </td>
                                    <td className="min-w-0 py-3 pr-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                {row.label}
                                            </span>
                                            {row.required && (
                                                <StatusPill tone="neutral">
                                                    Required
                                                </StatusPill>
                                            )}
                                        </div>
                                        <div className="text-caption text-ink-2 font-mono break-all">
                                            {row.handle}
                                        </div>
                                        <div className="text-caption text-ink-2 sm:hidden">
                                            {typeLabel(page, row)}
                                            {row.summary !== '' &&
                                                ` · ${row.summary}`}
                                        </div>
                                        {row.plugin !== null && (
                                            <PluginMark name={row.plugin} />
                                        )}
                                        {!locked && row.plugin === null && (
                                            <RowActions label={row.label}>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="xs"
                                                    className={LINK}
                                                    onClick={() => {
                                                        setAdding(false);
                                                        setEditing(row);
                                                    }}
                                                >
                                                    Edit
                                                </Button>
                                                {at > 0 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="xs"
                                                        className={LINK}
                                                        data-move={`${row.key}:up`}
                                                        onClick={() =>
                                                            move(
                                                                row,
                                                                fields[at - 1]
                                                                    .position,
                                                                'up',
                                                            )
                                                        }
                                                    >
                                                        Move up
                                                    </Button>
                                                )}
                                                {at < fields.length - 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="xs"
                                                        className={LINK}
                                                        data-move={`${row.key}:down`}
                                                        onClick={() =>
                                                            move(
                                                                row,
                                                                fields[at + 1]
                                                                    .position,
                                                                'down',
                                                            )
                                                        }
                                                    >
                                                        Move down
                                                    </Button>
                                                )}
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="xs"
                                                    className="text-danger-ink"
                                                    onClick={() =>
                                                        setRemoving(row)
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </RowActions>
                                        )}
                                    </td>
                                    <td className="hidden py-3 pr-3 sm:table-cell">
                                        {typeLabel(page, row)}
                                        {row.summary !== '' && (
                                            <div className="text-caption text-ink-2">
                                                {row.summary}
                                            </div>
                                        )}
                                    </td>
                                    <td className="hidden py-3 pr-3 md:table-cell">
                                        {row.search ? 'Yes' : 'No'}
                                    </td>
                                    <td className="hidden py-3 pr-3 md:table-cell">
                                        {row.filterable ? 'Yes' : 'No'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            {page.fromTeme.length > 0 && <FromTeme page={page} />}

            {page.removed.length > 0 && (
                <Card>
                    <CardHeading
                        title="Removed fields"
                        line="Hidden from the editor and the site. Their values are still stored, so Restore brings a field back with them."
                    />
                    <table className="text-small w-full">
                        <thead>
                            <tr className="border-line border-y text-left">
                                <Th className="pl-4">Field</Th>
                                <Th className="hidden sm:table-cell">
                                    Values kept
                                </Th>
                                <Th className="hidden sm:table-cell">
                                    Removed
                                </Th>
                            </tr>
                        </thead>
                        <tbody>
                            {page.removed.map((row) => (
                                <tr
                                    key={row.key}
                                    data-test={`removed-row-${row.handle}`}
                                    className="border-line border-b align-top last:border-b-0"
                                >
                                    <td className="py-3 pr-3 pl-4">
                                        <div className="font-medium">
                                            {row.label}
                                        </div>
                                        <div className="text-caption text-ink-2 font-mono break-all">
                                            {row.handle} ·{' '}
                                            {catalogueLabel(
                                                page,
                                                row.fieldType,
                                            )}
                                        </div>
                                        <div className="text-caption text-ink-2 sm:hidden">
                                            {holdingPhrase(type, row.holding)} ·
                                            removed {row.removedAt}
                                        </div>
                                        {!locked && (
                                            <RowActions label={row.label}>
                                                <Form
                                                    {...TypeFieldsController.restore.form(
                                                        {
                                                            key: type.key,
                                                            field: row.key,
                                                        },
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="xs"
                                                        className={LINK}
                                                    >
                                                        Restore
                                                    </Button>
                                                </Form>
                                            </RowActions>
                                        )}
                                    </td>
                                    <td className="hidden py-3 pr-3 sm:table-cell">
                                        {holdingPhrase(type, row.holding)}
                                    </td>
                                    <td className="hidden py-3 pr-3 sm:table-cell">
                                        {row.removedBy === null
                                            ? row.removedAt
                                            : `${row.removedAt}, by ${row.removedBy}`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}

            {page.unreadable.length > 0 && (
                <Card>
                    <div className="grid gap-2 p-4" data-test="unreadable">
                        {page.unreadable.map((row) => (
                            <p key={row.key} className="text-body">
                                {row.sentence}
                            </p>
                        ))}
                    </div>
                </Card>
            )}

            {!locked && (
                <FieldSheet
                    type={type}
                    catalogue={page.catalogue}
                    editing={editing}
                    open={adding || editing !== null}
                    onClose={() => {
                        setAdding(false);
                        setEditing(null);
                    }}
                    removeButton={
                        editing === null ? undefined : (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-danger-ink"
                                onClick={() => setRemoving(editing)}
                            >
                                Remove field…
                            </Button>
                        )
                    }
                />
            )}

            <RemoveDialog
                type={type}
                row={removing}
                onClose={() => setRemoving(null)}
                onRemoved={() => {
                    setRemoving(null);
                    setEditing(null);
                }}
            />
        </ListPage>
    );
}

function typeLabel(page: FieldsPage, row: FieldRow): string {
    return catalogueLabel(page, row.fieldType);
}

function catalogueLabel(page: FieldsPage, name: string): string {
    return page.catalogue.find((entry) => entry.name === name)?.label ?? name;
}

function Card({ children }: { children: ReactNode }) {
    return <Panel className="overflow-hidden">{children}</Panel>;
}

function CardHeading({
    title,
    line,
    icon,
}: {
    title: string;
    line: ReactNode;
    icon?: ReactNode;
}) {
    return (
        <div className="grid gap-1 p-4">
            <h2 className="text-body flex items-center gap-2 font-semibold">
                {icon}
                {title}
            </h2>
            <p className="text-small text-ink-2 text-pretty">{line}</p>
        </div>
    );
}

function Th({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <th
            scope="col"
            className={cn(
                'text-caption text-ink-2 py-2 pr-3 font-medium',
                className,
            )}
        >
            {children}
        </th>
    );
}

function RowActions({
    label,
    children,
}: {
    label: string;
    children: ReactNode[] | ReactNode;
}) {
    const items = (Array.isArray(children) ? children : [children]).filter(
        (child) => child !== false && child !== null && child !== undefined,
    );

    return (
        <div
            role="group"
            aria-label={`Actions for ${label}`}
            className="mt-1 flex flex-wrap items-center gap-x-1"
        >
            {items.map((item, at) => (
                <span key={at} className="inline-flex items-center gap-x-1">
                    {at > 0 && (
                        <span
                            aria-hidden="true"
                            className="text-caption text-ink-2"
                        >
                            ·
                        </span>
                    )}
                    {item}
                </span>
            ))}
        </div>
    );
}

/** Title and Body: every type has them, so they lead Teme's fields. */
function alwaysFields(): FieldFromTeme[] {
    return [
        {
            name: __('Title'),
            handles: 'title',
            fieldType: __('Short text'),
            where: __('In the editor’s title field'),
            feature: null,
        },
        {
            name: __('Body'),
            handles: 'body',
            fieldType: __('Rich text'),
            where: __('In the editor’s writing area'),
            feature: null,
        },
    ];
}

function FromTeme({ page }: { page: FieldsPage }) {
    return (
        <section aria-labelledby="fields-from-teme" className="grid gap-3">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2
                    id="fields-from-teme"
                    className="text-lead text-ink font-semibold"
                >
                    {__('From Teme')}
                </h2>
                <p className="text-small text-ink-2">
                    {__('Every type has these. They can’t be changed here.')}
                </p>
            </div>
            <Card>
                <table className="text-body w-full">
                    <caption className="sr-only">
                        {__('Fields that come with Teme')}
                    </caption>
                    <thead>
                        <tr className="text-left">
                            <Th className="pl-4">{__('Field')}</Th>
                            <Th className="hidden sm:table-cell">
                                {__('Field type')}
                            </Th>
                            <Th className="hidden sm:table-cell">
                                {__('Why it’s here')}
                            </Th>
                        </tr>
                    </thead>
                    <tbody>
                        {[...alwaysFields(), ...page.fromTeme].map((row) => (
                            <tr key={row.name} className="align-top">
                                <td className="min-w-0 py-3 pr-3 pl-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold">
                                            {row.name}
                                        </span>
                                        <span className="text-small text-ink-3">
                                            {__('Code')}
                                        </span>
                                    </div>
                                    <div className="text-caption text-ink-2 font-mono break-all">
                                        {row.handles}
                                    </div>
                                    <div className="text-caption text-ink-2 sm:hidden">
                                        {row.fieldType} · {row.where}
                                    </div>
                                </td>
                                <td className="hidden py-3 pr-3 sm:table-cell">
                                    {row.fieldType}
                                    <div className="text-caption text-ink-2">
                                        {row.where}
                                    </div>
                                </td>
                                <td className="hidden py-3 pr-3 sm:table-cell">
                                    {row.feature === null ? (
                                        <span className="text-ink-3">
                                            {__('Always')}
                                        </span>
                                    ) : (
                                        <>
                                            {__('Comes with')}{' '}
                                            <i>{row.feature}</i>
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </section>
    );
}

function RemoveDialog({
    type,
    row,
    onClose,
    onRemoved,
}: {
    type: FieldsPageType;
    row: FieldRow | null;
    onClose: () => void;
    onRemoved: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const held =
        row === null || row.holding !== 1
            ? `${row?.holding ?? 0} ${type.plural.toLowerCase()}`
            : `1 ${type.noun}`;

    return (
        <Dialog open={row !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent data-test="remove-field-dialog">
                <DialogHeader>
                    <DialogTitle>Remove {row?.label}?</DialogTitle>
                    <DialogDescription>
                        {row !== null && row.holding > 0 ? (
                            <>
                                It leaves {type.plural}’ editor and the site at
                                once, and templates that show it show nothing.
                                The values <b>{held}</b> hold stay stored, so{' '}
                                <i>Restore</i> brings it back with them. Nothing
                                is deleted.
                            </>
                        ) : (
                            `It leaves ${type.plural}’ editor and the site at once. No ${type.noun} holds a value.`
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="neutral">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={processing}
                        onClick={() => {
                            if (row === null) {
                                return;
                            }

                            router.delete(
                                TypeFieldsController.destroy.url({
                                    key: type.key,
                                    field: row.key,
                                }),
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onSuccess: onRemoved,
                                },
                            );
                        }}
                    >
                        Remove field
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
