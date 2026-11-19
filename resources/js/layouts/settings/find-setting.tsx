import { router, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import FindSettingsController from '@/actions/App/Http/Admin/Settings/FindSettingsController';
import { SearchSuggest } from '@/components/ui/search-suggest';
import { __ } from '@/lib/i18n';
import type { SettingsFound } from '@/types';

const NONE: SettingsFound = { screens: [], settings: [] };

/** The most results the list shows; the rest are counted under it. */
const SHOWN = 8;

type Result = { key: string; label: string; line: string; href: string };

/**
 * What Find a setting finds for what is typed, 150 ms after typing stops,
 * the previous request cancelled, with the words it answered for. Nothing
 * typed asks nothing.
 */
function useFindSettings(term: string): { term: string; found: SettingsFound } {
    const { submit, cancel } = useHttp();
    const [answer, setAnswer] = useState({ term: '', found: NONE });

    useEffect(() => {
        cancel();

        if (term === '') {
            setAnswer({ term: '', found: NONE });

            return;
        }

        const timer = window.setTimeout(() => {
            void (
                submit(
                    FindSettingsController({ query: { q: term } }),
                ) as Promise<SettingsFound>
            )
                .then((found) => setAnswer({ term, found }))
                .catch(() => {
                    // Cancelled by the next keystroke, or the connection
                    // failed: the next word typed asks again.
                });
        }, 150);

        return () => window.clearTimeout(timer);
    }, [term, submit, cancel]);

    return answer;
}

/**
 * "Find a setting" (docs/architecture/design-system.md, 4.42), at the top of
 * the Settings frame's side column: what is typed is looked for in every
 * Settings page the person may change, and the first eight results open as
 * a list under the box, the typed words marked. ↑↓ move, Enter opens,
 * Escape closes, and "/" anywhere on the page puts the cursor in the box.
 */
export function FindSetting() {
    const [query, setQuery] = useState('');
    const input = useRef<HTMLInputElement>(null);
    const term = query.trim();
    const answer = useFindSettings(term);
    const answered = term !== '' && answer.term === term;

    const results: Result[] = [
        ...answer.found.screens.map((found) => ({
            key: `page.${found.key}`,
            label: found.label,
            line: __('Settings page'),
            href: found.href,
        })),
        ...answer.found.settings.map((found) => ({
            key: found.key,
            label: found.label,
            line: found.screen,
            href: found.href,
        })),
    ];

    // "/" focuses the box, unless someone is typing or a dialog is open.
    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            const active = document.activeElement;

            if (
                event.key !== '/' ||
                event.ctrlKey ||
                event.metaKey ||
                event.altKey ||
                (active instanceof HTMLElement &&
                    (active.isContentEditable ||
                        ['INPUT', 'TEXTAREA', 'SELECT'].includes(
                            active.tagName,
                        ))) ||
                document.querySelector('[role="dialog"]') !== null
            ) {
                return;
            }

            event.preventDefault();
            input.current?.focus();
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const status = !answered
        ? ''
        : results.length === 0
          ? __('No settings found')
          : results.length === 1
            ? __('1 setting found')
            : __(':count settings found', { count: results.length });

    return (
        <SearchSuggest
            id="find-setting"
            inputRef={input}
            value={query}
            onValueChange={setQuery}
            results={results.slice(0, SHOWN)}
            answered={answered}
            total={results.length}
            onChoose={(result) => {
                setQuery('');
                router.visit(
                    results.find((each) => each.key === result.key)?.href ?? '',
                );
            }}
            label={__('Find a setting')}
            listLabel={__('Settings found')}
            emptyText={__('No setting matches “:term”. Try fewer words.', {
                term,
            })}
            moreText={(count) =>
                __(':count more: type more words to narrow them.', { count })
            }
            status={status}
            data-test="find-setting"
        />
    );
}
