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

const DOTS: Record<SaveKind, string> = {
    saved: 'bg-ok',
    saving: 'bg-warn',
    offline: 'bg-danger',
    stale: 'bg-danger',
    refused: 'bg-danger',
};

/**
 * A dot and a phrase in the header: where saving stands. The phrase is
 * 11px and muted, and hidden below 640px, where the dot and the sentence
 * for screen readers remain. `data-state` and `data-saved-at` are what
 * tests wait on, never the words.
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
    const words = saveWords(state, pending, savedAt, failure);
    const unsafe = words.kind !== 'saved' && words.kind !== 'saving';

    return (
        <span
            role="status"
            data-test="save-state"
            data-state={words.kind}
            data-saved-at={savedAt ?? undefined}
            title={words.detail}
            className="text-ink-2 text-caption inline-flex items-center gap-2 whitespace-nowrap"
        >
            <span
                aria-hidden
                className={cn('size-2 rounded-full', DOTS[words.kind])}
            />
            <span className="max-sm:sr-only">{words.phrase}</span>
            {unsafe && <span className="sr-only"> {words.detail}</span>}
        </span>
    );
}
