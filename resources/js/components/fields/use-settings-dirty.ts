import { useCallback, useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';

/** The form's data as one comparable string; a file counts by its name. */
function readForm(form: HTMLFormElement): string {
    return JSON.stringify(
        [...new FormData(form).entries()].map(([name, value]) => [
            name,
            typeof value === 'string' ? value : value.name,
        ]),
    );
}

/**
 * Whether a settings form differs from what it held when it was drawn
 * (docs/architecture/design-system.md, 4.12): its data is read again on
 * every input, change and click, and whenever its fields change in the
 * page, so a row added or a hidden value set by a button counts too.
 * `settle()` takes what it holds now as the new starting point.
 */
export function useSettingsDirty(anchor: RefObject<HTMLElement | null>): {
    dirty: boolean;
    settle: () => void;
} {
    const [dirty, setDirty] = useState(false);
    const start = useRef<string | null>(null);

    const form = useCallback(
        () => anchor.current?.closest('form') ?? null,
        [anchor],
    );

    const settle = useCallback(() => {
        const element = form();

        start.current = element === null ? null : readForm(element);
        setDirty(false);
    }, [form]);

    useEffect(() => {
        const element = form();

        if (element === null) {
            return;
        }

        // In the next frame, once React has handled the event: a state change
        // made before React's own handler runs puts a controlled field back.
        const check = () =>
            window.requestAnimationFrame(() =>
                setDirty(
                    start.current !== null &&
                        readForm(element) !== start.current,
                ),
            );
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

    return { dirty, settle };
}
