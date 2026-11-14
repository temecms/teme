import { Link, useHttp, usePage } from '@inertiajs/react';
import { ChevronDown, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { CSSProperties, PropsWithChildren } from 'react';
import FindSettingsController from '@/actions/App/Http/Admin/Settings/FindSettingsController';
import { Page } from '@/components/patterns/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { __ } from '@/lib/i18n';
import type { SettingsFound } from '@/types';

const NONE: SettingsFound = { screens: [], settings: [] };

/** The side list beside the page from 1024px: up to 200 wide, the page taking the rest. */
const COLUMNS = {
    '--settings-cols': 'minmax(0, 200px) minmax(0, 1fr)',
} as CSSProperties;

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
    const [groupsOpen, setGroupsOpen] = useState(false);
    const { answer } = useFindSettings(query);
    const term = query.trim();
    const answered = answer.term === term;
    const count = answer.found.screens.length + answer.found.settings.length;
    const headings = [...new Set(settingsNav.map((item) => item.heading))];

    // A page is current on its own address and anything under it.
    const within = (href: string): boolean =>
        currentUrl === href || currentUrl.startsWith(`${href}/`);

    const current = settingsNav.find((item) => within(item.href));

    // A result lands on its field (#field-{key}): focused, in view, and
    // marked until the person types in it or leaves it.
    useEffect(() => {
        const id = decodeURIComponent(window.location.hash.slice(1));

        if (!id.startsWith('field-')) {
            return;
        }

        const field = document.getElementById(id);
        let wrapper = field?.parentElement ?? null;

        while (wrapper !== null && wrapper.dataset.slot !== 'settings-field') {
            wrapper = wrapper.parentElement;
        }

        const unmark = () => wrapper?.removeAttribute('data-found');

        wrapper?.setAttribute('data-found', '');
        field?.focus({ preventScroll: true });
        field?.scrollIntoView({ block: 'center' });
        field?.addEventListener('input', unmark, { once: true });
        field?.addEventListener('blur', unmark, { once: true });

        return () => {
            field?.removeEventListener('input', unmark);
            field?.removeEventListener('blur', unmark);
        };
    }, [page.url]);

    const result = (key: string, href: string, label: string, line: string) => (
        <li key={key}>
            <Link
                href={href}
                onClick={() => setQuery('')}
                className="hover:bg-surface-2 focus-visible:outline-ring grid gap-0.5 px-4 py-2 focus-visible:outline-2 focus-visible:-outline-offset-2"
            >
                <span className="text-body font-medium">{label}</span>
                <span className="text-small text-ink-2">{line}</span>
            </Link>
        </li>
    );

    return (
        <Page className="grid gap-4">
            <div
                role="search"
                aria-label="Settings search"
                className="relative max-w-sm"
            >
                <Search
                    aria-hidden="true"
                    className="text-ink-3 pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
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
                    className="ps-10"
                    data-test="find-setting"
                />
            </div>

            <div id="settings-found" aria-live="polite">
                {term !== '' && (
                    <section
                        aria-labelledby="settings-found-heading"
                        className="bg-surface shadow-raised max-w-(--form-max) overflow-hidden rounded-lg"
                    >
                        <div className="border-line flex items-center justify-between gap-3 border-b px-4 py-2">
                            <h2
                                id="settings-found-heading"
                                className="text-body font-semibold"
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
                            <div className="grid gap-1 px-4 py-6">
                                <p className="text-body font-semibold">
                                    No setting matches
                                </p>
                                <p className="text-body text-ink-2">
                                    Try another word. The search reads every
                                    setting’s name and description.
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-line divide-y">
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
                className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-(--settings-cols)"
                style={COLUMNS}
            >
                <div className="grid min-w-0 content-start gap-2 lg:sticky lg:top-6 lg:self-start">
                    {/* Under 1024px the list opens from one button, in place. */}
                    <Button
                        type="button"
                        variant="neutral"
                        aria-expanded={groupsOpen}
                        aria-controls="settings-groups"
                        data-test="settings-groups-button"
                        onClick={() => setGroupsOpen((open) => !open)}
                        className="bg-surface shadow-raised w-full justify-between lg:hidden"
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <span className="text-ink-3">
                                {__('Settings groups')}
                            </span>
                            <span className="truncate">{current?.label}</span>
                        </span>
                        <ChevronDown
                            aria-hidden="true"
                            className={`size-4 transition-transform duration-(--dur-2) ease-(--ease) ${groupsOpen ? 'rotate-180' : ''}`}
                        />
                    </Button>
                    <nav
                        id="settings-groups"
                        aria-label="Settings"
                        className={`max-lg:bg-surface max-lg:shadow-raised grid min-w-0 content-start gap-1 max-lg:rounded-lg max-lg:p-2 ${groupsOpen ? '' : 'max-lg:hidden'}`}
                    >
                        {/* One group per heading: Site, and Plugin settings once a plugin adds a group. */}
                        {headings.map((heading, index) => (
                            <div
                                key={heading}
                                role="group"
                                aria-labelledby={`settings-heading-${index}`}
                                className="grid gap-1"
                            >
                                <p
                                    id={`settings-heading-${index}`}
                                    data-test="settings-heading"
                                    className="text-caption text-ink-3 px-3 pt-3 pb-1 font-semibold uppercase"
                                >
                                    {heading}
                                </p>
                                {settingsNav
                                    .filter((item) => item.heading === heading)
                                    .map((item) => (
                                        <Link
                                            key={item.key}
                                            href={item.href}
                                            onClick={() => setGroupsOpen(false)}
                                            aria-current={
                                                within(item.href)
                                                    ? 'page'
                                                    : undefined
                                            }
                                            className="text-small text-ink-2 hover:bg-surface-3 hover:text-ink aria-[current=page]:bg-accent-soft aria-[current=page]:text-on-accent-soft focus-visible:outline-ring flex min-h-(--ctl-h-sm) shrink-0 items-center rounded-md px-3 font-medium focus-visible:outline-2 focus-visible:-outline-offset-2"
                                        >
                                            {item.label}
                                        </Link>
                                    ))}
                            </div>
                        ))}
                    </nav>
                </div>

                <div className="min-w-0">{children}</div>
            </div>
        </Page>
    );
}
