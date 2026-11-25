import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { LayoutDashboard } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import ContentListController from '@/actions/App/Http/Admin/Contents/ContentListController';
import { CustomizeBar } from '@/components/dashboard/customize-bar';
import { todayLine } from '@/components/dashboard/greeting';
import { GettingStarted } from '@/components/dashboard/getting-started';
import {
    moveBy,
    moveTo,
    sectionOf,
    shownIn,
    toPreferences,
    type Layout,
} from '@/components/dashboard/home-layout';
import { HomePanel } from '@/components/dashboard/home-panel';
import { PanelGrid } from '@/components/dashboard/panel-grid';
import { SiteFigures } from '@/components/dashboard/site-figures';
import { usePanelDrops } from '@/components/dashboard/use-panel-drag';
import { showToast } from '@/components/flash-toast';
import { DashboardPage } from '@/components/patterns/dashboard-page';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import { savePreferences } from '@/lib/preferences';
import { dashboard } from '@/routes';
import { index as mediaIndex } from '@/routes/media';
import type { DashboardPage as HomeProps, PanelSize } from '@/types';

const SIZE_WORDS: Record<PanelSize, string> = {
    small: 'Small',
    medium: 'Medium',
    large: 'Large',
    full: 'Full width',
};

/**
 * Home: greeting and actions, the site's figures as tiles, then Your work
 * and From plugins in a grid of four sizes. Customise saves each change at
 * once as the person's preferences.
 */
