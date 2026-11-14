import { useCallback, useEffect, useRef, useState } from 'react';
import type { SaveFailure } from '@/components/editor/save-failure';
import { send } from '@/components/editor/editor-http';
import type { EditorPerson } from '@/types';

/**
 * Asks the server who else is editing, as a partial reload of the editor's own
 * page would, but never lets an ended session's answer replace the page: a
 * 401, a 419 or a sign-in page stops the asking and reports the person signed
 * out. It asks again once the save failure clears, or when the tab is shown.
 */
export function usePresence({
    everyMs,
    version,
    failure,
    onOthers,
    onSignedOut,
    onSignedIn,
}: {
    everyMs: number;
    version: string | null;
    failure: SaveFailure | null;
    onOthers: (people: EditorPerson[]) => void;
    onSignedOut: () => void;
    onSignedIn: () => void;
}) {
    const [ended, setEnded] = useState(false);
    const latest = useRef({ onOthers, onSignedOut, onSignedIn });

    latest.current = { onOthers, onSignedOut, onSignedIn };

    useEffect(() => {
        if (failure === null) {
            setEnded(false);
        }
    }, [failure]);

    const ask = useCallback(async () => {
        let answer;

        try {
            answer = await send(
                {
                    url: `${window.location.pathname}${window.location.search}`,
                    method: 'get',
                },
                undefined,
                {
                    'X-Inertia': 'true',
                    'X-Inertia-Version': version ?? '',
                    'X-Inertia-Partial-Component': 'content/edit',
                    'X-Inertia-Partial-Data': 'otherEditors',
                },
            );
        } catch {
            // No answer at all says nothing about the session.
            return;
        }

        const component = answer.body.component;

        if (
            answer.status === 401 ||
            answer.status === 419 ||
            (answer.status < 300 && component !== 'content/edit')
        ) {
            setEnded(true);
            latest.current.onSignedOut();

            return;
        }

        if (answer.status >= 300) {
            // A new deploy (409) or a passing error: the next ask tries again.
            return;
        }

        const props = answer.body.props as
            | { otherEditors?: EditorPerson[] }
            | undefined;

        if (props?.otherEditors !== undefined) {
            latest.current.onOthers(props.otherEditors);
        }

        latest.current.onSignedIn();
        setEnded(false);
    }, [version]);

    useEffect(() => {
        if (ended) {
            const shown = () => {
                if (document.visibilityState === 'visible') {
                    void ask();
                }
            };

            document.addEventListener('visibilitychange', shown);

            return () =>
                document.removeEventListener('visibilitychange', shown);
        }

        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                void ask();
            }
        }, everyMs);

        return () => window.clearInterval(timer);
    }, [ask, ended, everyMs]);
}
