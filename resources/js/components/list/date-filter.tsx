import { Link, router } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { useIsMobile } from '@/hooks/use-mobile';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dateValue, monthLabel } from './date-words';
import { ListPanel } from './filter-button';

/** The Date control's months, as the list page gives them (I-3131). */
export type DateMonths = {
    by: 'updated' | 'published';
    months: { month: string; count: number | null }[];
};

/**
 * The one Date control (I-3129): the months that hold the tab's items, with
 * their counts, then "Choose dates…" for an exact range of the site's days.
 * `hrefFor` writes the list's address with the Date values given: a key
 * set to null is left out.
 */
export function DateFilter({
    dates,
    filters,
    tabCount,
    hrefFor,
    open,
    onOpenChange,
}: {
    dates: DateMonths;
    filters: { month?: string; from?: string; to?: string };
    /** The tab's own count, shown beside "All dates" while counts are shown. */
    tabCount: number | null;
    hrefFor: (patch: Record<string, string | null>) => string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const isMobile = useIsMobile();
    const [view, setView] = useState<'months' | 'range'>('months');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const fromRef = useRef<HTMLInputElement>(null);
    const chooseRef = useRef<HTMLButtonElement>(null);
    const switched = useRef(false);
    const inForce =
        filters.month !== undefined ||
        filters.from !== undefined ||
        filters.to !== undefined;

    // Opened afresh on the months, the range's fields from the address.
    useEffect(() => {
        if (open) {
            setView('months');
            setFrom(filters.from ?? '');
            setTo(filters.to ?? '');
            switched.current = false;
        }
    }, [open, filters.from, filters.to]);

    // The button that had focus unmounts when the view changes, so the new view's first control takes it.
    useEffect(() => {
        if (!switched.current) {
            return;
        }

        (view === 'range' ? fromRef : chooseRef).current?.focus();
    }, [view]);

    const choose = (next: 'months' | 'range') => {
        switched.current = true;
        setView(next);
    };

    const years: { year: string; months: DateMonths['months'] }[] = [];

    for (const entry of dates.months) {
        const year = entry.month.slice(0, 4);
        const last = years[years.length - 1];

        if (last?.year === year) {
            last.months.push(entry);
        } else {
            years.push({ year, months: [entry] });
        }
    }

    const apply = (event: FormEvent) => {
        event.preventDefault();
        onOpenChange(false);
        router.visit(
            hrefFor({
                month: null,
                from: from === '' ? null : from,
                to: to === '' ? null : to,
            }),
        );
    };

    const row = cn(
        'hover:bg-surface-3 focus-visible:bg-surface-3 focus-visible:outline-ring text-small flex min-h-8 items-center gap-2 rounded-md px-2 focus-visible:outline-2',
        isMobile && 'min-h-11',
    );
    const count = (value: number | null) =>
        value === null ? null : (
            <span
                data-test="date-count"
                className="text-ink-2 text-caption ml-auto font-mono"
            >
                {value}
            </span>
        );

    return (
        <ListPanel
            label={__('Date')}
            open={open}
            onOpenChange={onOpenChange}
            testId="filter-date-panel"
            trigger={
                <Button
                    type="button"
                    variant="outline"
                    data-test="filter-date"
                    className={cn(
                        'h-8 font-normal',
                        // A thumb's target on a phone (I-3422).
                        isMobile && 'h-11 shrink-0 rounded-full',
                        inForce && 'bg-surface-3',
                    )}
                >
                    <span>
                        <span className="text-ink-2">{__('Date:')}</span>{' '}
                        {dateValue(filters)}
                    </span>
                    <ChevronDown className="size-3.5" />
                </Button>
            }
        >
            {view === 'months' ? (
                <div className="w-64">
                    <p
                        data-test="date-hint"
                        className="text-ink-2 text-caption px-2 py-1"
                    >
                        {dates.by === 'published'
                            ? __('By date published.')
                            : __(
                                  'By last update. On the Published tab, by date published.',
                              )}
                    </p>
                    <ScrollArea className="max-h-72">
                        <Link
                            href={hrefFor({
                                month: null,
                                from: null,
                                to: null,
                            })}
                            data-test="date-all"
                            aria-current={inForce ? undefined : 'true'}
                            onClick={() => onOpenChange(false)}
                            className={row}
                        >
                            <span data-test="date-name">{__('All dates')}</span>
                            {count(tabCount)}
                            {!inForce && <Check className="size-3.5" />}
                        </Link>
                        {years.map((group) => (
                            <div key={group.year}>
                                <p className="text-ink-2 text-caption px-2 pt-2 font-semibold">
                                    {group.year}
                                </p>
                                {group.months.map((entry) => (
                                    <Link
                                        key={entry.month}
                                        href={hrefFor({
                                            month: entry.month,
                                            from: null,
                                            to: null,
                                        })}
                                        data-test="date-month"
                                        data-month={entry.month}
                                        aria-current={
                                            filters.month === entry.month
                                                ? 'true'
                                                : undefined
                                        }
                                        onClick={() => onOpenChange(false)}
                                        className={row}
                                    >
                                        <span data-test="date-name">
                                            {monthLabel(entry.month)}
                                        </span>
                                        {count(entry.count)}
                                        {filters.month === entry.month && (
                                            <Check className="size-3.5" />
                                        )}
                                    </Link>
                                ))}
                            </div>
                        ))}
                    </ScrollArea>
                    <Separator className="my-1" />
                    <Button
                        ref={chooseRef}
                        type="button"
                        variant="ghost"
                        data-test="date-choose"
                        onClick={() => choose('range')}
                        className={cn(row, 'text-ink w-full justify-start')}
                    >
                        {__('Choose dates…')}
                    </Button>
                </div>
            ) : (
                <form onSubmit={apply} className="grid w-64 gap-3 p-2">
                    <h3 className="text-small font-semibold">
                        {__('Choose dates')}
                    </h3>
                    <div className="grid grid-cols-2 gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="date-from">{__('From')}</Label>
                            <Input
                                ref={fromRef}
                                id="date-from"
                                type="date"
                                value={from}
                                onChange={(event) =>
                                    setFrom(event.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="date-to">{__('To')}</Label>
                            <Input
                                id="date-to"
                                type="date"
                                value={to}
                                onChange={(event) => setTo(event.target.value)}
                            />
                        </div>
                    </div>
                    <p className="text-ink-2 text-caption">
                        {dates.by === 'published'
                            ? __(
                                  'By date published. Leave To empty for “since”.',
                              )
                            : __('By last update. Leave To empty for “since”.')}
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            data-test="date-back"
                            onClick={() => choose('months')}
                        >
                            {__('Back')}
                        </Button>
                        <Button type="submit" data-test="date-apply">
                            {__('Apply dates')}
                        </Button>
                    </div>
                </form>
            )}
        </ListPanel>
    );
}
