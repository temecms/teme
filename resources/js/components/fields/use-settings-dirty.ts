import { useCallback, useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';

/** The form's data, each name's values joined; a file counts by its name. */
function readForm(form: HTMLFormElement): Record<string, string> {
    const values: Record<string, string[]> = {};

    for (const [name, value] of new FormData(form).entries()) {
        (values[name] ??= []).push(
            typeof value === 'string' ? value : value.name,
        );
    }

    return Object.fromEntries(
        Object.entries(values).map(([name, list]) => [
            name,
            JSON.stringify(list),
        ]),
    );
}

/** The names whose values differ between two readings, each setting once (`values[key][]` counts as `values[key]`). */
function differences(
    before: Record<string, string>,
    now: Record<string, string>,
): string[] {
    const names = new Set([...Object.keys(before), ...Object.keys(now)]);

    return [
        ...new Set(
            [...names]
                .filter((name) => before[name] !== now[name])
                .map((name) => name.replace(/\[\]$/, '')),
        ),
    ];
}

/**
 * Whether a settings form differs from what it held when it was drawn,
 * and which of its fields do
 * (docs/architecture/design-system.md, 4.12): its data is read again on
 * every input, change and click, and whenever its fields change in the
 * page, so a row added or a hidden value set by a button counts too.
 * `settle()` takes what it holds now as the new starting point.
 */
export function useSettingsDirty(anchor: RefObject<HTMLElement | null>): {
    dirty: boolean;
    /** The names of the fields that changed, such as `values[site_title]`. */
    changed: string[];
    settle: () => void;
} {
    const [changed, setChanged] = useState<string[]>([]);
    const start = useRef<Record<string, string> | null>(null);

    const form = useCallback(
        () => anchor.current?.closest('form') ?? null,
        [anchor],
    );

    const settle = useCallback(() => {
        const element = form();

        start.current = element === null ? null : readForm(element);
        setChanged([]);
    }, [form]);

    useEffect(() => {
        const element = form();

        if (element === null) {
            return;
        }

        // In the next frame, once React has handled the event: a state change
        // made before React's own handler runs puts a controlled field back.
        const check = () =>
            window.requestAnimationFrame(() => {
                const next =
                    start.current === null
                        ? []
                        : differences(start.current, readForm(element));

                setChanged((before) =>
                    before.join('\n') === next.join('\n') ? before : next,
                );
            });
        // After the first paint, so fields a section fills in an effect count as they start.
        const frame = window.requestAnimationFrame(settle);
        const observer = new MutationObserver(check);

        ['input', 'change', 'click'].forEach((type) =>
            element.addEventListener(type, check),
        );
        observer.observe(element, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: [
                'value',
                'checked',
                'name',
                'disabled',
                'data-state',
            ],
        });

        return () => {
            window.cancelAnimationFrame(frame);
            observer.disconnect();
            ['input', 'change', 'click'].forEach((type) =>
                element.removeEventListener(type, check),
            );
        };
    }, [form, settle]);

    return { dirty: changed.length > 0, changed, settle };
}
