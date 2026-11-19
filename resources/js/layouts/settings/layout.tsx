import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, PlugIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, PropsWithChildren } from 'react';
import { Page } from '@/components/patterns/page';
import { SettingsFrameContext } from '@/components/patterns/settings-frame';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { __ } from '@/lib/i18n';
import { FindSetting } from './find-setting';
import { SectionLinks } from './section-links';

/** The side column beside the page from 1024px: 200 wide, the page taking the rest. */
const COLUMNS = {
    '--settings-cols': '200px minmax(0, 1fr)',
} as CSSProperties;

const LINK =
    'text-body text-ink-2 hover:bg-surface-3 hover:text-ink aria-[current=page]:bg-accent-soft aria-[current=page]:text-on-accent-soft focus-visible:outline-ring flex min-h-(--ctl-h) shrink-0 items-center gap-2 rounded-md px-3 font-medium focus-visible:outline-2 focus-visible:-outline-offset-2';

/**
 * The frame of every Settings page (docs/architecture/design-system.md,
 * 4.42): the page's title and line across the full width; under them a
 * 200px side column holding Find a setting and the Settings pages, the
 * current one opened to its sections, plugins' pages under "From plugins"
 * with the plug mark; and the page's form beside it.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const page = usePage();
    const { settingsNav } = page.props;
    const { currentUrl } = useCurrentUrl();
    const [groupsOpen, setGroupsOpen] = useState(false);
    const [head, setHead] = useState<HTMLElement | null>(null);
    const [saved, setSaved] = useState(false);
    const main = useRef<HTMLDivElement>(null);
    const path = currentUrl.split(/[?#]/)[0];

    // "Saved just now" belongs to the page it was said on.
    useEffect(() => setSaved(false), [path]);

    // A page is current on its own address and anything under it.
    const within = (href: string): boolean =>
        path === href || path.startsWith(`${href}/`);

    const current = settingsNav.find((item) => within(item.href));
    const core = settingsNav.filter((item) => !item.plugin);
    const plugins = settingsNav.filter((item) => item.plugin);
    const frame = useMemo(() => ({ head, saved, setSaved }), [head, saved]);

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
            unmark();
            field?.removeEventListener('input', unmark);
            field?.removeEventListener('blur', unmark);
        };
    }, [page.url]);

    const entry = (item: (typeof settingsNav)[number]) => (
        <div key={item.key} className="contents">
            <Link
                href={item.href}
                onClick={() => setGroupsOpen(false)}
                aria-current={within(item.href) ? 'page' : undefined}
                data-test="settings-group"
                className={LINK}
            >
                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                {item.plugin && (
                    <span
                        title={__('A plugin’s settings')}
                        className="text-ink-3"
                    >
                        <PlugIcon aria-hidden="true" className="size-3.5" />
                        <span className="sr-only">{__('Plugin')}</span>
                    </span>
                )}
            </Link>
            {within(item.href) && <SectionLinks main={main} />}
        </div>
    );

    return (
        <SettingsFrameContext.Provider value={frame}>
            <Page>
                <div ref={setHead} data-test="settings-head" />
                <div
                    className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-(--settings-cols) lg:gap-8"
                    style={COLUMNS}
                >
                    <aside className="grid min-w-0 content-start gap-4 lg:sticky lg:top-4 lg:self-start">
                        <FindSetting />
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
                                <span className="truncate">
                                    {current?.label}
                                </span>
                            </span>
                            <ChevronDown
                                aria-hidden="true"
                                className={`size-4 transition-transform duration-(--dur-2) ease-(--ease) ${groupsOpen ? 'rotate-180' : ''}`}
                            />
                        </Button>
                        <nav
                            id="settings-groups"
                            aria-label={__('Settings')}
                            className={`max-lg:bg-surface max-lg:shadow-raised grid min-w-0 content-start gap-0.5 max-lg:rounded-lg max-lg:p-2 ${groupsOpen ? '' : 'max-lg:hidden'}`}
                        >
                            {core.map(entry)}
                            {plugins.length > 0 && (
                                <div
                                    role="group"
                                    aria-labelledby="settings-from-plugins"
                                    className="grid gap-0.5"
                                >
                                    <p
                                        id="settings-from-plugins"
                                        data-test="settings-heading"
                                        className="text-caption text-ink-3 px-3 pt-3 pb-1 font-semibold uppercase"
                                    >
                                        {__('From plugins')}
                                    </p>
                                    {plugins.map(entry)}
                                </div>
                            )}
                        </nav>
                    </aside>

                    <div ref={main} className="min-w-0">
                        {children}
                    </div>
                </div>
            </Page>
        </SettingsFrameContext.Provider>
    );
}
