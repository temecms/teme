import { Form, Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { PANEL_ICONS } from '@/components/editor/plugin-panels/plugin-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { __ } from '@/lib/i18n';
import type { RowAction } from './row-actions';
import { orderRowMenu } from './row-menu-order';

/** The optional icon beside a menu label, when the action names one. */
function ActionIcon({ name }: { name: string | null | undefined }) {
    if (name === null || name === undefined || name === '') {
        return null;
    }

    const Icon = PANEL_ICONS[name];

    if (Icon === undefined) {
        return null;
    }

    return <Icon aria-hidden="true" className="size-4 shrink-0" />;
}

/**
 * A row's "…" menu (docs/architecture/design-system.md, section 4.17): the
 * state action that applies, then plugins' actions in their order, then the
 * destructive ones last, in red, a separator between the groups (I-3398). Area
 * tables pass `pluginsFirst` so custom actions sit above Enable/Disable. It
 * opens below its button and never locks the page; Esc and Tab close it and
 * focus returns to the button.
 */
export function RowMenu({
    items,
    title,
    pluginsFirst = false,
}: {
    items: RowAction[];
    /** The row's title, as shown: it names the button. */
    title: string;
    /** Area tables: plugin actions, then Enable/Disable, then Delete. */
    pluginsFirst?: boolean;
}) {
    const [open, setOpen] = useState(false);
    // The button's name as a tooltip, never over its own open menu.
    const [tip, setTip] = useState(false);
    // The main landmark, found once the page is drawn: it is not yet there while the rows first render.
    const [container, setContainer] = useState<HTMLElement | null>(null);

    useEffect(() => setContainer(document.querySelector('main')), []);
    const handOff = useRef(false);
    const label = __('More actions for :title', { title });
    const ordered = orderRowMenu(items, { pluginsFirst });
    const groups = pluginsFirst
        ? [ordered.plugins, ordered.core, ordered.destructive].filter(
              (group) => group.length > 0,
          )
        : [ordered.core, ordered.plugins, ordered.destructive].filter(
              (group) => group.length > 0,
          );

    return (
        <DropdownMenu open={open} onOpenChange={setOpen} modal={false}>
            <Tooltip open={tip && !open} onOpenChange={setTip}>
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label={label}
                            data-test="row-menu"
                        >
                            <MoreHorizontal aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent>{__('More actions')}</TooltipContent>
            </Tooltip>
            {/* Drawn inside the main landmark, not at the end of the page, so the open menu stays in it, as the account menu stays in the banner. */}
            <DropdownMenuContent
                container={container}
                side="bottom"
                align="end"
                onCloseAutoFocus={(event) => {
                    // An action that puts focus somewhere of its own keeps it there.
                    if (handOff.current) {
                        handOff.current = false;
                        event.preventDefault();
                    }
                }}
            >
                {groups.map((group, at) => (
                    <Fragment key={group[0].id}>
                        {at > 0 && <DropdownMenuSeparator />}
                        {group.map((item) => (
                            <MenuItem
                                key={item.id}
                                item={item}
                                onHandOff={() => {
                                    handOff.current = true;
                                }}
                                onDone={() => setOpen(false)}
                            />
                        ))}
                    </Fragment>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** One entry of the menu, by what it does: goes, opens something, or sends. */
function MenuItem({
    item,
    onHandOff,
    onDone,
}: {
    item: RowAction;
    onHandOff: () => void;
    onDone: () => void;
}) {
    const variant = item.destructive ? 'destructive' : 'default';

    if ('href' in item) {
        return (
            <DropdownMenuItem asChild variant={variant}>
                {item.newTab ? (
                    <a
                        href={item.href}
                        target="_blank"
                        rel="noreferrer"
                        data-action={item.id}
                        className="gap-2"
                    >
                        <ActionIcon name={item.icon} />
                        {item.label}
                        <span className="sr-only">
                            {__(', opens in a new tab')}
                        </span>
                    </a>
                ) : (
                    <Link href={item.href} data-action={item.id} className="gap-2">
                        <ActionIcon name={item.icon} />
                        {item.label}
                    </Link>
                )}
            </DropdownMenuItem>
        );
    }

    if ('onSelect' in item) {
        return (
            <DropdownMenuItem
                variant={variant}
                data-action={item.id}
                className="gap-2"
                onSelect={() => {
                    if (item.movesFocus) {
                        onHandOff();
                    }

                    item.onSelect();
                }}
            >
                <ActionIcon name={item.icon} />
                {item.label}
            </DropdownMenuItem>
        );
    }

    return (
        <Form {...item.form} onSuccess={onDone}>
            {({ processing, errors }) => {
                const button = (
                    <DropdownMenuItem
                        asChild
                        variant={variant}
                        disabled={processing}
                        // The form submits; the menu stays open until it has,
                        // so an error can show beside the action.
                        onSelect={(event) => event.preventDefault()}
                    >
                        <Button
                            type="submit"
                            variant="ghost"
                            data-action={item.id}
                            className="w-full justify-start gap-2"
                        >
                            <ActionIcon name={item.icon} />
                            {item.label}
                        </Button>
                    </DropdownMenuItem>
                );

                return (
                    <>
                        {Object.entries(item.fields ?? {}).map(
                            ([name, value]) => (
                                <input
                                    key={name}
                                    type="hidden"
                                    name={name}
                                    value={value}
                                />
                            ),
                        )}
                        {item.wrap ? item.wrap(button) : button}
                        <InputError
                            message={Object.values(errors)[0]}
                            className="px-2 pb-1"
                        />
                    </>
                );
            }}
        </Form>
    );
}
