import { Slot } from '@radix-ui/react-slot';
import type {
    ComponentProps,
    KeyboardEvent,
    MouseEvent as ReactMouseEvent,
    ReactNode,
} from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useId,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

const phoneQuery =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia('(max-width: 39.99rem)');

/**
 * Whether the screen is a phone's, by the stylesheet's own phone query
 * (docs/architecture/design-system.md, section 2.12).
 */
export function usePhoneWidth(): boolean {
    return useSyncExternalStore(
        (onChange) => {
            phoneQuery?.addEventListener('change', onChange);

            return () => phoneQuery?.removeEventListener('change', onChange);
        },
        () => phoneQuery?.matches ?? false,
        () => false,
    );
}

/** What a menu's parts need to know: which way it is drawn, and how to close it. */
const MenuContext = createContext<{ phone: boolean; close: () => void }>({
    phone: false,
    close: () => {},
});

/** A phone menu's group, which its label names. */
const GroupContext = createContext<{
    labelId: string;
    labelled: () => void;
} | null>(null);

/**
 * A menu that fits a phone (docs/proposals/phone-overlays.md): from 640px
 * up it is the dropdown menu, unchanged; below 640px it is a sheet from the
 * foot of the screen holding the same items. Crossing 640px while it is open
 * closes it, so it is never drawn both ways.
 */
export function ResponsiveMenu({
    open: openProp,
    onOpenChange,
    modal,
    children,
}: {
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    /** The dropdown's own `modal`, from 640px up; a phone's sheet is always modal. */
    modal?: boolean;
    children: ReactNode;
}) {
    const phone = usePhoneWidth();
    const [ownOpen, setOwnOpen] = useState(false);
    const open = openProp ?? ownOpen;
    const setOpen = useCallback(
        (next: boolean) => {
            if (openProp === undefined) {
                setOwnOpen(next);
            }

            onOpenChange?.(next);
        },
        [openProp, onOpenChange],
    );
    const close = useCallback(() => setOpen(false), [setOpen]);
    const drawnFor = useRef(phone);

    useEffect(() => {
        if (drawnFor.current !== phone) {
            drawnFor.current = phone;
            setOpen(false);
        }
    }, [phone, setOpen]);

    return (
        <MenuContext.Provider value={{ phone, close }}>
            {phone ? (
                <Sheet open={open} onOpenChange={setOpen}>
                    {children}
                </Sheet>
            ) : (
                <DropdownMenu open={open} onOpenChange={setOpen} modal={modal}>
                    {children}
                </DropdownMenu>
            )}
        </MenuContext.Provider>
    );
}

/** The button that opens the menu; `asChild` passes through. */
export function ResponsiveMenuTrigger(
    props: ComponentProps<typeof DropdownMenuTrigger>,
) {
    const { phone } = useContext(MenuContext);

    return phone ? (
        <SheetTrigger {...props} />
    ) : (
        <DropdownMenuTrigger {...props} />
    );
}

/** The enabled items of a phone menu, in their order. */
function enabledItems(menu: HTMLElement): HTMLElement[] {
    return [
        ...menu.querySelectorAll<HTMLElement>(
            '[role="menuitem"]:not([aria-disabled="true"])',
        ),
    ];
}

/**
 * Keeps one item in the Tab order, the roving tabindex of the WAI-ARIA menu
 * pattern: `current`, or else the one already in it, or else the first
 * enabled item. So Shift+Tab from the sheet's Close button comes back.
 */
function rove(menu: HTMLElement, current?: HTMLElement): void {
    const items = [...menu.querySelectorAll<HTMLElement>('[role="menuitem"]')];
    const enabled = enabledItems(menu);
    const kept =
        current ??
        enabled.find((item) => item.getAttribute('tabindex') === '0') ??
        enabled[0];

    for (const item of items) {
        item.tabIndex = item === kept ? 0 : -1;
    }
}

/** ↑ ↓ Home End move between the enabled items, wrapping (the WAI-ARIA menu pattern). */
function moveFocus(event: KeyboardEvent<HTMLDivElement>): void {
    const items = enabledItems(event.currentTarget);
    const at = items.indexOf(document.activeElement as HTMLElement);
    const next = (
        {
            ArrowDown: at + 1,
            ArrowUp: at - 1,
            Home: 0,
            End: items.length - 1,
        } as Record<string, number>
    )[event.key];

    if (next === undefined || items.length === 0) {
        return;
    }

    event.preventDefault();

    const target = items[(next + items.length) % items.length];

    if (target) {
        rove(event.currentTarget, target);
        target.focus();
    }
}

/**
 * The menu's items. Below 640px they sit in a sheet from the foot, headed by
 * `title`, which also names the menu; from 640px up they are the dropdown's
 * content, or whatever `renderDesktop` draws around them.
 */
