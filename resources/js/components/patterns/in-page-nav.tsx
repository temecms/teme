import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SoonTag } from '@/components/ui/soon-tag';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type InPageNavItem = {
    href: string;
    label: string;
    count?: number;
    /** The count is on its way: a quiet pulsing pill, still for reduced motion. */
    counting?: boolean;
    current?: boolean;
    /** Planned and not built: drawn with its Soon tag, opening nothing (I-3408). */
    soon?: boolean;
};

/** How many items show before the rest go under More, unless a place says. */
const SHOWN = 5;

const ITEM =
    'text-body text-ink-2 hover:bg-surface-3 hover:text-ink aria-[current=page]:bg-surface-3 aria-[current=page]:text-ink focus-visible:outline-ring inline-flex h-(--ctl-h) shrink-0 items-center gap-2 rounded-md px-3 font-medium whitespace-nowrap focus-visible:outline-2 focus-visible:-outline-offset-2';

/** An item's count, after its label, for information. */
function Count({ count, counting }: { count?: number; counting?: boolean }) {
    if (counting) {
        return (
            <span
                className="bg-surface-3 motion-safe:animate-pulse inline-block size-5 shrink-0 rounded-full"
                data-test="tab-counting"
            >
                <span className="sr-only">{__('Counting')}</span>
            </span>
        );
    }

    return count === undefined ? null : (
        <span className="text-ink-3 tabular-nums">{count}</span>
    );
}

/**
 * Views of one place that change the address
 * (docs/architecture/design-system.md, section 4.27): links in a labelled
 * nav, the current one marked; the first five show (or as many as the
 * place asks), the rest are under More, which shows the current item's
 * name when it is one of them. One row, never wrapping.
 */
export function InPageNav({
    label,
    items,
    shown: showing = SHOWN,
}: {
    label: string;
    items: InPageNavItem[];
    /** How many show before More: People shows all seven of its views. */
    shown?: number;
}) {
    const shown = items.slice(0, showing);
    const more = items.slice(showing);
    const currentInMore = more.find((item) => item.current);

    return (
        <nav
            aria-label={label}
            data-slot="in-page-nav"
            className="mb-4 max-w-full min-w-0"
        >
            <ScrollArea axis="x" className="w-full">
                <ul className="flex w-max gap-1">
                    {shown.map((item) => (
                        <li key={item.href || item.label} className="flex">
                            {item.soon ? (
                                <span
                                    aria-disabled="true"
                                    className={cn(ITEM, 'cursor-default')}
                                >
                                    {item.label}
                                    <SoonTag className="ms-0" />
                                </span>
                            ) : (
                                <Link
                                    href={item.href}
                                    aria-current={
                                        item.current ? 'page' : undefined
                                    }
                                    className={ITEM}
                                >
                                    {item.label}
                                    <Count
                                        count={item.count}
                                        counting={item.counting}
                                    />
                                </Link>
                            )}
                        </li>
                    ))}
                    {more.length > 0 && (
                        <li className="flex">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        aria-current={
                                            currentInMore ? 'page' : undefined
                                        }
                                        className={ITEM}
                                    >
                                        {currentInMore?.label ?? __('More')}
                                        <ChevronDown aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start">
                                    {more.map((item) =>
                                        item.soon ? (
                                            <DropdownMenuItem
                                                key={item.label}
                                                disabled
                                            >
                                                {item.label}
                                                <SoonTag className="ms-auto" />
                                            </DropdownMenuItem>
                                        ) : (
                                            <DropdownMenuItem
                                                key={item.href}
                                                asChild
                                            >
                                                <Link
                                                    href={item.href}
                                                    aria-current={
                                                        item.current
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                >
                                                    {item.label}
                                                    <Count count={item.count} />
                                                </Link>
                                            </DropdownMenuItem>
                                        ),
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </li>
                    )}
                </ul>
            </ScrollArea>
        </nav>
    );
}
