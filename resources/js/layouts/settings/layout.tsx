import { Link, useHttp, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { PropsWithChildren } from 'react';
import FindSettingsController from '@/actions/App/Http/Admin/Settings/FindSettingsController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { SettingsFound } from '@/types';

const NONE: SettingsFound = { screens: [], settings: [] };

/**
 * What Find a setting finds for what is typed, 150 ms after typing stops,
 * the previous request cancelled, with the words it answered for. Nothing
 * typed asks nothing.
 */
function useFindSettings(query: string): {
    answer: { term: string; found: SettingsFound };
} {
    const { submit, cancel } = useHttp();
    const [answer, setAnswer] = useState({ term: '', found: NONE });
    const term = query.trim();

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

    return { answer };
}

/**
 * The frame of every Settings screen: Find a setting at the top, the
 * Settings menu on the left under its headings, and the screen. While
 * something is typed, what it finds takes the place of both; the screen
 * is hidden, never unmounted, so what was typed on it is kept.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const page = usePage();
    const { settingsNav } = page.props;
    const { currentUrl } = useCurrentUrl();
    const [query, setQuery] = useState('');
    const { answer } = useFindSettings(query);
    const term = query.trim();
    const answered = answer.term === term;
    const count = answer.found.screens.length + answer.found.settings.length;
    const headings = [...new Set(settingsNav.map((item) => item.heading))];

    // A page is current on its own address and anything under it.
    const within = (href: string): boolean =>
        currentUrl === href || currentUrl.startsWith(`${href}/`);

    // A result lands on its field (#field-{key}): focused, and in view.
    useEffect(() => {
        const id = decodeURIComponent(window.location.hash.slice(1));

        if (!id.startsWith('field-')) {
            return;
        }

        const field = document.getElementById(id);

        field?.focus({ preventScroll: true });
        field?.scrollIntoView({ block: 'center' });
    }, [page.url]);

    const result = (key: string, href: string, label: string, line: string) => (
        <li key={key}>
            <Link
                href={href}
                onClick={() => setQuery('')}
                className="hover:bg-muted focus-visible:ring-ring grid gap-0.5 px-4 py-2 focus-visible:ring-2 focus-visible:outline-none"
            >
                <span className="text-[13px] font-medium">{label}</span>
                <span className="text-muted-foreground text-xs">{line}</span>
            </Link>
        </li>
    );

    return (
        <div className="space-y-4 p-6 max-md:px-4">
            <div
                role="search"
                aria-label="Settings search"
                className="relative max-w-sm"
            >
                <Search
                    aria-hidden="true"
                    className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <label htmlFor="find-setting" className="sr-only">
                    Find a setting
                </label>
                <Input
                    id="find-setting"
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Find a setting, e.g. time zone"
                    aria-controls="settings-found"
                    autoComplete="off"
                    className="pl-8"
                    data-test="find-setting"
                />
            </div>

            <div id="settings-found" aria-live="polite">
                {term !== '' && (
                    <section
                        aria-labelledby="settings-found-heading"
                        className="border-border max-w-3xl rounded-md border"
                    >
                        <div className="border-border flex items-center justify-between gap-3 border-b px-4 py-2">
                            <h2
                                id="settings-found-heading"
                                className="text-[13px] font-semibold"
                            >
                                {answered
                                    ? `${count === 1 ? '1 result' : `${count} results`} for “${term}”`
                                    : 'Searching…'}
                            </h2>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setQuery('')}
                            >
                                Clear
                            </Button>
                        </div>
                        {answered && count === 0 ? (
                            <div className="space-y-1 px-4 py-6">
                                <p className="text-[13px] font-semibold">
                                    No setting matches
                                </p>
                                <p className="text-muted-foreground text-[13px]">
                                    Try another word. The search reads every
                                    setting’s name and description.
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-border divide-y">
                                {answer.found.screens.map((found) =>
                                    result(
                                        `page.${found.key}`,
                                        found.href,
                                        found.label,
                                        'Settings page',
                                    ),
                                )}
                                {answer.found.settings.map((found) =>
                                    result(
                                        found.key,
                                        found.href,
                                        found.label,
                                        found.screen,
                                    ),
                                )}
                            </ul>
                        )}
                    </section>
                )}
            </div>

            <div
                hidden={term !== ''}
                className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[200px_minmax(0,1fr)]"
            >
                <nav
                    aria-label="Settings"
                    className="flex min-w-0 gap-0.5 overflow-x-auto max-lg:border-b max-lg:pb-1 lg:sticky lg:top-6 lg:grid lg:content-start lg:self-start"
                >
                    {/* One group per heading: Site, and Plugin settings once a plugin adds a group. */}
                    {headings.map((heading, index) => (
                        <div
                            key={heading}
                            role="group"
                            aria-labelledby={`settings-heading-${index}`}
                            className="flex gap-0.5 lg:grid"
                        >
                            <p
                                id={`settings-heading-${index}`}
                                data-test="settings-heading"
                                className="text-muted-foreground px-2 pt-3 pb-1 text-[11px] font-medium max-lg:hidden"
                            >
                                {heading}
                            </p>
                            {settingsNav
                                .filter((item) => item.heading === heading)
                                .map((item) => (
                                    <Link
                                        key={item.key}
                                        href={item.href}
                                        aria-current={
                                            within(item.href)
                                                ? 'page'
                                                : undefined
                                        }
                                        className="hover:bg-muted aria-[current=page]:bg-muted flex min-h-8 shrink-0 items-center rounded-md px-2 py-1 text-[13px] whitespace-nowrap aria-[current=page]:font-medium"
                                    >
                                        {item.label}
                                    </Link>
                                ))}
                        </div>
                    ))}
                </nav>

                <div className="min-w-0">{children}</div>
            </div>
        </div>
    );
}