export function ResponsiveMenuContent({
    title,
    align,
    className,
    renderDesktop,
    children,
}: {
    title: string;
    align?: ComponentProps<typeof DropdownMenuContent>['align'];
    /** The dropdown's classes, from 640px up only. */
    className?: string;
    /** Draws the content from 640px up in place of the dropdown's own. */
    renderDesktop?: (children: ReactNode) => ReactNode;
    children: ReactNode;
}) {
    const { phone } = useContext(MenuContext);
    const menu = useRef<HTMLDivElement>(null);

    if (!phone) {
        return renderDesktop ? (
            renderDesktop(children)
        ) : (
            <DropdownMenuContent align={align} className={className}>
                {children}
            </DropdownMenuContent>
        );
    }

    return (
        <SheetContent
            side="bottom"
            aria-describedby={undefined}
            className="max-h-(--dialog-xl-h) gap-0"
            onOpenAutoFocus={(event) => {
                event.preventDefault();

                if (menu.current) {
                    enabledItems(menu.current)[0]?.focus();
                }
            }}
        >
            <SheetHeader>
                <SheetTitle>{title}</SheetTitle>
            </SheetHeader>
            <ScrollArea>
                <div
                    ref={(node) => {
                        menu.current = node;

                        if (node) {
                            rove(node);
                        }
                    }}
                    role="menu"
                    aria-label={title}
                    aria-orientation="vertical"
                    onKeyDown={moveFocus}
                    onFocus={(event) => {
                        const item = (
                            event.target as HTMLElement
                        ).closest<HTMLElement>(
                            '[role="menuitem"]:not([aria-disabled="true"])',
                        );

                        if (item) {
                            rove(event.currentTarget, item);
                        }
                    }}
                    className="grid gap-0.5 px-2 pb-4"
                >
                    {children}
                </div>
            </ScrollArea>
        </SheetContent>
    );
}

/** A phone menu item, drawn as the dropdown's (docs/architecture/design-system.md, section 4.17). */
const PHONE_ITEM =
    'text-body text-ink hover:bg-surface-3 focus:bg-surface-3 focus-visible:outline-ring data-[variant=destructive]:text-danger-ink flex h-(--ctl-h) w-full cursor-default items-center gap-3 rounded-md px-3 text-start outline-none select-none focus-visible:outline-2 focus-visible:-outline-offset-2 aria-disabled:pointer-events-none aria-disabled:opacity-55 [&_svg]:pointer-events-none [&_svg]:size-5 [&_svg]:shrink-0 [&_svg:not([class*=text-])]:text-ink-2 data-[variant=destructive]:[&_svg]:text-danger-ink';

/**
 * One of the menu's choices. Choosing it runs `onSelect` and closes the menu;
 * with `asChild`, a link's own click runs first. Call `event.preventDefault()`
 * in `onSelect` to keep the menu open (forms that show an error beside the
 * action), as Radix's dropdown does. `onClick` is forwarded so ConfirmAction
 * can ask before a form submits (it clones this item and adds the handler).
 */
export function ResponsiveMenuItem({
    onSelect,
    onClick,
    asChild = false,
    disabled = false,
    variant = 'default',
    className,
    children,
}: {
    onSelect?: (event: Event) => void;
    onClick?: (event: ReactMouseEvent<HTMLElement>) => void;
    asChild?: boolean;
    disabled?: boolean;
    variant?: 'default' | 'destructive';
    className?: string;
    children: ReactNode;
}) {
    const { phone, close } = useContext(MenuContext);

    if (!phone) {
        return (
            <DropdownMenuItem
                asChild={asChild}
                disabled={disabled}
                variant={variant}
                className={className}
                onSelect={onSelect}
                onClick={onClick}
            >
                {children}
            </DropdownMenuItem>
        );
    }

    const Item = asChild ? Slot : 'button';

    return (
        <Item
            {...(asChild ? {} : { type: 'button' as const })}
            role="menuitem"
            aria-disabled={disabled || undefined}
            data-slot="responsive-menu-item"
            data-variant={variant}
            className={cn(PHONE_ITEM, className)}
            onClick={(event: ReactMouseEvent<HTMLElement>) => {
                if (disabled) {
                    event.preventDefault();

                    return;
                }

                onClick?.(event);

                const selectEvent = new Event('select', { cancelable: true });
                onSelect?.(selectEvent);

                if (!selectEvent.defaultPrevented) {
                    close();
                }
            }}
        >
            {children}
        </Item>
    );
}

/** A heading line in the menu; inside a group, it names the group. */
export function ResponsiveMenuLabel({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    const { phone } = useContext(MenuContext);
    const group = useContext(GroupContext);
    const labelled = group?.labelled;

    useEffect(() => {
        if (phone) {
            labelled?.();
        }
    }, [phone, labelled]);

    if (!phone) {
        return (
            <DropdownMenuLabel className={className}>
                {children}
            </DropdownMenuLabel>
        );
    }

    return (
        <div
            id={group?.labelId}
            className={cn(
                'text-caption text-ink-3 px-3 pt-2 pb-1 font-semibold uppercase',
                className,
            )}
        >
            {children}
        </div>
    );
}

/** Items that belong together, named by a label inside it. */
export function ResponsiveMenuGroup({ children }: { children: ReactNode }) {
    const { phone } = useContext(MenuContext);
    const labelId = useId();
    const [hasLabel, setHasLabel] = useState(false);
    const labelled = useCallback(() => setHasLabel(true), []);

    if (!phone) {
        return <DropdownMenuGroup>{children}</DropdownMenuGroup>;
    }

    return (
        <GroupContext.Provider value={{ labelId, labelled }}>
            <div
                role="group"
                aria-labelledby={hasLabel ? labelId : undefined}
                className="grid gap-0.5"
            >
                {children}
            </div>
        </GroupContext.Provider>
    );
}

/** A rule between the menu's groups. */
export function ResponsiveMenuSeparator() {
    const { phone } = useContext(MenuContext);

    if (!phone) {
        return <DropdownMenuSeparator />;
    }

    return <div role="separator" className="border-line mx-2 my-1 border-t" />;
}