export default function Dashboard({
    panels,
    catalogue,
    layout: initialLayout,
    figures,
    mainType: _mainType,
    create: createTypes,
    gettingStarted,
    canQuickDraft,
    canUpload,
    searchEngines,
}: HomeProps) {
    const { auth, i18n } = usePage().props;
    const [customizing, setCustomizing] = useState(false);
    const [layout, setLayout] = useState<Layout>(initialLayout);
    const [announce, setAnnounce] = useState('');
    const customizeButton = useRef<HTMLButtonElement | null>(null);
    const saveChain = useRef(Promise.resolve(true));
    const layoutRef = useRef(layout);
    layoutRef.current = layout;

    useEffect(() => {
        setLayout(initialLayout);
    }, [initialLayout]);

    const owners = useMemo(
        () =>
            Object.fromEntries(
                catalogue.map((entry) => [entry.key, entry.owner]),
            ),
        [catalogue],
    );

    const declared = useMemo(
        () =>
            Object.fromEntries(
                catalogue.map((entry) => [entry.key, entry.size]),
            ) as Record<string, PanelSize>,
        [catalogue],
    );

    const panelsByKey = useMemo(
        () => Object.fromEntries(panels.map((panel) => [panel.key, panel])),
        [panels],
    );

    const greetingLine = (() => {
        const hour = new Date().getHours();
        const name = auth.user.name.trim();

        if (hour >= 5 && hour <= 11) {
            return __('Good morning, :name', { name });
        }

        if (hour >= 12 && hour <= 17) {
            return __('Good afternoon, :name', { name });
        }

        return __('Good evening, :name', { name });
    })();

    const createButtons = createTypes.map((type, index) => (
        <Form
            key={type.segment}
            {...ContentListController.store.form({ type: type.segment })}
        >
            {({ processing }) => (
                <Button
                    variant={index === 0 ? 'default' : 'neutral'}
                    disabled={processing}
                >
                    {__('+ :singular', { singular: type.singular })}
                </Button>
            )}
        </Form>
    ));
    const create = createButtons.length > 0 ? <>{createButtons}</> : null;

    const persist = (next: Layout) => {
        setLayout(next);
        saveChain.current = saveChain.current.then(async () => {
            const saved = await savePreferences(toPreferences(next, declared));

            if (!saved) {
                router.reload({ only: ['layout'] });
                showToast({
                    type: 'error',
                    message: __("Couldn't save your Home. Try again."),
                });

                return false;
            }

            return true;
        });

        return saveChain.current;
    };

    const focusControl = (key: string, control: string) => {
        queueMicrotask(() => {
            const node = document.querySelector(
                `[data-panel="${key}"] [data-control="${control}"]`,
            );

            if (node instanceof HTMLElement) {
                node.focus();
            }
        });
    };

    const sayMove = (key: string, next: Layout) => {
        const entry = catalogue.find((item) => item.key === key);
        const section = sectionOf(owners[key] ?? 'core');
        const shown = shownIn(next, owners, section);
        const position = shown.indexOf(key) + 1;

        setAnnounce(
            __(':name moved to position :position of :count.', {
                name: __(entry?.label ?? key),
                position,
                count: shown.length,
            }),
        );
    };

    const movePanel = (key: string, step: -1 | 1) => {
        const previous = layoutRef.current;
        const order = moveBy(previous, owners, key, step);

        if (order === previous.order) {
            focusControl(key, step === -1 ? 'move-earlier' : 'move-later');

            return;
        }

        const next = { ...previous, order };

        void persist(next).then((saved) => {
            if (saved) {
                sayMove(key, next);
            }

            focusControl(key, step === -1 ? 'move-earlier' : 'move-later');
        });
    };

    const dropPanel = (key: string, targetKey: string, after: boolean) => {
        const previous = layoutRef.current;
        const section = sectionOf(owners[key] ?? 'core');
        const shown = shownIn(previous, owners, section);
        const from = shown.indexOf(key);
        const targetIndex = shown.indexOf(targetKey);

        if (from === -1 || targetIndex === -1) {
            return;
        }

        let to = after ? targetIndex + 1 : targetIndex;

        if (from < to) {
            to -= 1;
        }

        const order = moveTo(previous, owners, key, to);

        if (order === previous.order) {
            return;
        }

        const next = { ...previous, order };

        void persist(next).then((saved) => {
            if (saved) {
                sayMove(key, next);
            }
        });
    };

    usePanelDrops(dropPanel, customizing);

    const resizePanel = (key: string, size: PanelSize) => {
        const previous = layoutRef.current;
        const next = {
            ...previous,
            sizes: { ...previous.sizes, [key]: size },
        };
        const entry = catalogue.find((item) => item.key === key);

        void persist(next).then((saved) => {
            if (saved) {
                setAnnounce(
                    __(':name is now :size.', {
                        name: __(entry?.label ?? key),
                        size: __(SIZE_WORDS[size]),
                    }),
                );
            }

            focusControl(key, 'size');
        });
    };

    const hidePanel = (key: string) => {
        const previous = layoutRef.current;
        const entry = catalogue.find((item) => item.key === key);
        const next = {
            ...previous,
            hidden: [...previous.hidden, key],
        };

        void persist(next).then((saved) => {
            if (!saved) {
                return;
            }

            showToast({
                type: 'success',
                message: __(':name hidden', { name: __(entry?.label ?? key) }),
                action: {
                    label: __('Undo'),
                    onClick: () => {
                        const current = layoutRef.current;
                        void persist({
                            ...current,
                            hidden: current.hidden.filter(
                                (item) => item !== key,
                            ),
                        });
                    },
                },
            });
        });
    };

    const addPanel = (key: string) => {
        const previous = layoutRef.current;
        const entry = catalogue.find((item) => item.key === key);
        const order = previous.order.includes(key)
            ? previous.order
            : [...previous.order, key];
        const unhidden: Layout = {
            ...previous,
            order,
            hidden: previous.hidden.filter((item) => item !== key),
        };
        const atEnd = moveTo(
            unhidden,
            owners,
            key,
            shownIn(unhidden, owners, sectionOf(owners[key])).length,
        );

        void persist({ ...unhidden, order: atEnd }).then((saved) => {
            if (saved) {
                showToast({
                    type: 'success',
                    message: __(':name added', {
                        name: __(entry?.label ?? key),
                    }),
                });
            }
        });
    };

    const resetLayout = () => {
        const next: Layout = {
            order: catalogue.map((entry) => entry.key),
            hidden: [],
            sizes: { ...declared },
        };

        setLayout(next);
        saveChain.current = saveChain.current.then(async () => {
            const saved = await savePreferences({
                'dashboard.order': [],
                'dashboard.hidden': [],
                'dashboard.sizes': [],
            });

            if (!saved) {
                router.reload({ only: ['layout'] });
                showToast({
                    type: 'error',
                    message: __("Couldn't save your Home. Try again."),
                });

                return false;
            }

            showToast({
                type: 'success',
                message: __('Home is back to the site default'),
            });

            return true;
        });
    };

    const actions = !customizing && (
        <>
            {create}
            {canUpload && (
                <Button variant="neutral" asChild>
                    <Link href={mediaIndex()}>{__('Upload media')}</Link>
                </Button>
            )}
            <Button
                ref={customizeButton}
                variant="ghost"
                aria-pressed={customizing}
                data-test="home-customize"
                onClick={() => setCustomizing(true)}
            >
                <LayoutDashboard aria-hidden="true" />
                {__('Customise')}
            </Button>
        </>
    );

    const shown = (section: 'core' | 'plugins') =>
        layout.order.filter((key) => {
            const entry = catalogue.find((item) => item.key === key);

            if (
                entry === undefined ||
                sectionOf(entry.owner) !== section ||
                layout.hidden.includes(key)
            ) {
                return false;
            }

            if (customizing) {
                return true;
            }

            if (entry.kind === 'quick-draft') {
                return canQuickDraft;
            }

            if (entry.kind === 'site-status') {
                return true;
            }

            const panel = panelsByKey[key];

            return (
                panel !== undefined && (panel.rows.length > 0 || panel.failed)
            );
        });

    const coreKeys = shown('core');
    const pluginKeys = shown('plugins');
    const hasPluginCatalogue = catalogue.some(
        (entry) => sectionOf(entry.owner) === 'plugins',
    );
    // Outside Customise, empty plugin panels stay out of the way; Customise
    // still shows the section so empty ones can be arranged.
    const showFromPlugins =
        pluginKeys.length > 0 || (customizing && hasPluginCatalogue);
    const nothingWaiting =
        !customizing &&
        coreKeys.every((key) => {
            const entry = catalogue.find((item) => item.key === key);

            return entry?.kind !== 'rows';
        });

    const renderPanel = (key: string) => {
        const entry = catalogue.find((item) => item.key === key)!;
        const section = sectionOf(entry.owner);
        const keys = section === 'core' ? coreKeys : pluginKeys;
        const index = keys.indexOf(key);

        return (
            <HomePanel
                key={key}
                entry={entry}
                panel={panelsByKey[key] ?? null}
                size={layout.sizes[key] ?? entry.size}
                customizing={customizing}
                section={section}
                canMoveEarlier={index > 0}
                canMoveLater={index >= 0 && index < keys.length - 1}
                onMoveEarlier={() => movePanel(key, -1)}
                onMoveLater={() => movePanel(key, 1)}
                onResize={(size) => resizePanel(key, size)}
                onHide={() => hidePanel(key)}
            />
        );
    };

    return (
        <>
            <Head title={__('Home')} />
            <DashboardPage
                title={greetingLine}
                description={
                    <>
                        {todayLine(i18n.locale)}.
                        {__(
                            " Your work first, then the site's figures and what plugins report.",
                        )}
                    </>
                }
                actions={actions}
            >
                <div
                    role="status"
                    className="sr-only"
                    data-test="home-announce"
                >
                    {announce}
                </div>

                {customizing && (
                    <CustomizeBar
                        catalogue={catalogue}
                        hidden={layout.hidden}
                        onAdd={addPanel}
                        onReset={resetLayout}
                        onDone={() => {
                            setCustomizing(false);
                            queueMicrotask(() =>
                                customizeButton.current?.focus(),
                            );
                        }}
                    />
                )}

                {searchEngines !== null && (
                    <Notice
                        tone="warn"
                        data-test="home-search-engines"
                        className="mb-6"
                    >
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <p className="min-w-0 flex-1 basis-60">
                                <span className="font-medium">
                                    {searchEngines.message}
                                </span>{' '}
                                {searchEngines.detail}
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={searchEngines.actionUrl}>
                                    {searchEngines.actionLabel}
                                </Link>
                            </Button>
                        </div>
                    </Notice>
                )}

                {gettingStarted !== null && (
                    <div className="mb-6">
                        <GettingStarted steps={gettingStarted.steps} />
                    </div>
                )}

                <div className="mb-8">
                    <SiteFigures figures={figures} />
                </div>

                <section
                    aria-labelledby="home-your-work"
                    data-test="home-your-work"
                    className="mb-8"
                >
                    <h2
                        id="home-your-work"
                        className="text-lead text-ink mb-4 font-semibold"
                    >
                        {__('Your work')}
                    </h2>
                    {nothingWaiting && (
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <p className="text-small text-ink-2">
                                {__('Nothing needs you right now.')}
                            </p>
                            {create}
                        </div>
                    )}
                    {coreKeys.length > 0 && (
                        <PanelGrid>{coreKeys.map(renderPanel)}</PanelGrid>
                    )}
                </section>

                {showFromPlugins && (
                    <section
                        aria-labelledby="home-from-plugins"
                        data-test="home-from-plugins"
                        className="mb-8"
                    >
                        <h2
                            id="home-from-plugins"
                            className="text-lead text-ink mb-1 font-semibold"
                        >
                            {__('From plugins')}
                        </h2>
                        <p className="text-small text-ink-2 mb-4">
                            {__(
                                'Panels that plugins add, each naming its plugin. Hide or move them in Customise.',
                            )}
                        </p>
                        {pluginKeys.length > 0 && (
                            <PanelGrid>{pluginKeys.map(renderPanel)}</PanelGrid>
                        )}
                    </section>
                )}
            </DashboardPage>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Home',
            href: dashboard(),
        },
    ],
};
