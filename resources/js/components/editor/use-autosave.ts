import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AutosaveController from '@/actions/App/Http/Admin/Contents/AutosaveController';
import ContentEditorController from '@/actions/App/Http/Admin/Contents/ContentEditorController';
import { guardHistory } from '@/lib/history-guard';
import type { EditorPerson } from '@/types';
import { post, reasonOf } from './editor-http';
import type { LinkChange } from './link-changes';
import { retryDelay, saveFailure, versionRefused } from './save-failure';
import type { SaveFailure } from './save-failure';

/**
 * How long a visit away waits for the last save before the leave dialog
 * asks: Inertia's XHR client has no timeout, so a stalled save would
 * otherwise hold every link with no sign of it until the request ended.
 */
const HOLD_LIMIT_MS = 12_000;

/**
 * Where saving stands. `offline` is a failed save that tries again by
 * itself (no answer, signed out, a server error), and `refused` one that
 * waits for the next change or has stopped saving; the hook's `failure`
 * says which, in words.
 */
export type AutosaveState =
    | 'idle'
    | 'saving'
    | 'saved'
    | 'stale'
    | 'offline'
    | 'refused';

/** Who changed the article under this person, when a save came back stale. */
export type StaleChange = {
    by: string | null;
    at: string | null;
    workingRevisionId: string | null;
};

type Draft = {
    title: string;
    document: unknown;
    fields: Record<string, unknown>;
    terms: string[];
};

