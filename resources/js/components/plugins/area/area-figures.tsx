import { ArrowDown, ArrowUp } from 'lucide-react';
import { __ } from '@/lib/i18n';
import type { AreaFiguresData } from '@/types/plugin-area';

type Item = AreaFiguresData['items'][number];

/** A figure's value as the person reads it, or a dash for none. */
function shown(item: Item): string {
    if (item.value === null) {
        return '—';
    }

    if (item.format === 'percent' && typeof item.value === 'number') {
        return new Intl.NumberFormat(undefined, {
            style: 'percent',
            maximumFractionDigits: 1,
        }).format(item.value);
    }

    return typeof item.value === 'number'
        ? new Intl.NumberFormat().format(item.value)
        : item.value;
}

/** The label's coloured dot from the change's direction. */
function dotClass(change: number | null): string {
    if (change === null) {
        return 'bg-neutral-dot';
    }

    return change >= 0 ? 'bg-ok' : 'bg-danger';
}

/** A change as an arrow and a percentage, said in words for screen readers. */
function Change({ change }: { change: number }) {
    const up = change >= 0;
    const amount = new Intl.NumberFormat(undefined, {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(Math.abs(change));
    const Arrow = up ? ArrowUp : ArrowDown;

    return (
        <span
            className={
                up
                    ? 'text-small text-ok-ink inline-flex items-center gap-1'
                    : 'text-small text-danger-ink inline-flex items-center gap-1'
            }
        >
            <Arrow aria-hidden="true" className="size-3.5" />
            <span aria-hidden="true">{amount}</span>
            <span className="sr-only">
                {up
                    ? __('Up :amount', { amount })
                    : __('Down :amount', { amount })}
            </span>
        </span>
    );
}

/**
 * A plugin's figures (docs/plans/plugin-sockets-2.md, A9; design-system
 * §4.28 figure tile): the value large, its label beneath with a coloured
 * dot, and its change on its own line.
 */
export function AreaFigures({
    data,
    label,
}: {
    data: AreaFiguresData;
    label: string;
}) {
    return (
        <section
            aria-label={label}
            data-test="area-figures"
            className="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            {data.items.map((item) => (
                <div
                    key={item.id}
                    data-figure={item.id}
                    className="bg-surface shadow-raised flex flex-col gap-1 rounded-lg px-5 py-4"
                >
                    <span className="text-title font-semibold tabular-nums">
                        {shown(item)}
                    </span>
                    <span className="text-small text-ink-2 flex items-center gap-2">
                        <span
                            aria-hidden="true"
                            className={`size-2 shrink-0 rounded-full ${dotClass(item.change)}`}
                        />
                        {item.label}
                    </span>
                    {item.change !== null && <Change change={item.change} />}
                </div>
            ))}
        </section>
    );
}
