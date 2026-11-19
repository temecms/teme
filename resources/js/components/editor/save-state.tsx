import { CircleAlert, CircleCheck, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { __, __n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SaveFailure } from './save-failure';
import type { AutosaveState } from './use-autosave';
import { adminLocale, siteZone } from '@/lib/dates';

/** A time on the site's clock, hours and minutes. */
function clock(iso: string): string {
    return new Intl.DateTimeFormat(adminLocale(), {
        timeZone: siteZone(),
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

/** Where saving stands, as the header's hook names it. */
export type SaveKind = 'saved' | 'saving' | 'offline' | 'stale' | 'refused';

/**
 * Where saving stands, in words that say plainly whether the work is safe
 * (I-1316): every safe state starts "Saved", every unsafe one "Not saved".
 * `phrase` is shown; `detail` is the tooltip, and screen readers hear it
 * after the phrase whenever the work isn't safe. A failed autosave with a
 * notice says its own phrase and description.
 */
export function saveWords(
    state: AutosaveState,
    pending: boolean,
    savedAt: string | null,
    failure: SaveFailure | null,
): { kind: SaveKind; phrase: string; detail: string } {
    if (
        (state === 'offline' || state === 'refused') &&
        failure !== null &&
        failure.title !== null
    ) {
        return {
            kind: state,
            phrase: failure.phrase,
            detail: failure.description ?? '',
        };
    }

    if (state === 'offline') {
        return {
            kind: 'offline',
            phrase: 'Not saved: offline',
            detail: 'Teme can’t reach the server. Your changes stay on this page and are saved when the connection is back. Keep this tab open until then.',
        };
    }

    if (state === 'stale') {
        return {
            kind: 'stale',
            phrase: 'Not saved: changed by someone else',
            detail: 'Someone else saved this after you opened it, so saving has stopped. Choose what to do with your changes.',
        };
    }

    if (state === 'refused') {
        const refusal = failure?.description ?? null;

        return {
            kind: 'refused',
            phrase: 'Not saved',
            detail: `${refusal === null ? '' : `${refusal} `}Your changes stay on this page, and saving tries again after your next change.`,
        };
    }

    if (state === 'saving' || pending) {
        return {
            kind: 'saving',
            phrase: 'Saving…',
            detail: 'Saving your changes…',
        };
    }

    return savedAt === null
        ? {
              kind: 'saved',
              phrase: 'Saved',
              detail: 'Everything here is saved.',
          }
        : {
              kind: 'saved',
              phrase: `Saved ${clock(savedAt)}`,
              detail: `Your changes were saved at ${clock(savedAt)}.`,
          };
}

/**
 * When the work was last saved, as the bar says it: "just now" for the
 * first 45 seconds, then minutes ago for the first hour, then the time.
 */
export function savedAgo(savedAt: string, now: number): string {
    const seconds = Math.max(0, (now - new Date(savedAt).getTime()) / 1000);

    if (seconds < 45) {
        return __('Saved just now');
    }

    if (seconds < 3600) {
        const minutes = Math.max(1, Math.round(seconds / 60));

        return __n('Saved :count min ago|Saved :count min ago', minutes);
    }

    return __('Saved :time', { time: clock(savedAt) });
}

/** The clock the "minutes ago" reads, moved on every 20 seconds. */
function useNow(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 20000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

/**
 * Where saving stands, after the status in the bar (4.39): a tick and
 * "Saved 2 min ago" when the work is safe, a turning circle while it saves,
 * an alert and the reason when it isn't. The phrase hides below 640px,
 * where the icon and the sentence for screen readers remain.
 * `data-state` and `data-saved-at` are what tests wait on, never the words.
 */
export function SaveState({
    state,
    pending,
    savedAt,
    failure,
}: {
    state: AutosaveState;
    pending: boolean;
    savedAt: string | null;
    failure: SaveFailure | null;
}) {
    const now = useNow();
    const words = saveWords(state, pending, savedAt, failure);
    const unsafe = words.kind !== 'saved' && words.kind !== 'saving';
    const phrase =
        words.kind === 'saved' && savedAt !== null
            ? savedAgo(savedAt, now)
            : words.phrase;
    const Icon = unsafe
        ? CircleAlert
        : words.kind === 'saving'
          ? LoaderCircle
          : CircleCheck;

    return (
        <span
            role="status"
            data-test="save-state"
            data-state={words.kind}
            data-saved-at={savedAt ?? undefined}
            title={words.detail}
            className={cn(
                'text-small inline-flex min-w-0 items-center gap-2 whitespace-nowrap',
                unsafe ? 'text-danger-ink' : 'text-ink-3',
            )}
        >
            <Icon
                aria-hidden
                className={cn(
                    'size-4 shrink-0',
                    words.kind === 'saved' && 'text-ok-ink',
                    words.kind === 'saving' && 'motion-safe:animate-spin',
                )}
            />
            <span className="truncate max-sm:sr-only">{phrase}</span>
            {unsafe && <span className="sr-only"> {words.detail}</span>}
        </span>
    );
}
