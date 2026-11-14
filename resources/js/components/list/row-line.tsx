import { Form, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import type { RowAction } from './row-actions';

const ITEM = 'text-small min-h-6 px-1 pointer-coarse:min-h-(--ctl-h-sm)';

/**
 * A row's line of actions under its title (I-3398, the hybrid row actions):
 * always visible, on touch and by keyboard alike, one line of text buttons.
 * Each names its row to a screen reader, as in "Edit: Hello".
 */
export function RowLine({
    items,
    title,
}: {
    items: RowAction[];
    /** The row's title, as shown: it names the group and each action. */
    title: string;
}) {
    return (
        <div
            role="group"
            aria-label={__('Actions for :title', { title })}
            data-test="row-line"
            className="-ms-1 flex flex-wrap items-center gap-1"
        >
            {items.map((item) => (
                <LineItem key={item.id} item={item} title={title} />
            ))}
        </div>
    );
}

/** One action on the line, by what it does: goes, opens something, or sends. */
function LineItem({
    item,
    title,
}: {
    item: RowAction;
    title: string;
}): ReactElement {
    const tone = item.destructive ? 'danger' : 'accent';
    const named = (
        <>
            {item.label}
            <span className="sr-only">{`: ${title}`}</span>
        </>
    );

    if ('href' in item) {
        return (
            <TextButton asChild tone={tone} className={ITEM}>
                {item.newTab ? (
                    <a
                        href={item.href}
                        target="_blank"
                        rel="noreferrer"
                        data-action={item.id}
                    >
                        {named}
                        <span className="sr-only">
                            {__(', opens in a new tab')}
                        </span>
                    </a>
                ) : (
                    <Link href={item.href} data-action={item.id}>
                        {named}
                    </Link>
                )}
            </TextButton>
        );
    }

    if ('onSelect' in item) {
        return (
            <TextButton
                tone={tone}
                data-action={item.id}
                className={ITEM}
                onClick={item.onSelect}
            >
                {named}
            </TextButton>
        );
    }

    return (
        <Form {...item.form} className="contents">
            {({ processing, errors }) => {
                const button = (
                    <TextButton
                        type="submit"
                        tone={tone}
                        data-action={item.id}
                        disabled={processing}
                        className={ITEM}
                    >
                        {named}
                    </TextButton>
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
