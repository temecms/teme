import { Link } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import { useRef } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

/** The first thing in a panel that takes focus: a link, a button or a field. */
const FIRST_FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * The panel a list header's button opens (I-3135): a popover under the
 * button on a desktop, a bottom sheet on a phone. Whether it is open is the
 * page's to say, so opening one closes any other. It moves focus to the
 * first link or field itself, because Radix's focus scope skips links.
 */
export function ListPanel({
    label,
    trigger,
    open,
    onOpenChange,
    align = 'start',
    testId,
    children,
}: {
    /** Names the panel: its accessible name, and a phone sheet's title. */
    label: string;
    /** The button that opens it. */
    trigger: ReactElement;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    align?: 'start' | 'center' | 'end';
    testId: string;
    children: ReactNode;
}) {
    const isMobile = useIsMobile();
    const contentRef = useRef<HTMLDivElement>(null);

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetTrigger asChild>{trigger}</SheetTrigger>
                <SheetContent
                    side="bottom"
                    aria-describedby={undefined}
                    className="max-h-[85vh]"
                >
                    <SheetHeader>
                        <SheetTitle>{label}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {label}
                        </SheetDescription>
                    </SheetHeader>
                    <div
                        data-test={testId}
                        className="overflow-y-auto px-2 pb-4"
                    >
                        {children}
                    </div>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                ref={contentRef}
                aria-label={label}
                data-test={testId}
                side="bottom"
                align={align}
                onCloseAutoFocus={(event) => {
                    // Another panel opened as this one closed: focus is in it, so it isn't taken back.
                    const holder = document.activeElement?.closest(
                        '[data-slot="popover-content"]',
                    );

                    if (holder !== null && holder !== contentRef.current) {
                        event.preventDefault();
                    }
                }}
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    const panel = contentRef.current;

                    (
                        panel?.querySelector<HTMLElement>(FIRST_FOCUSABLE) ??
                        panel
                    )?.focus();
                }}
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}

/** One choice in a filter's panel: where it goes, and whether it is the one in force. */
export type FilterChoice = {
    key: string;
    label: string;
    href: string;
    current: boolean;
    /** Indents a nested choice, such as a child category, a step per level. */
    depth?: number;
};

/**
 * A labelled button showing a filter's value ("Category: Roads"), whose
 * panel lists its choices as links that apply at once (I-3129).
 */
export function FilterButton({
    name,
    label,
    value,
    active,
    choices,
    open,
    onOpenChange,
}: {
    name: string;
    label: string;
    value: string;
    /** Whether the filter narrows the list now. */
    active: boolean;
    choices: FilterChoice[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const isMobile = useIsMobile();

    return (
        <ListPanel
            label={label}
            open={open}
            onOpenChange={onOpenChange}
            testId={`filter-${name}-panel`}
            trigger={
                <Button
                    type="button"
                    variant="outline"
                    data-test={`filter-${name}`}
                    className={cn(
                        'h-8 font-normal',
                        isMobile && 'shrink-0 rounded-full',
                        active && 'bg-accent',
                    )}
                >
                    <span>
                        <span className="text-muted-foreground">{label}:</span>{' '}
                        {value}
                    </span>
                    <ChevronDown className="size-3.5" />
                </Button>
            }
        >
            <PanelLinks
                choices={choices}
                onChoose={() => onOpenChange(false)}
            />
        </ListPanel>
    );
}

/** The choices of a panel, each a link, the current one checked. */
function PanelLinks({
    choices,
    onChoose,
}: {
    choices: FilterChoice[];
    onChoose: () => void;
}) {
    const isMobile = useIsMobile();

    return (
        <div className="max-h-72 overflow-y-auto">
            {choices.map((choice) => (
                <Link
                    key={choice.key}
                    href={choice.href}
                    aria-current={choice.current ? 'true' : undefined}
                    onClick={onChoose}
                    style={{ paddingLeft: `${8 + (choice.depth ?? 0) * 16}px` }}
                    className={cn(
                        'hover:bg-accent focus-visible:bg-accent flex min-h-8 items-center gap-2 rounded-md pr-2 text-[13px] outline-none',
                        isMobile && 'min-h-11',
                    )}
                >
                    <span className="flex-1">{choice.label}</span>
                    {choice.current && <Check className="size-3.5" />}
                </Link>
            ))}
        </div>
    );
}
