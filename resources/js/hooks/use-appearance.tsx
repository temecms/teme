import { useSyncExternalStore } from 'react';
import { savePreferences } from '@/lib/preferences';

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

const listeners = new Set<() => void>();
let currentAppearance: Appearance = 'system';

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredAppearance = (): Appearance => {
    if (typeof window === 'undefined') {
        return 'system';
    }

    return (localStorage.getItem('appearance') as Appearance) || 'system';
};

const isDarkMode = (appearance: Appearance): boolean => {
    return appearance === 'dark' || (appearance === 'system' && prefersDark());
};

const applyTheme = (appearance: Appearance): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = isDarkMode(appearance);
    const root = document.documentElement;

    // Every color changes at once: with transitions held off, no element
    // fades between the schemes and is caught half-drawn.
    root.classList.add('scheme-switching');
    root.classList.toggle('dark', isDark);
    root.style.colorScheme = isDark ? 'dark' : 'light';

    // Reading a computed style puts the new colors in place while the hold
    // lasts, so lifting it on the next frame starts no transition.
    void window.getComputedStyle(root).color;
    window.requestAnimationFrame(() =>
        root.classList.remove('scheme-switching'),
    );
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const mediaQuery = (): MediaQueryList | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const handleSystemThemeChange = (): void => applyTheme(currentAppearance);

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!localStorage.getItem('appearance')) {
        localStorage.setItem('appearance', 'system');
        setCookie('appearance', 'system');
    }

    currentAppearance = getStoredAppearance();
    applyTheme(currentAppearance);

    // Set up system theme change listener
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

// Whether a change is saved as the person's preference: on while a signed-in
// screen is open (AppLayout says so), off on the signed-out screens, which
// keep the cookie. Not read from the page: the toaster uses this hook
// outside Inertia's component.
let savesAsPreference = false;

/** Called by the signed-in layout while it is open. */
export function setAppearanceSaving(on: boolean): void {
    savesAsPreference = on;
}

/**
 * Puts the signed-in person's saved mode in place: the server's value wins,
 * and localStorage keeps only the hint initializeTheme() reads before the
 * first render, so another machine's stale hint lasts one paint at most.
 */
export function syncAppearance(mode: Appearance): void {
    if (typeof window === 'undefined' || mode === currentAppearance) {
        return;
    }

    currentAppearance = mode;
    localStorage.setItem('appearance', mode);
    applyTheme(mode);
    notify();
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'system',
    );

    const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
        ? 'dark'
        : 'light';

    const updateAppearance = (mode: Appearance): void => {
        const previous = currentAppearance;
        currentAppearance = mode;

        // The hint for the next page load, before anything renders.
        localStorage.setItem('appearance', mode);

        applyTheme(mode);
        notify();

        if (!savesAsPreference) {
            // Signed-out screens have no preferences: the cookie is theirs.
            setCookie('appearance', mode);

            return;
        }

        // Applied at once; saved as the person's preference, and put back if
        // the save fails.
        void savePreferences({ 'appearance.mode': mode }).then((saved) => {
            if (!saved && currentAppearance === mode) {
                syncAppearance(previous);
            }
        });
    };

    return { appearance, resolvedAppearance, updateAppearance } as const;
}
