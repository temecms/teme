import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import ThemesController from '@/actions/App/Http/Admin/ThemesController';
import { send } from '@/components/editor/editor-http';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/list/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { SelectField } from '@/components/ui/select-field';
import { ThemeList } from '@/components/themes/theme-list';
import { ThemeRegions } from '@/components/themes/theme-regions';
import { ThemePreviewPanel } from '@/components/themes/theme-preview-panel';
import { index as navigationIndex } from '@/routes/navigation';
import { show as showSettings } from '@/routes/settings';
import type { DesignField, DesignFieldset, DesignPage } from '@/types';

/** The settings sections, in the order the screen shows them (I-442). */
const SECTIONS: { key: DesignFieldset; label: string }[] = [
    { key: 'site', label: 'Site' },
    { key: 'latest', label: 'Latest posts' },
    { key: 'content', label: 'Content' },
    { key: 'archive', label: 'Archive' },
];

/** How long a change waits for the next before the draft is saved. */
const SAVE_AFTER_MS = 800;

type Values = Record<string, Record<string, unknown>>;
type SaveState = 'idle' | 'saving' | 'saved' | 'failed';

/** Whether every condition holds: the other setting has this value, or one of these. */
function visible(field: DesignField, values: Record<string, unknown>): boolean {
    return Object.entries(field.visibleWhen).every(([key, wanted]) =>
        Array.isArray(wanted)
            ? wanted.includes(values[key])
            : values[key] === wanted,
    );
}

