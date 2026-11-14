// A screen's say over the browser's Back and Forward buttons. Inertia
// answers a step through history at once, with no `before` event, so one
// listener is installed before Inertia's own and asks the screen that holds
// the guard first.

/** Answers true when it has taken the step itself, so Inertia never sees it. */
type HistoryGuard = (event: PopStateEvent) => boolean;

let current: HistoryGuard | null = null;

/** Installs the one listener; app.tsx calls it before Inertia starts. */
export function installHistoryGuard(): void {
    window.addEventListener('popstate', (event) => {
        if (current?.(event) === true) {
            event.stopImmediatePropagation();
        }
    });
}

/** Holds the guard until the returned function lets it go. */
export function guardHistory(guard: HistoryGuard): () => void {
    current = guard;

    return () => {
        if (current === guard) {
            current = null;
        }
    };
}
