import { useCallback, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { __ } from '@/lib/i18n';

/**
 * Asks for the password in place when the server answers a save or a
 * publish with 423, as a change to a region's Custom HTML code does once the
 * confirmation window has lapsed. `ask()` resolves true once the password is
 * confirmed, so the caller sends the same request again, and false when the
 * person closes the prompt. One confirmation covers a save and the publish
 * after it, which fall in the same window.
 */
export function useElevated(): {
    ask: () => Promise<boolean>;
    password: ReactNode;
} {
    const [asking, setAsking] = useState(false);
    const waiting = useRef<((confirmed: boolean) => void) | null>(null);

    const settle = (confirmed: boolean) => {
        const resolve = waiting.current;
        waiting.current = null;
        resolve?.(confirmed);
    };

    const ask = useCallback(
        () =>
            new Promise<boolean>((resolve) => {
                // A second ask while one is open waits on the same answer.
                const earlier = waiting.current;
                waiting.current = (confirmed) => {
                    earlier?.(confirmed);
                    resolve(confirmed);
                };
                setAsking(true);
            }),
        [],
    );

    return {
        ask,
        password: (
            <FreshPasswordDialog
                open={asking}
                onOpenChange={(open) => {
                    setAsking(open);

                    if (!open) {
                        // A confirmation closes the prompt too, then settles
                        // true at once; only a prompt closed without one is false.
                        queueMicrotask(() => settle(false));
                    }
                }}
                title={__('Confirm your password')}
                text={__(
                    'Saving or publishing a change to Custom HTML code asks for your password.',
                )}
                onConfirmed={() => settle(true)}
            />
        ),
    };
}