/** A stored value as a control shows it: text, or nothing. */
function text(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

function when(iso: string): string {
    return new Date(iso).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function FieldInput({
    field,
    value,
    error,
    onChange,
}: {
    field: DesignField;
    value: unknown;
    error?: string;
    onChange: (value: unknown) => void;
}) {
    const id = `design-${field.key}`;
    const hint = field.description !== '' ? `${id}-hint` : undefined;

    if (field.type === 'boolean') {
        return (
            <div className="grid gap-1">
                <label className="flex items-center gap-2 text-[13px]">
                    <Checkbox
                        id={id}
                        checked={value === true}
                        aria-describedby={hint}
                        aria-invalid={error !== undefined}
                        onCheckedChange={(checked) =>
                            onChange(checked === true)
                        }
                    />
                    {field.label}
                </label>
                {hint && (
                    <p id={hint} className="text-ink-2 text-xs">
                        {field.description}
                    </p>
                )}
                <InputError message={error} />
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{field.label}</Label>
            {field.type === 'select' ? (
                <SelectField
                    id={id}
                    aria-describedby={hint}
                    value={text(value)}
                    options={Object.entries(field.options).map(
                        ([option, label]) => ({ value: option, label }),
                    )}
                    onValueChange={onChange}
                />
            ) : (
                <Input
                    id={id}
                    aria-describedby={hint}
                    aria-invalid={error !== undefined}
                    value={text(value)}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
            {hint && (
                <p id={hint} className="text-ink-2 text-xs">
                    {field.description}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

/**
 * Site › Themes: the installed themes, a theme's preview, the regions each
 * places, and the chosen theme's settings and menu places. Every change
 * is saved as a draft as it is made, only the keys changed; nothing reaches
 * readers until Publish, and each section previews its page on the real site.
 */
export default function Design(page: DesignPage) {
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

                setDraft((answer.body.draft ?? null) as DesignPage['draft']);
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
    const active = page.themes.find((theme) => theme.state === 'active');
    const design = { ...page.values.design, ...edits.design };
    // The theme the draft or the live value names: the previewed one, else the active one.
    const chosen = page.themes.find((theme) => theme.code === design.theme);
    const previewError = pageErrors['groups.design.theme'];

    return (
        <>
            <Head title="Themes" />

            <div className="space-y-6 p-6 pb-24">
                <PageHeader
                    title="Themes"
                    description="How the public site looks. A theme draws the site; it adds no features of its own."
                />

                <p
                    className="text-ink-2 text-[13px]"
                    role="status"
                    aria-live="polite"
                >
                    {saveState === 'saving' && 'Saving the draft…'}
                    {saveState === 'saved' && 'Saved as a draft.'}
                    {saveState === 'failed' &&
                        'The last change was not saved. Check the fields marked below.'}
                </p>

                <div className="flex flex-wrap items-center gap-3">
                    <p className="text-ink-2 text-[13px]">
                        The site’s name, tagline and brand colour stay when you
                        change theme.
                    </p>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={showSettings({ group: 'general' })}>
                            Open Settings › General
                        </Link>
                    </Button>
                </div>

                {page.preview !== null && (
                    <ThemePreviewPanel
                        preview={page.preview}
                        activeName={active?.name ?? null}
                        unbound={unbound}
                        onOpenSite={() => void preview('site')}
                        onStop={() =>
                            page.preview !== null &&
                            stopPreview(page.preview.code)
                        }
                        onActivate={() =>
                            page.preview !== null &&
                            void activateTheme(page.preview.code)
                        }
                        busy={activating}
                        colourError={
                            pressed === null ? previewError : undefined
                        }
                        themeError={pageErrors.theme}
                    >
                        {page.previewRegions !== null && (
                            <ThemeRegions
                                regions={page.previewRegions}
                                level={3}
                                intro={
                                    active === undefined
                                        ? 'Regions are filled in Content › Regions.'
                                        : `Regions are filled in Content › Regions. What ${active.name} places stays as it is, listed under From other themes, and comes back with ${active.name}.`
                                }
                                unplacedLine={`What ${page.preview.name} would leave unplaced: kept as it is, and placed again when its theme is in use.`}
                                canEdit={false}
                                idPrefix="theme-preview-regions"
                            />
                        )}
                    </ThemePreviewPanel>
                )}

                <ThemeList
                    themes={page.themes}
                    settingsOf={page.preview?.code ?? active?.code ?? null}
                    error={
                        previewError !== undefined && pressed !== null
                            ? { code: pressed, message: previewError }
                            : null
                    }
                    onPreview={(code) => void previewTheme(code)}
                />

                <ThemeRegions
                    regions={page.regions}
                    level={2}
                    unplacedLine="Kept from themes not in use, as they were. Each is placed again when its theme is in use."
                    canEdit={page.canEditRegions}
                    idPrefix="theme-regions"
                />
                {chosen !== undefined && chosen.usable && (
                    <section
                        id="theme-settings"
                        aria-labelledby="theme-settings-heading"
                        className="max-w-3xl scroll-mt-6 space-y-4"
                    >
                        <div className="grid gap-1">
                            <h2
                                id="theme-settings-heading"
                                className="text-[15px] font-semibold"
                            >
                                {chosen.name} settings
                            </h2>
                            {page.preview !== null && (
                                <p className="text-ink-2 text-[13px]">
                                    Visitors see these once you activate{' '}
                                    {page.preview.name}.
                                </p>
                            )}
                        </div>
                        {themeGroup === null && (
                            <p className="text-ink-2 text-[13px]">
                                {chosen.name} has no settings or menu places of
                                its own.
                            </p>
                        )}
                        {themeGroup !== null &&
                            SECTIONS.filter(
                                (section) =>
                                    page.fieldsets[section.key].length > 0,
                            ).map((section) => (
                                <Panel
                                    key={section.key}
                                    aria-labelledby={`design-${section.key}`}
                                >
                                    <PanelHeader id={`design-${section.key}`}>
                                        {section.label}
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            className="ml-auto"
                                            onClick={() =>
                                                void preview(section.key)
                                            }
                                        >
                                            Preview
                                            <span className="sr-only">
                                                {' '}
                                                {section.label}
                                            </span>
                                        </Button>
                                    </PanelHeader>
                                    <PanelBody>
                                        {page.fieldsets[section.key]
                                            .filter((field) =>
                                                visible(field, themeValues),
                                            )
                                            .map((field) => (
                                                <FieldInput
                                                    key={field.key}
                                                    field={field}
                                                    value={
                                                        themeValues[field.key]
                                                    }
                                                    error={errorOf(
                                                        themeGroup,
                                                        field.key,
                                                    )}
                                                    onChange={(value) =>
                                                        change(
                                                            themeGroup,
                                                            field.key,
                                                            value,
                                                        )
                                                    }
                                                />
                                            ))}
                                    </PanelBody>
                                </Panel>
                            ))}

                        {themeGroup !== null && page.slots.length > 0 && (
                            <Panel aria-labelledby="design-menus">
                                <PanelHeader id="design-menus">
                                    Menus
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="ml-auto"
                                        asChild
                                    >
                                        <Link href={navigationIndex()}>
                                            Edit menus
                                        </Link>
                                    </Button>
                                </PanelHeader>
                                <PanelBody>
                                    <p className="text-ink-2 text-[13px]">
                                        A menu you choose here shows once you
                                        publish, and replaces whatever Content ›
                                        Navigation sets for that place. Places
                                        you don’t change here keep Navigation’s
                                        menu.
                                    </p>
                                    {page.slots.map((slot) => (
                                        <div
                                            key={slot.key}
                                            className="grid gap-2"
                                        >
                                            <Label
                                                htmlFor={`design-${slot.key}`}
                                            >
                                                {slot.label}
                                            </Label>
                                            <SelectField
                                                id={`design-${slot.key}`}
                                                value={text(
                                                    themeValues[slot.key],
                                                )}
                                                emptyLabel="None"
                                                options={page.menus.map(
                                                    (menu) => ({
                                                        value: menu.id,
                                                        label: menu.name,
                                                    }),
                                                )}
                                                onValueChange={(value) =>
                                                    change(
                                                        themeGroup,
                                                        slot.key,
                                                        value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={errorOf(
                                                    themeGroup,
                                                    slot.key,
                                                )}
                                            />
                                        </div>
                                    ))}
                                </PanelBody>
                            </Panel>
                        )}
                    </section>
                )}
            </div>

            {draft !== null && page.preview === null && (
                <div
                    className="border-line bg-page fixed inset-x-0 bottom-0 z-10 flex flex-wrap items-center gap-3 border-t px-6 py-3 text-[13px]"
                    role="region"
                    aria-label="Unpublished design changes"
                >
                    <span>
                        Unpublished design changes
                        {draft.by !== null && ` · by ${draft.by}`},{' '}
                        {when(draft.at)}
                    </span>
                    <InputError role="alert" message={pageErrors.theme} />
                    <span className="ml-auto flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => finish('discard')}
                        >
                            Discard
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => finish('publish')}
                        >
                            Publish
                        </Button>
                    </span>
                </div>
            )}
        </>
    );
}
