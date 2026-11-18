import { Form, Link } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { RowActionsMenu } from './row-actions';
import type { RowAction } from './row-actions';
import { splitRowActions } from './row-action-order';

const LINK =
    'text-accent-ink focus-visible:ring-ring -ml-1 inline-flex min-h-6 items-center rounded-sm px-1 hover:underline focus-visible:ring-2 focus-visible:outline-none';

/**
 * A row's actions as links under its title (I-3130): up to four on the line,
 * and past four the first three and the destructive one, the rest under
 * More. The line is always shown, never only on hover, so touch screens
 * and the keyboard find it (docs/architecture/design-system.md, 4.26).
 */
export function RowLinks({
    items,
    label,
    more,
}: {
    items: RowAction[];
    /** Names the row, as in "Actions for Hello". */
    label: string;
    /** Names the row's More, as in "More actions for Hello". */
    more: string;
}) {
    const { line, more: rest } = splitRowActions(items);

    return (
        <div
            role="group"
            aria-label={label}
            data-test="row-links"
            className="mt-0.5 flex flex-wrap items-center gap-x-1 text-xs"
        >
            {line.map((item, at) => (
                <Fragment key={item.id}>
                    {at > 0 && (
                        <span aria-hidden="true" className="text-ink-2">
                            ·
                        </span>
                    )}
                    <LineItem item={item} />
                </Fragment>
            ))}
            {rest.length > 0 && (
                <>
                    <span aria-hidden="true" className="text-ink-2">
                        ·
                    </span>
                    <RowActionsMenu items={rest} label={more} />
                </>
            )}
        </div>
    );
}

/** One action on the line, by what it does: goes, opens something, or sends. */
function LineItem({ item }: { item: RowAction }): ReactElement {
    const className = cn(LINK, item.destructive && 'text-danger-ink');

    if ('href' in item) {
        return item.newTab ? (
            <a
                href={item.href}
                target="_blank"
                rel="noreferrer"
                data-action={item.id}
                className={className}
            >
                {item.label}
                <span className="sr-only">, opens in a new tab</span>
            </a>
        ) : (
            <Link href={item.href} data-action={item.id} className={className}>
                {item.label}
            </Link>
        );
    }

    if ('onSelect' in item) {
        return (
            <button
                type="button"
                data-action={item.id}
                className={className}
                onClick={item.onSelect}
            >
                {item.label}
            </button>
        );
    }

    return (
        <Form {...item.form} className="contents">
            {({ processing, errors }) => {
                const button = (
                    <button
                        type="submit"
                        data-action={item.id}
                        disabled={processing}
                        className={className}
                    >
                        {item.label}
                    </button>
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
                            className="basis-full"
                        />
                    </>
                );
            }}
        </Form>
    );
}
