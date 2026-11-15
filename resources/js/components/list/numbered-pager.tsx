import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type { ListPages } from '@/types/list-pages';

/** How many steps past the cap the address says this page is, from the pager's own `past`; 0 on a numbered page. */
function stepsPastCap(url: string): number {
    const value = new URLSearchParams(url.split('?')[1] ?? '').get('past');
    const steps = Number(value);

    return Number.isInteger(steps) && steps > 0 ? steps : 0;
}

/**
 * The page numbers to draw: the first, the last, the current one and one
 * either side, with null for each gap.
 */
function numbersFor(current: number, last: number): (number | null)[] {
    const wanted = new Set(
        [1, last, current - 1, current, current + 1].filter(
            (number) => number >= 1 && number <= last,
        ),
    );
    const sorted = [...wanted].sort((a, b) => a - b);
    const numbers: (number | null)[] = [];

    sorted.forEach((number, at) => {
        if (at > 0 && number - sorted[at - 1] > 1) {
            numbers.push(null);
        }

        numbers.push(number);
    });

    return numbers;
}

/**
 * A list's numbered pager (I-3421; docs/architecture/design-system.md,
 * section 4.26): where the rows sit, then Previous, the page numbers and
 * Next. The count stops at the cap, so the last number reads "50+" when more
 * lie beyond; past it, Previous and Next page by keyset. On a phone, Previous,
 * "Page 3 of 50+" and Next. Every link keeps the list's other query values.
 */
export function NumberedPager({
    pages,
    perPage,
    href,
}: {
    pages: ListPages;
    /** Rows per page, for "of more than 2,500". */
    perPage: number;
    /** The list's address with these values added to the ones it keeps. */
    href: (query: Record<string, string>) => string;
}) {
    const { url } = usePage();
    const past = stepsPastCap(url);
    const { number: page, last, more } = pages;
    const lastLabel = more
        ? `${last.toLocaleString()}+`
        : last.toLocaleString();

    const previous =
        page === null
            ? past <= 1 || pages.newer === null
                ? href({ page: String(last) })
                : href({ before: pages.newer, past: String(past - 1) })
            : page > 1
              ? href({ page: String(page - 1) })
              : null;
    const next =
        page === null
            ? pages.older !== null
                ? href({ after: pages.older, past: String(past + 1) })
                : null
            : page < last
              ? href({ page: String(page + 1) })
              : more && pages.older !== null
                ? href({ after: pages.older, past: '1' })
                : null;

    const where =
        pages.from === null || pages.to === null
            ? null
            : pages.total !== null
              ? __(':from to :to of :total', {
                    from: pages.from.toLocaleString(),
                    to: pages.to.toLocaleString(),
                    total: pages.total.toLocaleString(),
                })
              : __(':from to :to of more than :most', {
                    from: pages.from.toLocaleString(),
                    to: pages.to.toLocaleString(),
                    most: (last * perPage).toLocaleString(),
                });

    if (page === 1 && last === 1 && !more && pages.from === null) {
        return null;
    }

    return (
        <div
            data-slot="numbered-pager"
            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-4"
        >
            <p className="text-small text-ink-3 hidden tabular-nums sm:block">
                {where}
            </p>
            <nav
                aria-label={__('Pages')}
                className="flex w-full items-center justify-between gap-1 sm:w-auto sm:justify-end"
            >
                <Step
                    href={previous}
                    label={__('Previous page')}
                    icon={<ChevronLeft aria-hidden="true" />}
                />
                {page === null ? (
                    <span className="text-small text-ink-3 px-2">
                        {__('Past page :last', { last: last.toLocaleString() })}
                    </span>
                ) : (
                    <>
                        <span
                            data-test="pager-phone"
                            className="text-small text-ink-2 px-2 tabular-nums sm:hidden"
                        >
                            {__('Page :page of :last', {
                                page: page.toLocaleString(),
                                last: lastLabel,
                            })}
                        </span>
                        <ol className="hidden items-center gap-1 sm:flex">
                            {numbersFor(page, last).map((number, at) =>
                                number === null ? (
                                    <li
                                        key={`gap-${at}`}
                                        className="text-ink-3 px-1"
                                    >
                                        <span aria-hidden="true">…</span>
                                        <span className="sr-only">
                                            {__('and more')}
                                        </span>
                                    </li>
                                ) : (
                                    <li key={number}>
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                            className="aria-[current=page]:bg-surface-3 aria-[current=page]:text-ink min-w-8 tabular-nums"
                                        >
                                            <Link
                                                href={href({
                                                    page: String(number),
                                                })}
                                                aria-current={
                                                    number === page
                                                        ? 'page'
                                                        : undefined
                                                }
                                                aria-label={
                                                    number === last && more
                                                        ? __(
                                                              'Page :page of more than :last',
                                                              {
                                                                  page: number.toLocaleString(),
                                                                  last: last.toLocaleString(),
                                                              },
                                                          )
                                                        : __('Page :page', {
                                                              page: number.toLocaleString(),
                                                          })
                                                }
                                            >
                                                {number === last
                                                    ? lastLabel
                                                    : number.toLocaleString()}
                                            </Link>
                                        </Button>
                                    </li>
                                ),
                            )}
                        </ol>
                    </>
                )}
                <Step
                    href={next}
                    label={__('Next page')}
                    icon={<ChevronRight aria-hidden="true" />}
                />
            </nav>
        </div>
    );
}

/** Previous or Next: a link, or a disabled button when there is nowhere to go. */
function Step({
    href,
    label,
    icon,
}: {
    href: string | null;
    label: string;
    icon: ReactNode;
}) {
    return href === null ? (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={label}
            disabled
        >
            {icon}
        </Button>
    ) : (
        <Button asChild variant="ghost" size="icon-sm">
            <Link href={href} aria-label={label}>
                {icon}
            </Link>
        </Button>
    );
}
