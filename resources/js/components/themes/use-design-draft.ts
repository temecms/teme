import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import ThemesController from '@/actions/App/Http/Admin/ThemesController';
import { send } from '@/components/editor/editor-http';
import type { DesignFieldset, DesignSettingsPage } from '@/types';

/** How long a change waits for the next before the draft is saved. */
const SAVE_AFTER_MS = 800;

type Values = Record<string, Record<string, unknown>>;

export type SaveState = 'idle' | 'saving' | 'saved' | 'failed';

/**
 * The design's draft, for Themes and a theme's settings page alike: every
 * change is saved as a draft as it is made, only the keys changed; nothing
 * reaches readers until Publish, and each section previews its page on the
 * real site.
 */
export function useDesignDraft(page: DesignSettingsPage) {
    const { errors: pageErrors } = usePage().props;
    const [edits, setEdits] = useState<Values>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [draft, setDraft] = useState(page.draft);
    const [saveState, setSaveState] = useState<SaveState>('idle');
    const [shown, setShown] = useState(page);
    const [pressed, setPressed] = useState<string | null>(null);
    const [activating, setActivating] = useState(false);
    const pending = useRef<Values>({});
    const timer = useRef<number | undefined>(undefined);
    const inFlight = useRef<Promise<void> | null>(null);

    // New props, after a reload, a publish or a discard: what they say wins.
    if (shown !== page) {
        setShown(page);
        setDraft(page.draft);
    }

    const themeGroup = page.themeGroup;
    const themeValues = {
        ...page.values.theme,
        ...(themeGroup === null ? {} : edits[themeGroup]),
    };
    const designValues = {
        ...page.values.design,
        ...(edits.design ?? {}),
    };
    const errorOf = (group: string, key: string): string | undefined =>
        errors[`groups.${group}.${key}`] ??
        pageErrors[`groups.${group}.${key}`];

    /** Sends what changed since the last save; a change made meanwhile waits its turn. */
    const save = useCallback(async (): Promise<void> => {
        window.clearTimeout(timer.current);

        if (inFlight.current !== null) {
            await inFlight.current;
        }

        const groups = pending.current;

        if (Object.keys(groups).length === 0) {
            return;
        }

        pending.current = {};
        setSaveState('saving');

        const request = (async () => {
            const sent = Object.entries(groups).flatMap(([group, values]) =>
                Object.keys(values).map((key) => `groups.${group}.${key}`),
            );

            try {
                const answer = await send(ThemesController.saveDraft(), {
                    groups,
                });

                setErrors((current) => {
                    const next = Object.fromEntries(
                        Object.entries(current).filter(
                            ([key]) => !sent.includes(key),
                        ),
                    );

                    if (answer.status === 422) {
                        const found = (answer.body.errors ?? {}) as Record<
                            string,
                            string[]
                        >;

                        for (const [key, messages] of Object.entries(found)) {
                            next[key] = messages[0];
                        }
                    }

                    return next;
                });

                if (answer.status >= 400) {
                    setSaveState('failed');

                    return;
                }

                setDraft(
                    (answer.body.draft ?? null) as DesignSettingsPage['draft'],
                );
                setSaveState('saved');
            } catch {
                setSaveState('failed');
            }
        })();

        inFlight.current = request;
        await request;
        inFlight.current = null;

        if (Object.keys(pending.current).length > 0) {
            await save();
        }
    }, []);

    const change = (group: string, key: string, value: unknown) => {
        setEdits((current) => ({
            ...current,
            [group]: { ...current[group], [key]: value },
        }));
        pending.current = {
            ...pending.current,
            [group]: { ...pending.current[group], [key]: value },
        };
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => void save(), SAVE_AFTER_MS);
    };

    useEffect(() => () => window.clearTimeout(timer.current), []);

    /** Opens the page a section is about, drafts shown, in a new tab. */
    const preview = async (section: DesignFieldset) => {
        // Opened now, while the click still counts: a tab opened after the
        // request would be blocked as a pop-up.
        const tab = window.open('', '_blank');

        await save();

        const answer = await send(ThemesController.previewLink(), { section });
        const url = answer.body.url;

        if (answer.status >= 400 || typeof url !== 'string') {
            tab?.close();
            setSaveState('failed');

            return;
        }

        // Blocked as a pop-up after all: ask once more, now the link is known.
        if (tab === null) {
            window.open(url, '_blank', 'noopener');

            return;
        }

        tab.opener = null;
        tab.location.href = url;
    };

    /**
     * After the server has changed what the screen shows: Inertia keeps the
     * page's state on a post or a delete (preserveState), so what was typed
     * and refused before goes, as finish() clears it after a publish.
     */
    const settled = () => {
        setEdits({});
        setErrors({});
        setSaveState('idle');
    };

    /** Previews a theme: changes waiting are saved first, the switch is drafted, and the panel opens with focus on its heading. */
    const previewTheme = async (code: string) => {
        setPressed(code);
        await save();
        router.post(
            ThemesController.startPreview.url({ code }),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    settled();
                    requestAnimationFrame(() =>
                        document
                            .getElementById('theme-preview-heading')
                            ?.focus(),
                    );
                },
            },
        );
    };

    /** Stops previewing: a change still waiting for its save belongs to the preview, and goes with it. */
    const stopPreview = (code: string) => {
        window.clearTimeout(timer.current);
        pending.current = {};
        router.delete(ThemesController.stopPreview.url({ code }), {
            preserveScroll: true,
            onSuccess: settled,
        });
    };

    /**
     * Activates the previewed theme: a change still waiting for its save is
     * saved first, as a section's Preview does, so it goes live with the rest.
     */
    const activateTheme = async (code: string) => {
        setPressed(null);
        setActivating(true);
        await save();
        router.post(
            ThemesController.activate.url({ code }),
            {},
            {
                preserveScroll: true,
                onSuccess: settled,
                onFinish: () => setActivating(false),
            },
        );
    };

    const finish = (action: 'publish' | 'discard') => {
        window.clearTimeout(timer.current);
        pending.current = {};

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEdits({});
                setErrors({});
                setSaveState('idle');
            },
        };

        if (action === 'publish') {
            router.post(ThemesController.publish.url(), {}, options);
        } else {
            router.delete(ThemesController.discard.url(), options);
        }
    };

    const unbound =
        page.preview !== null
            ? page.slots
                  .filter((slot) => themeValues[slot.key] === '')
                  .map((slot) => slot.label)
            : [];

    return {
        edits,
        errors,
        draft,
        saveState,
        pressed,
        activating,
        themeValues,
        designValues,
        errorOf,
        unbound,
        save,
        change,
        preview,
        settled,
        previewTheme,
        stopPreview,
        activateTheme,
        finish,
    };
}