/** The XSRF cookie, for the one request sent without Inertia's client. */
function xsrfToken(): string {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Saves the person's work as they type, to their own autosave: two seconds
 * after typing stops, and at least every ten seconds while it continues, one
 * request at a time. Hiding or closing the tab saves at once. An explicit
 * save, or the first save after a quiet stretch, also writes a revision
 * (checkpoint). A stale answer stops saving and is never retried; retrying
 * is how one person's work overwrites another's. Any other failed save is
 * shown, and tried again with a backoff, stopped, or left for the next
 * change, as saveFailure() decides. While the latest work is stored
 * nowhere, leaving the page asks first.
 */
export function useAutosave({
    contentId,
    title,
    document: body,
    fields,
    terms,
    basedOn,
    idleMs,
    maxMs,
    checkpointAfterMinutes,
    initialSavedAt,
    initialOthers,
    hasAutosave,
    onLinksChanged,
    onSlug,
}: Draft & {
    contentId: string;
    basedOn: string | null;
    idleMs: number;
    maxMs: number;
    checkpointAfterMinutes: number;
    initialSavedAt: string | null;
    initialOthers: EditorPerson[];
    hasAutosave: boolean;
    /** The links the server stored differently, from each saved autosave's answer (I-452). */
    onLinksChanged?: (changes: LinkChange[]) => void;
    /** The address a checkpoint's answer gives the draft, which a first title may have changed (I-425). */
    onSlug?: (slug: string) => void;
}) {
    const [state, setState] = useState<AutosaveState>('idle');
    const [savedAt, setSavedAt] = useState<string | null>(initialSavedAt);
    const [otherEditors, setOtherEditors] =
        useState<EditorPerson[]>(initialOthers);
    const [pending, setPending] = useState(false);
    const [stale, setStale] = useState<StaleChange | null>(null);
    const [failure, setFailure] = useState<SaveFailure | null>(null);

    const draft = useRef<Draft>({ title, document: body, fields, terms });
    const based = useRef(basedOn);
    const version = useRef(0);
    const savedVersion = useRef(0);
    const inFlight = useRef<Promise<void> | null>(null);
    const stopped = useRef(false);
    const absorb = useRef(false);
    const autosaved = useRef(hasAutosave);
    const linksChanged = useRef(onLinksChanged);
    const slugChanged = useRef(onSlug);
    const lastRevisionAt = useRef(Date.now());
    const backoff = useRef(1000);
    const idleTimer = useRef<number | null>(null);
    const maxTimer = useRef<number | null>(null);
    const retryTimer = useRef<number | null>(null);
    // Whether leaving now would lose work: the latest changes are stored
    // nowhere, because the last autosave failed or came back stale.
    const atRisk = useRef(false);
    // Whether the last failure was a signed-out one.
    const retryOnShow = useRef(false);
    const unmounted = useRef(false);
    const entry = useRef<{ url: string; state: unknown } | null>(null);
    // This editor's saves are numbered, so the server keeps the newest words
    // whatever order they arrive in.
    const editorSession = useRef(
        `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`,
    );
    const saveNumber = useRef(0);
    const seen = useRef<Draft>({ title, document: body, fields, terms });

    draft.current = { title, document: body, fields, terms };
    linksChanged.current = onLinksChanged;
    slugChanged.current = onSlug;

    const clearTimers = () => {
        for (const timer of [idleTimer, maxTimer, retryTimer]) {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
                timer.current = null;
            }
        }
    };

    /** The newest draft as an autosave sends it, with the next number. */
    const payload = () => {
        saveNumber.current += 1;

        return {
            ...draft.current,
            based_on_revision_id: based.current,
            editor_session: editorSession.current,
            save_number: saveNumber.current,
        };
    };

    const markStale = useCallback((answer: Record<string, unknown>) => {
        stopped.current = true;
        atRisk.current = true;
        clearTimers();
        setStale({
            by: (answer.changedBy as string | null) ?? null,
            at: (answer.changedAt as string | null) ?? null,
            workingRevisionId:
                (answer.workingRevisionId as string | null) ?? null,
        });
        setState('stale');
    }, []);

    /** Writes the autosave into a revision; the new revision's ID, or null. */
    const checkpointNow = useCallback(async (): Promise<string | null> => {
        const answer = await post(
            ContentEditorController.checkpoint(contentId).url,
        );

        if (answer.status === 409) {
            markStale(answer.body);

            return null;
        }

        // Only the version was refused: the autosave holds the latest text.
        // Its reason is shown as today, with no notice, and nothing retries.
        if (answer.status >= 400) {
            setFailure(versionRefused(reasonOf(answer.body)));
            setState('refused');

            return null;
        }

        const revisionId = answer.body.revisionId as string;

        if (typeof answer.body.slug === 'string') {
            slugChanged.current?.(answer.body.slug);
        }

        based.current = revisionId;
        autosaved.current = false;
        lastRevisionAt.current = Date.now();
        setSavedAt(new Date().toISOString());
        setFailure(null);
        setState('saved');

        return revisionId;
    }, [contentId, markStale]);

    /**
     * An autosave the server didn't keep, or no answer at all: shown, then
     * tried again with the backoff, stopped, or left for the next change, as
     * saveFailure() decides. Its work is at risk until a save succeeds.
     */
    const fail = useCallback(
        (
            status: number | null,
            reason: string,
            retryAfter: string | null = null,
        ) => {
            if (unmounted.current) {
                return;
            }

            const failed = saveFailure(status, reason);

            setFailure(failed);
            setState(failed.then === 'retry' ? 'offline' : 'refused');
            atRisk.current = true;
            retryOnShow.current = status === 401 || status === 419;

            if (retryTimer.current !== null) {
                window.clearTimeout(retryTimer.current);
                retryTimer.current = null;
            }

            if (failed.then === 'stop') {
                stopped.current = true;
                clearTimers();

                return;
            }

            if (failed.then === 'retry') {
                retryTimer.current = window.setTimeout(
                    () => {
                        retryTimer.current = null;
                        void flushRef.current();
                    },
                    retryDelay(backoff.current, retryAfter),
                );
                backoff.current = Math.min(backoff.current * 2, 60_000);
            }
        },
        [],
    );

    const send = useCallback(async (): Promise<void> => {
        const sending = version.current;
        setState('saving');

        try {
            const answer = await post(
                AutosaveController.store(contentId).url,
                payload(),
            );

            if (answer.status >= 400 && answer.body.stale !== true) {
                fail(
                    answer.status,
                    reasonOf(answer.body),
                    answer.retryAfter ?? null,
                );

                return;
            }

            setOtherEditors((answer.body.otherEditors as EditorPerson[]) ?? []);

            if (answer.body.stale === true) {
                markStale(answer.body);

                return;
            }

            savedVersion.current = sending;
            autosaved.current = true;
            backoff.current = 1000;
            atRisk.current = false;
            retryOnShow.current = false;
            setSavedAt((answer.body.savedAt as string | null) ?? null);
            setFailure(null);
            setPending(version.current !== sending);
            setState('saved');

            // The server stored some links differently: the editor shows
            // what is saved (I-452).
            const links = (answer.body.links as LinkChange[] | undefined) ?? [];

            if (links.length > 0) {
                linksChanged.current?.(links);
            }

            // A long session leaves a readable history, not one huge revision.
            if (
                Date.now() - lastRevisionAt.current >=
                checkpointAfterMinutes * 60_000
            ) {
                await checkpointNow();
            }
        } catch {
            fail(null, '');
        }
    }, [contentId, checkpointAfterMinutes, checkpointNow, markStale, fail]);

    /** Saves what is unsaved now, after any save already on its way. */
    const flush = useCallback(async (): Promise<void> => {
        if (idleTimer.current !== null) {
            window.clearTimeout(idleTimer.current);
            idleTimer.current = null;
        }

        if (maxTimer.current !== null) {
            window.clearTimeout(maxTimer.current);
            maxTimer.current = null;
        }

        while (inFlight.current !== null) {
            await inFlight.current;
        }

        if (stopped.current || version.current === savedVersion.current) {
            return;
        }

        inFlight.current = send();
        await inFlight.current;
        inFlight.current = null;
    }, [send]);

    const flushRef = useRef(flush);
    flushRef.current = flush;

    // Every change waits for a pause, or the maximum. Comparing with what was
    // last seen, not skipping the first run, keeps a re-run effect from
    // counting as a change.
    useEffect(() => {
        const last = seen.current;

        if (
            last.title === title &&
            last.document === body &&
            last.fields === fields &&
            last.terms === terms
        ) {
            return;
        }

        seen.current = { title, document: body, fields, terms };

        // The change Use made is in the revision the server wrote (adopt()).
        if (absorb.current) {
            absorb.current = false;

            return;
        }

        if (stopped.current) {
            return;
        }

        version.current += 1;
        setPending(true);
        // Where the person is typing, which Back would leave (Inertia writes
        // the entry after the editor first draws, so it is read here).
        entry.current = {
            url: window.location.href,
            state: window.history.state as unknown,
        };

        if (idleTimer.current !== null) {
            window.clearTimeout(idleTimer.current);
        }

        idleTimer.current = window.setTimeout(
            () => void flushRef.current(),
            idleMs,
        );

        if (maxTimer.current === null) {
            maxTimer.current = window.setTimeout(
                () => void flushRef.current(),
                maxMs,
            );
        }
    }, [title, body, fields, terms, idleMs, maxMs]);

    // Hidden tabs save at once; a closing one, or an editor left for another
    // page, sends what it has with keepalive, which outlives the page, and
    // reconnecting tries again.
    useEffect(() => {
        // A tab shown again after signing out tries at once, as after
        // signing in again in another tab.
        const hidden = () => {
            if (
                window.document.visibilityState === 'hidden' ||
                retryOnShow.current
            ) {
                void flushRef.current();
            }
        };

        const leaving = () => {
            if (stopped.current || version.current === savedVersion.current) {
                return;
            }

            void fetch(AutosaveController.store(contentId).url, {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify(payload()),
                // Refused above 64 KB, or lost with the page: the prompt
                // before the unload has already asked.
            }).catch(() => undefined);
        };

        const online = () => void flushRef.current();

        unmounted.current = false;
        window.document.addEventListener('visibilitychange', hidden);
        window.addEventListener('pagehide', leaving);
        window.addEventListener('online', online);

        return () => {
            unmounted.current = true;
            window.document.removeEventListener('visibilitychange', hidden);
            window.removeEventListener('pagehide', leaving);
            window.removeEventListener('online', online);
            clearTimers();
            // The browser's Back button unmounts the editor without a
            // pagehide or a visit to hold: the page stays loaded, so what is
            // unsent goes as an ordinary save, after any on its way.
            void flushRef.current();
        };
    }, [contentId]);

    // Where a person leaving is going, held until the last save answers or
    // they choose in the leave dialog; and the one replay of a held visit,
    // which passes the guard.
    const held = useRef<(() => void) | null>(null);
    const passing = useRef(false);
    const [asking, setAsking] = useState(false);

    /**
     * Leaves for where the person was going: the latest changes stay
     * unsaved, as they chose in the leave dialog.
     */
    const leave = useCallback(() => {
        const going = held.current;
        held.current = null;
        atRisk.current = false;
        setAsking(false);
        going?.();
    }, []);

    /** Stays in the editor, with every word still in it. */
    const stay = useCallback(() => {
        held.current = null;
        setAsking(false);
    }, []);

    /**
     * Holds a way out: at once to the leave dialog while the latest work is
     * stored nowhere, and otherwise after an ordinary save, after any on
     * its way, going on once it answers or asking if it failed. A second
     * way out while one is held replaces it.
     */
    const holdThenGo = useCallback((go: () => void) => {
        const waiting = held.current !== null;
        held.current = go;

        if (waiting) {
            return;
        }

        if (atRisk.current) {
            setAsking(true);

            return;
        }

        // A save that never answers would hold the way out for good: past
        // the limit, the leave dialog asks, and the save may still land.
        const limit = window.setTimeout(() => {
            if (!unmounted.current && held.current !== null) {
                setAsking(true);
            }
        }, HOLD_LIMIT_MS);

        void flushRef.current().then(() => {
            window.clearTimeout(limit);

            if (unmounted.current || held.current === null) {
                return;
            }

            if (atRisk.current) {
                setAsking(true);

                return;
            }

            const going = held.current;
            held.current = null;
            setAsking(false);
            going();
        });
    }, []);

    /** Whether a way out now would leave words the server doesn't have. */
    const unsent = useCallback(
        () =>
            atRisk.current ||
            (!stopped.current &&
                (version.current !== savedVersion.current ||
                    inFlight.current !== null)),
        [],
    );

    // While the server doesn't have the newest words, leaving waits or
    // asks: the browser's own prompt before a reload or a closed tab, and,
    // for a visit to another admin page, an ordinary save first, or the
    // leave dialog while the latest work is stored nowhere. A poll, partial
    // reload or prefetch is held back without asking while the work is at
    // risk: if the session has ended, its answer could replace the editor
    // with the sign-in page.
    useEffect(() => {
        // A true unload can't wait for a save, and its keepalive send can't
        // be relied on above 64 KB or beside a save on its way: the browser
        // asks whenever the server doesn't have the newest words yet.
        const unload = (event: BeforeUnloadEvent) => {
            if (unsent()) {
                event.preventDefault();
                // Older Safari asks only when this is set.
                event.returnValue = '';
            }
        };

        window.addEventListener('beforeunload', unload);

        const replay = (visit: PendingVisit) => {
            passing.current = true;
            router.visit(visit.url, {
                method: visit.method,
                data: visit.data,
                replace: visit.replace,
                preserveScroll: visit.preserveScroll,
                preserveState: visit.preserveState,
                headers: visit.headers,
            });
            passing.current = false;
        };

        // The browser's Back or Forward button sends Inertia no `before`
        // event. While words are unsent, the step is caught before Inertia
        // sees it, the editor's entry is put back where the person still is,
        // and the way out waits like any other; going on is a visit to where
        // they were headed.
        const popped = (): boolean => {
            const editor = entry.current;

            if (
                editor === null ||
                window.location.href === editor.url ||
                !unsent()
            ) {
                return false;
            }

            const target = window.location.href;
            window.history.pushState(editor.state, '', editor.url);

            holdThenGo(() => {
                passing.current = true;
                router.visit(target);
                passing.current = false;
            });

            return true;
        };

        const stopHoldingHistory = guardHistory(popped);

        const stopGuarding = router.on('before', (event) => {
            const { visit } = event.detail;

            if (visit.only.length > 0 || visit.prefetch || visit.async) {
                return !atRisk.current;
            }

            if (passing.current || !unsent()) {
                return true;
            }

            // Inertia's before event gives the visit without its callbacks
            // (onSuccess, onError, the Form's own), so a replay would lose
            // them: a form's submission goes as it is, with the save sent
            // after any on its way.
            if (visit.method !== 'get') {
                void flushRef.current();

                return true;
            }

            holdThenGo(() => replay(visit));

            return false;
        });

        return () => {
            window.removeEventListener('beforeunload', unload);
            stopHoldingHistory();
            stopGuarding();
        };
    }, [holdThenGo, unsent]);

    /**
     * Lets the page go without asking, for a person who has already chosen
     * to leave their unsaved work, as the stale dialog offers.
     */
    const release = useCallback(() => {
        atRisk.current = false;
    }, []);

    /**
     * The presence poll learned the session has ended: the editor shows what
     * a signed-out save shows, and only words not yet stored stay at risk.
     */
    const signedOut = useCallback(() => {
        if (retryOnShow.current) {
            return;
        }

        const unsentNow =
            atRisk.current ||
            version.current !== savedVersion.current ||
            inFlight.current !== null;

        fail(401, '');
        atRisk.current = unsentNow;
    }, [fail]);

    /**
     * The presence poll was answered again after a sign-in elsewhere: a
     * signed-out state with nothing to send clears, and words waiting are sent.
     */
    const signedIn = useCallback(() => {
        if (!retryOnShow.current) {
            return;
        }

        if (
            version.current !== savedVersion.current ||
            inFlight.current !== null
        ) {
            void flushRef.current();

            return;
        }

        if (retryTimer.current !== null) {
            window.clearTimeout(retryTimer.current);
            retryTimer.current = null;
        }

        atRisk.current = false;
        retryOnShow.current = false;
        backoff.current = 1000;
        setFailure(null);
        setState('saved');
    }, []);

    /**
     * An explicit save: the latest work saved, then written into a revision.
     * Resolves to the working revision's ID, which is the one to publish, or
     * null when the save was refused or stale.
     */
    const checkpoint = useCallback(async (): Promise<string | null> => {
        await flush();

        if (stopped.current || version.current !== savedVersion.current) {
            return null;
        }

        if (!autosaved.current) {
            return based.current;
        }

        while (inFlight.current !== null) {
            await inFlight.current;
        }

        // On its way like any save, so a save after it waits for its answer
        // and is sent on the revision it writes.
        const writing = checkpointNow();
        const done = writing.then(
            () => undefined,
            () => undefined,
        );
        inFlight.current = done;

        try {
            return await writing;
        } finally {
            if (inFlight.current === done) {
                inFlight.current = null;
            }
        }
    }, [flush, checkpointNow]);

    /**
     * Takes a revision the server wrote for this person, the assistant's
     * change they used, as the base; the very next change is that change,
     * already saved in it, so it isn't saved again as theirs (I-317).
     * Called only right before that change is made.
     */
    const adopt = useCallback((revisionId: string) => {
        based.current = revisionId;
        autosaved.current = false;
        absorb.current = true;
        lastRevisionAt.current = Date.now();
        setSavedAt(new Date().toISOString());
        setState('saved');
    }, []);

    /** The revision this person's work is based on now. */
    const workingRevisionId = useCallback(() => based.current, []);

    return {
        workingRevisionId,
        state,
        savedAt,
        otherEditors,
        setOtherEditors,
        pending,
        stale,
        failure,
        release,
        signedOut,
        signedIn,
        asking,
        leave,
        stay,
        checkpoint,
        adopt,
        markStale,
    };
}
