import { useEffect, useRef, useState } from 'react';
import { Notice } from '@/components/ui/notice';
import { __, __n } from '@/lib/i18n';

/** The wait in words: minutes while a minute or more is left, then seconds. */
function waitWords(seconds: number): string {
    return seconds >= 60
        ? __n(':count minute|:count minutes', Math.ceil(seconds / 60))
        : __n(':count second|:count seconds', seconds);
}

/**
 * The notice a paused sign-in shows: the wait, counted down each second, then
 * gone, calling `onDone`. Read aloud once, as it first appears; the count
 * that changes is for the eye only.
 */
export function SignInPaused({
    seconds,
    onDone,
}: {
    seconds: number;
    onDone: () => void;
}) {
    const [endsAt] = useState(() => Date.now() + seconds * 1000);
    const [left, setLeft] = useState(seconds);
    const done = useRef(onDone);
    done.current = onDone;

    useEffect(() => {
        const timer = window.setInterval(() => {
            const remaining = Math.max(
                0,
                Math.ceil((endsAt - Date.now()) / 1000),
            );

            setLeft(remaining);

            if (remaining === 0) {
                window.clearInterval(timer);
                done.current();
            }
        }, 1000);

        return () => window.clearInterval(timer);
    }, [endsAt]);

    if (left === 0) {
        return null;
    }

    return (
        <Notice tone="warn" role="alert" data-test="sign-in-paused">
            <span className="sr-only">
                {__('Too many tries. Wait :time, then try again.', {
                    time: waitWords(seconds),
                })}
            </span>
            <span aria-hidden="true" data-test="sign-in-paused-wait">
                {__('Too many tries. Wait :time, then try again.', {
                    time: waitWords(left),
                })}
            </span>
        </Notice>
    );
}
