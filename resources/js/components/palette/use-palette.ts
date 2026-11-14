import { useHttp } from '@inertiajs/react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import SearchController from '@/actions/App/Http/Admin/SearchController';
import type { PaletteCommand } from './palette-context';

/** One search result, as AdminSearch gives it. */
export type PaletteResult = {
    id: string;
    title: string;
    subtitle: string;
    url: string;
    kind: 'content' | 'media' | 'term' | 'user' | 'setting';
    /** Content only: whether this person may edit it (I-409). */
    canEdit?: boolean;
};

/** The search's answer, one list per group. */
export type PaletteResults = {
    content: PaletteResult[];
    media: PaletteResult[];
    terms: PaletteResult[];
    users: PaletteResult[];
    settings: PaletteResult[];
    /**
     * The list's In text search for the query: the published text is
     * searched there, not here (B-199). Null for someone who may not open it.
     */
    textSearch: { url: string; plural: string } | null;
};

const NONE: PaletteResults = {
    content: [],
    media: [],
    terms: [],
    users: [],
    settings: [],
    textSearch: null,
};

/**
 * The search for what is typed, 150 ms after typing stops, the previous
 * request cancelled. An empty query costs no request: going somewhere and
 * running a command are already in the browser.
 */
export function usePaletteSearch(query: string): {
    results: PaletteResults;
    searching: boolean;
} {
    const { submit, cancel } = useHttp();
    const [results, setResults] = useState<PaletteResults>(NONE);
    const [searching, setSearching] = useState(false);
    const term = query.trim();

    useEffect(() => {
        cancel();

        if (term === '') {
            setResults(NONE);
            setSearching(false);

            return;
        }

        setSearching(true);

        const timer = window.setTimeout(() => {
            void (
                submit(
                    SearchController({ query: { q: term } }),
                ) as Promise<PaletteResults>
            )
                .then((found) => setResults(found))
                .catch(() => {
                    // Cancelled by the next keystroke, or the connection
                    // failed: the next query asks again.
                })
                .finally(() => setSearching(false));
        }, 150);

        return () => window.clearTimeout(timer);
    }, [term, submit, cancel]);

    return { results, searching };
}

// The commands the current screen registers, such as the editor's. A screen
// adds them while it is open and they go when it leaves.
let screenCommands: PaletteCommand[][] = [];
const listeners = new Set<() => void>();

const notify = () => listeners.forEach((listener) => listener());

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/** Registers a screen's commands for as long as the screen is open. */
export function usePaletteCommands(commands: PaletteCommand[]): void {
    useEffect(() => {
        screenCommands = [...screenCommands, commands];
        notify();

        return () => {
            screenCommands = screenCommands.filter((set) => set !== commands);
            notify();
        };
    }, [commands]);
}

/** Every command the open screens registered. */
export function useScreenCommands(): PaletteCommand[][] {
    return useSyncExternalStore(
        subscribe,
        () => screenCommands,
        () => screenCommands,
    );
}

/** ⌘K or Ctrl K anywhere in the admin toggles the palette. */
export function usePaletteShortcut(toggle: () => void): void {
    useEffect(() => {
        const keys = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                toggle();
            }
        };

        window.addEventListener('keydown', keys);

        return () => window.removeEventListener('keydown', keys);
    }, [toggle]);
}
