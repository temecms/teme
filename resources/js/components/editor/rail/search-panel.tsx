import { Link, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    CircleAlert,
    LayoutGrid,
    MessageSquare,
    Monitor,
    Plug,
    SlidersHorizontal,
    Smartphone,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { flushSync } from 'react-dom';
import { BooleanControl } from '@/components/editor/fields/boolean-control';
import { TextControl } from '@/components/editor/fields/text-control';
import { TextareaControl } from '@/components/editor/fields/textarea-control';
import { AdvancedFold } from '@/components/editor/rail/advanced-fold';
import { CanonicalChoice } from '@/components/editor/rail/canonical-choice';
import type { ChosenItem } from '@/components/editor/rail/canonical-choice';
import { MetadataProposal } from '@/components/editor/rail/metadata-proposal';
import {
    searchCheckWords,
    searchToCheck,
} from '@/components/editor/rail/search-box';
import {
    SearchResult,
    SocialCard,
} from '@/components/editor/rail/search-previews';
import type { ResultSize } from '@/components/editor/rail/search-previews';
import { SocialImage } from '@/components/editor/rail/social-image';
import {
    SuggestMetadataButtons,
    SuggestMetadataNote,
} from '@/components/editor/rail/suggest-metadata';
import {
    METADATA_FIELDS,
    useMetadata,
} from '@/components/editor/rail/use-metadata';
import type { MetadataBinding } from '@/components/editor/rail/use-metadata';
import { PluginChecks } from '@/components/editor/rail/plugin-checks';
import { BoxRow } from '@/components/editor/search-social/box-row';
import { PluginTab } from '@/components/editor/search-social/plugin-tab';
import { orderTabs } from '@/components/editor/search-social/tab-order';
import type { CheckId } from '@/components/editor/rail/checks';
import type { SearchPreview } from '@/components/editor/use-search-preview';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { __, __n } from '@/lib/i18n';
import type {
    CardMedia,
    EditorField,
    EditorSeo,
    PluginCheckGroup,
    PluginCheckResult,
    PluginSearchTab,
} from '@/types/editor';
import { quotedTitle } from '@/lib/titles';

/** A text field's value, or nothing. */
function textOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/** A text area of the box: its lines fixed, so an empty one still reads as several lines. */
const textareaClass =
    'border-edge bg-surface focus-visible:ring-ring focus-visible:ring-offset-page text-lead placeholder:text-ink-3 w-full rounded-md border px-3 py-2 leading-normal outline-none focus-visible:ring-2 focus-visible:ring-offset-1';

/**
 * A field's length in the box's left column: one short line over a bar, the
 * bar in the accent while the text fits and in the warning colour once a
 * result may cut it.
 */
function Length({
    id,
    testId,
    label,
    length,
    guide,
    over,
    from,
}: {
    id: string;
    testId: string;
    label: string;
    /** Its length in characters. */
    length: number;
    /** About how many characters a result shows. */
    guide: number;
    /** Whether a result may cut it, by its width. */
    over: boolean;
    /** Where the text comes from while the field is empty, or null once typed. */
    from: string | null;
}) {
    const said =
        length === 0
            ? __('Empty')
            : [
                  from === null
                      ? __n(':count character|:count characters', length)
                      : __n(
                            ':count character, from :from|:count characters, from :from',
                            length,
                            { from },
                        ),
                  over
                      ? __('may be cut off')
                      : __('about :guide show', { guide }),
              ].join(' · ');

    return (
        <div className="grid gap-2">
            <p
                id={id}
                className={`text-small tabular-nums ${over ? 'text-warn-ink' : 'text-ink-2'}`}
            >
                {said}
            </p>
            <Progress
                data-test={testId}
                aria-hidden
                label={label}
                value={Math.min(length, guide)}
                max={guide}
                tone={over ? 'warn' : 'accent'}
                className="h-1"
            />
        </div>
    );
}

/** A tab's icon: a warning on Search while a search check warns, a plug on a plugin's tab. */
function TabIcon({
    tab,
    plugin,
    warns,
}: {
    tab: string;
    plugin: boolean;
    warns: boolean;
}) {
    const icon = 'size-4 shrink-0';

    if (plugin) {
        return (
            <Plug
                aria-hidden
                data-icon="plugin"
                className={`${icon} text-ink-3`}
            />
        );
    }

    switch (tab) {
        case 'search':
            return warns ? (
                <TriangleAlert
                    aria-hidden
                    data-icon="warning"
                    className={`${icon} text-warn`}
                />
            ) : (
                <CheckCircle2
                    aria-hidden
                    data-icon="passed"
                    className={`${icon} text-ok`}
                />
            );
        case 'social':
            return (
                <MessageSquare
                    aria-hidden
                    data-icon="social"
                    className={`${icon} text-ink-3`}
                />
            );
        case 'schema':
            return (
                <LayoutGrid
                    aria-hidden
                    data-icon="schema"
                    className={`${icon} text-ink-3`}
                />
            );
        default:
            return (
                <SlidersHorizontal
                    aria-hidden
                    data-icon="advanced"
                    className={`${icon} text-ink-3`}
                />
            );
    }
}

/** Each tab's name: core's, or a plugin's own label. */
function tabName(tab: string, plugins: PluginSearchTab[]): string {
    const plugin = plugins.find((each) => each.key === tab);

    if (plugin !== undefined) {
        return plugin.label;
    }

    switch (tab) {
        case 'social':
            return __('Social');
        case 'schema':
            return __('Schema');
        case 'advanced':
            return __('Advanced');
        default:
            return __('Search');
    }
}

/** The tab each AI proposal waits on, under its field. */
const PROPOSAL_TABS: Record<string, string> = {
    seo_title: 'search',
    meta_description: 'search',
    social_title: 'social',
    social_description: 'social',
};

/** Where a search check's warning leads in the box: its tab and its field. */
const FIXES: Partial<Record<CheckId, { tab: string; field: string }>> = {
    description: { tab: 'search', field: 'editor-seo-description' },
    'title-width': { tab: 'search', field: 'editor-seo-title' },
    listing: { tab: 'advanced', field: 'editor-seo-noindex' },
};

/** The result's size a phone starts at: its own. */
function startingSize(): ResultSize {
    return typeof window !== 'undefined' &&
        window.matchMedia('(max-width: 639px)').matches
        ? 'mobile'
        : 'desktop';
}

/**
 * Search and social, at the foot of the writing column (I-230, I-3415): a
 * header strip naming the box, the result's title and how many things to
 * check, which folds the whole box (I-3124, folded on every visit); open, the
 * tabs Search, Social, Schema and Advanced, one at a time, with each plugin's
 * tab where its author declared it. The Search tab holds the result preview,
 * the SEO title, the meta description and, after them, the checks. Every
 * value is a built-in field saved with the item's revision (I-147, I-293,
 * I-693), kept as typed across tabs and folds. With AI, each field's proposal
 * sits under it.
 */
export function SearchPanel({
    fields,
    values,
    onChange,
    seo,
    search,
    address,
    contentId,
    noun,
    plural,
    media,
    loading,
    addMedia,
    metadata,
    open,
    onOpenChange,
    onGoToCheck,
    tab: shownTab,
    onTabChange,
    pluginTabs = [],
    pluginChecks = [],
    onGoToPlugin,
}: {
    fields: EditorField[];
    values: Record<string, unknown>;
    onChange: (key: string, value: unknown) => void;
    seo: EditorSeo;
    search: SearchPreview;
    address: string;
    contentId: string;
    noun: string;
    plural: string;
    media: Record<string, CardMedia>;
    loading: string[];
    addMedia: (ids: string[]) => Promise<void>;
    metadata: MetadataBinding;
    /** Whether the fields show: closed on every visit (I-3124). */
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Puts the cursor on a check's problem outside the box (alt text, links), as the rail's Checks do. */
    onGoToCheck?: (check: CheckId) => void;
    /** The tab shown, when the editor leads to a field; the box keeps its own otherwise. */
    tab?: string;
    onTabChange?: (tab: string) => void;
    /** The plugins' tabs, each where its plugin declared it (docs/guides/developers/plugins/seo.md). */
    pluginTabs?: PluginSearchTab[];
    /** The plugins' checks, after core's, each under its plugin's name. */
    pluginChecks?: PluginCheckGroup[];
    /** Puts the cursor on the field a plugin's check names. */
    onGoToPlugin?: (code: string, result: PluginCheckResult) => void;
}) {
    const tabs = orderTabs(
        pluginTabs.map((each) => ({
            id: each.key,
            owner: each.code,
            position: each.position,
        })),
    );
    const [ownTab, setOwnTab] = useState('search');
    const tab = shownTab ?? ownTab;
    const setTab = onTabChange ?? setOwnTab;
    const [size, setSize] = useState<ResultSize>(startingSize);
    const { settingsNav } = usePage().props;
    const searchSettings = settingsNav.find((item) =>
        item.href.endsWith('/settings/seo'),
    );
    const field = (handle: string) =>
        fields.find((each) => each.handle === handle);
    const seoTitle = field('seo_title');
    const metaDescription = field('meta_description');
    const noindex = field('noindex');
    const canonical = field('canonical');
    const socialTitle = field('social_title');
    const socialDescription = field('social_description');
    const socialImage = field('social_image');
    const home = seo.kind === 'home';
    const proposes =
        metadata.offered &&
        fields.some((each) => METADATA_FIELDS.includes(each.handle));

    const suggestion = useMetadata(metadata);
    // Proposals for the section's fields open it, so none waits out of sight (I-3124).
    const waitingHere = Object.keys(suggestion.proposals).some(
        (handle) => handle !== 'excerpt',
    );

    // A tab not shown that holds a proposal is marked, so none waits unseen there (I-3124).
    const waitingOn = new Set(
        Object.keys(suggestion.proposals).map(
            (handle) => PROPOSAL_TABS[handle],
        ),
    );

    useEffect(() => {
        if (waitingHere) {
            onOpenChange(true);
        }
    }, [waitingHere, onOpenChange]);

    const searchChecks = search.checks.filter((check) => check.search);
    const toCheck = searchToCheck(searchChecks);
    const [checksOpen, setChecksOpen] = useState(false);
    const structuredCheck = search.checks.find(
        (check) => check.id === 'structured-data',
    );

    // A check's Fix opens its tab and puts the cursor on its field; one outside the box goes where the rail's would.
    const fixable = (check: CheckId) =>
        FIXES[check] !== undefined || onGoToCheck !== undefined;
    const fix = (check: CheckId) => {
        const target = FIXES[check];

        if (target === undefined) {
            onGoToCheck?.(check);

            return;
        }

        flushSync(() => setTab(target.tab));
        document.getElementById(target.field)?.focus();
    };

    const setForSharing = [socialTitle, socialDescription, socialImage].filter(
        (each) => {
            const value = each === undefined ? null : values[each.key];

            return typeof value === 'string'
                ? value.trim() !== ''
                : value !== null && value !== undefined;
        },
    ).length;
    const sharingLine =
        setForSharing === 0 && search.card.imageFrom === 'featured'
            ? __('Same as search, with the featured image.')
            : __(':count of 3 set for sharing; the rest same as search.', {
                  count: setForSharing,
              });

    const [chosen, setChosen] = useState<ChosenItem | null>(() =>
        seo.canonicalItem !== null &&
        (seo.canonicalItem.state === 'published' ||
            seo.canonicalItem.state === 'unpublished')
            ? seo.canonicalItem
            : null,
    );

    const stored = canonical === undefined ? null : values[canonical.key];
    const storedItem = (stored as { item?: unknown } | null)?.item;
    const storedAddress =
        typeof stored === 'string'
            ? stored.trim()
            : textOf((stored as { address?: unknown } | null)?.address);
    const canonicalNote =
        typeof storedItem === 'string' &&
        chosen !== null &&
        chosen.id === storedItem &&
        chosen.state === 'published'
            ? `Search engines are asked to list “${quotedTitle(chosen.title)}” instead.`
            : typeof storedItem !== 'string' && storedAddress !== ''
              ? `Search engines are asked to list ${storedAddress} instead.`
              : null;

    const titleText =
        seoTitle === undefined ? '' : textOf(values[seoTitle.key]);
    const description = search.description;
    const siteName = seo.tokens.site ?? '';
    const date = seo.kind === 'item' ? (seo.tokens.date ?? null) : null;
    const descriptionPlaceholder =
        description.from === 'excerpt' && description.text !== null
            ? __('Using the excerpt: :excerpt', {
                  excerpt: description.text,
              })
            : description.from === 'tagline' && description.text !== null
              ? description.text
              : __(
                    'No excerpt either: search engines will choose text from the page',
                );

    return (
        <section
            aria-labelledby="editor-search-label"
            data-test="search-section"
            className="-mx-4 grid grid-cols-1 sm:-mx-10"
        >
            <div
                data-test="search-strip"
                className="bg-chrome border-line flex min-h-13 w-full items-center gap-3 border-y pr-4 pl-6"
            >
                <h2
                    id="editor-search-label"
                    tabIndex={-1}
                    className="text-body focus-visible:ring-ring shrink-0 rounded-sm font-semibold whitespace-nowrap outline-none focus-visible:ring-2"
                >
                    {__('Search and social')}
                </h2>
                <p
                    data-test="search-check-line"
                    className="text-ink-2 text-small min-w-0 flex-1 truncate"
                >
                    {`${search.title.text} · ${searchCheckWords(toCheck)}`}
                </p>
                {open && proposes && (
                    <SuggestMetadataButtons state={suggestion} />
                )}
                <Button
                    size="icon-sm"
                    variant="ghost"
                    data-test="search-toggle"
                    aria-expanded={open}
                    aria-controls="editor-search-fields"
                    aria-label={
                        open
                            ? __('Fold Search and social')
                            : __('Open Search and social')
                    }
                    title={
                        open
                            ? __('Fold Search and social')
                            : __('Open Search and social')
                    }
                    onClick={() => onOpenChange(!open)}
                >
                    <ChevronDown className={open ? 'rotate-180' : undefined} />
                </Button>
            </div>
            <div
                id="editor-search-fields"
                hidden={!open}
                className="grid grid-cols-1 gap-3 pt-8"
            >
                {proposes && <SuggestMetadataNote state={suggestion} />}
                <div className="bg-surface shadow-raised @container min-w-0 rounded-lg">
                    <Tabs value={tab} onValueChange={setTab} className="gap-0">
                        <TabsList
                            variant="underline"
                            aria-label={__('Search and social')}
                        >
                            {tabs.map((each) => (
                                <TabsTrigger
                                    key={each}
                                    value={each}
                                    id={
                                        each === 'advanced'
                                            ? 'editor-advanced'
                                            : undefined
                                    }
                                    aria-describedby={
                                        each !== tab && waitingOn.has(each)
                                            ? `editor-tab-${each}-waiting`
                                            : undefined
                                    }
                                >
                                    <TabIcon
                                        tab={each}
                                        plugin={pluginTabs.some(
                                            (plugin) => plugin.key === each,
                                        )}
                                        warns={toCheck > 0}
                                    />
                                    {tabName(each, pluginTabs)}
                                    {each !== tab && waitingOn.has(each) && (
                                        <span
                                            aria-hidden
                                            data-test="tab-proposal"
                                            className="bg-accent size-2 rounded-full"
                                        />
                                    )}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        {tabs.map((each) => (
                            <span
                                key={each}
                                id={`editor-tab-${each}-waiting`}
                                hidden
                            >
                                {__('proposal waiting')}
                            </span>
                        ))}
                        <TabsContent
                            value="search"
                            forceMount
                            hidden={tab !== 'search'}
                            className="divide-line grid grid-cols-1 divide-y"
                        >
                            <BoxRow
                                guide={
                                    <>
                                        <span className="text-small font-semibold">
                                            {__('Result preview')}
                                        </span>
                                        <Segmented
                                            aria-label={__('Result size')}
                                            value={size}
                                            onValueChange={(next) =>
                                                setSize(next as ResultSize)
                                            }
                                            className="justify-self-start"
                                        >
                                            <SegmentedItem value="desktop">
                                                <Monitor
                                                    aria-hidden
                                                    className="size-3.5"
                                                />
                                                {__('Desktop')}
                                            </SegmentedItem>
                                            <SegmentedItem value="mobile">
                                                <Smartphone
                                                    aria-hidden
                                                    className="size-3.5"
                                                />
                                                {__('Mobile')}
                                            </SegmentedItem>
                                        </Segmented>
                                        <p className="text-ink-2 text-small">
                                            {__(
                                                'As a search result may show it.',
                                            )}
                                        </p>
                                    </>
                                }
                            >
                                <SearchResult
                                    search={search}
                                    address={address}
                                    siteName={siteName}
                                    date={date}
                                    canonicalNote={canonicalNote}
                                    plural={plural}
                                    size={size}
                                />
                            </BoxRow>
                            {seoTitle && (
                                <BoxRow
                                    guide={
                                        <>
                                            <Label htmlFor="editor-seo-title">
                                                {seoTitle.label}
                                            </Label>
                                            <Length
                                                id="editor-seo-title-count"
                                                testId="seo-title-bar"
                                                label={__(
                                                    'The SEO title’s length',
                                                )}
                                                length={
                                                    Array.from(
                                                        search.title.text,
                                                    ).length
                                                }
                                                guide={60}
                                                over={search.title.width > 580}
                                                from={
                                                    search.title.fromTemplate
                                                        ? home
                                                            ? __(
                                                                  'the home page’s template',
                                                              )
                                                            : __('the template')
                                                        : null
                                                }
                                            />
                                        </>
                                    }
                                >
                                    <TextareaControl
                                        field={seoTitle}
                                        value={titleText}
                                        onChange={(next) =>
                                            onChange(
                                                seoTitle.key,
                                                String(next).replace(
                                                    /\s*\n\s*/g,
                                                    ' ',
                                                ),
                                            )
                                        }
                                        id="editor-seo-title"
                                        describedBy="editor-seo-title-count"
                                        rows={2}
                                        className={`${textareaClass} resize-none`}
                                        placeholder={__(
                                            'Using the title template: :title',
                                            { title: search.title.text },
                                        )}
                                    />
                                    <MetadataProposal
                                        handle="seo_title"
                                        label={seoTitle.label}
                                        metadata={metadata}
                                    />
                                </BoxRow>
                            )}
                            {metaDescription && (
                                <BoxRow
                                    guide={
                                        <>
                                            <Label htmlFor="editor-seo-description">
                                                {metaDescription.label}
                                            </Label>
                                            <Length
                                                id="editor-seo-description-count"
                                                testId="seo-description-bar"
                                                label={__(
                                                    'The meta description’s length',
                                                )}
                                                length={
                                                    description.text === null
                                                        ? 0
                                                        : Array.from(
                                                              description.text,
                                                          ).length
                                                }
                                                guide={155}
                                                over={description.width > 920}
                                                from={
                                                    description.from ===
                                                    'excerpt'
                                                        ? __('the excerpt')
                                                        : description.from ===
                                                            'tagline'
                                                          ? __(
                                                                'the site’s tagline',
                                                            )
                                                          : null
                                                }
                                            />
                                        </>
                                    }
                                >
                                    <TextareaControl
                                        field={metaDescription}
                                        value={values[metaDescription.key]}
                                        onChange={(next) =>
                                            onChange(metaDescription.key, next)
                                        }
                                        id="editor-seo-description"
                                        describedBy="editor-seo-description-count"
                                        rows={3}
                                        className={`${textareaClass} resize-y`}
                                        placeholder={descriptionPlaceholder}
                                    />
                                    <MetadataProposal
                                        handle="meta_description"
                                        label={metaDescription.label}
                                        metadata={metadata}
                                    />
                                </BoxRow>
                            )}
                            <Collapsible
                                open={checksOpen}
                                onOpenChange={setChecksOpen}
                            >
                                <h3>
                                    <CollapsibleTrigger
                                        data-test="search-checks-toggle"
                                        className="hover:bg-surface-2 focus-visible:ring-ring flex min-h-16 w-full items-center gap-3 px-6 text-start outline-none focus-visible:ring-2 focus-visible:ring-inset"
                                    >
                                        {toCheck > 0 ? (
                                            <TriangleAlert
                                                aria-hidden
                                                data-icon="warning"
                                                className="text-warn size-4 shrink-0"
                                            />
                                        ) : (
                                            <CheckCircle2
                                                aria-hidden
                                                data-icon="passed"
                                                className="text-ok size-4 shrink-0"
                                            />
                                        )}
                                        <span className="text-lead min-w-0 flex-1 font-medium">
                                            {__('Checks')}{' '}
                                            <span className="text-ink-2 text-small ms-2 font-normal">
                                                {`· ${searchCheckWords(toCheck)}`}
                                            </span>
                                        </span>
                                        <ChevronDown
                                            aria-hidden
                                            className={`text-ink-3 size-5 shrink-0 transition-transform duration-(--dur-2) ease-(--ease) ${checksOpen ? 'rotate-180' : ''}`}
                                        />
                                    </CollapsibleTrigger>
                                </h3>
                                <CollapsibleContent className="grid gap-3 px-6 pb-6">
                                    <ul
                                        data-test="search-checks"
                                        className="text-body grid gap-3"
                                    >
                                        {searchChecks.map((check) => (
                                            <li
                                                key={check.id}
                                                className="flex items-center gap-2"
                                            >
                                                {check.state === 'ok' ? (
                                                    <CheckCircle2
                                                        aria-hidden
                                                        className="text-ok-ink size-4 shrink-0"
                                                    />
                                                ) : (
                                                    <CircleAlert
                                                        aria-hidden
                                                        className="text-warn-ink size-4 shrink-0"
                                                    />
                                                )}
                                                <span className="min-w-0 flex-1">
                                                    <strong className="font-semibold">
                                                        {check.label}
                                                    </strong>
                                                    <span className="sr-only">
                                                        {' '}
                                                        {check.state === 'ok'
                                                            ? __('passed')
                                                            : __('warning')}
                                                    </span>{' '}
                                                    <span className="text-ink-2">
                                                        {check.message}
                                                    </span>
                                                </span>
                                                {check.state === 'warn' &&
                                                    fixable(check.id) && (
                                                        <Button
                                                            size="sm"
                                                            variant="neutral"
                                                            aria-label={__(
                                                                'Fix: :check',
                                                                {
                                                                    check: check.label,
                                                                },
                                                            )}
                                                            onClick={() =>
                                                                fix(check.id)
                                                            }
                                                        >
                                                            {__('Fix')}
                                                        </Button>
                                                    )}
                                            </li>
                                        ))}
                                    </ul>
                                    <PluginChecks
                                        groups={pluginChecks}
                                        onGoTo={onGoToPlugin}
                                        testId="search-plugin-checks"
                                    />
                                    <p className="text-ink-2 text-small">
                                        {__(
                                            'A warning never blocks publishing.',
                                        )}
                                    </p>
                                </CollapsibleContent>
                            </Collapsible>
                        </TabsContent>
                        <TabsContent
                            value="social"
                            forceMount
                            hidden={tab !== 'social'}
                            className="divide-line grid grid-cols-1 divide-y"
                        >
                            <p
                                data-test="social-line"
                                className="text-ink-2 text-small px-6 py-4"
                            >
                                {sharingLine}
                            </p>
                            {socialTitle && (
                                <BoxRow
                                    guide={
                                        <>
                                            <Label htmlFor="editor-social-title">
                                                {socialTitle.label}
                                            </Label>
                                            <p
                                                id="editor-social-title-hint"
                                                className="text-ink-2 text-caption"
                                            >
                                                {__(
                                                    'The title on the card when it’s shared. Leave empty to use the SEO title, or else the title.',
                                                )}
                                            </p>
                                        </>
                                    }
                                >
                                    <TextControl
                                        field={socialTitle}
                                        value={values[socialTitle.key]}
                                        onChange={(next) =>
                                            onChange(socialTitle.key, next)
                                        }
                                        id="editor-social-title"
                                        describedBy="editor-social-title-hint"
                                        placeholder={search.title.text}
                                    />
                                    <MetadataProposal
                                        handle="social_title"
                                        label={socialTitle.label}
                                        metadata={metadata}
                                    />
                                </BoxRow>
                            )}
                            {socialDescription && (
                                <BoxRow
                                    guide={
                                        <>
                                            <Label htmlFor="editor-social-description">
                                                {socialDescription.label}
                                            </Label>
                                            <p
                                                id="editor-social-description-hint"
                                                className="text-ink-2 text-caption"
                                            >
                                                {__(
                                                    'Leave empty to use the meta description, or else the excerpt.',
                                                )}
                                            </p>
                                        </>
                                    }
                                >
                                    <TextareaControl
                                        field={socialDescription}
                                        value={values[socialDescription.key]}
                                        onChange={(next) =>
                                            onChange(
                                                socialDescription.key,
                                                next,
                                            )
                                        }
                                        id="editor-social-description"
                                        describedBy="editor-social-description-hint"
                                        rows={2}
                                        className={`${textareaClass} resize-y`}
                                        placeholder={
                                            search.description.text === null
                                                ? undefined
                                                : search.description.text
                                        }
                                    />
                                    <MetadataProposal
                                        handle="social_description"
                                        label={socialDescription.label}
                                        metadata={metadata}
                                    />
                                </BoxRow>
                            )}
                            {socialImage && (
                                <div className="px-6 py-5">
                                    <SocialImage
                                        field={socialImage}
                                        value={values[socialImage.key]}
                                        onChange={(next) =>
                                            onChange(socialImage.key, next)
                                        }
                                        card={search.card}
                                        media={media}
                                        loading={loading}
                                        addMedia={addMedia}
                                    />
                                </div>
                            )}
                            <div className="px-6 py-5">
                                <SocialCard
                                    search={search}
                                    address={address}
                                    media={media}
                                    loading={loading}
                                    seo={seo}
                                    noun={noun}
                                />
                            </div>
                        </TabsContent>
                        <TabsContent
                            value="schema"
                            forceMount
                            hidden={tab !== 'schema'}
                            className="text-small grid gap-2 p-6"
                        >
                            <p>
                                {__('Published as :type', {
                                    type:
                                        seo.schema.mainEntity ??
                                        seo.schema.pageType,
                                })}
                            </p>
                            {structuredCheck !== undefined && (
                                <p className="text-ink-2">
                                    {structuredCheck.message}
                                </p>
                            )}
                            {searchSettings !== undefined && (
                                <Link
                                    href={searchSettings.href}
                                    className="text-accent-ink justify-self-start underline"
                                >
                                    {__(
                                        'Change this for every item in Settings › Search and social',
                                    )}
                                </Link>
                            )}
                        </TabsContent>
                        {pluginTabs.map((each) => (
                            <TabsContent
                                key={each.key}
                                value={each.key}
                                forceMount
                                hidden={tab !== each.key}
                            >
                                <PluginTab
                                    tab={each}
                                    value={values[each.key]}
                                    onChange={(next) =>
                                        onChange(each.key, next)
                                    }
                                />
                            </TabsContent>
                        ))}
                        <TabsContent
                            value="advanced"
                            forceMount
                            hidden={tab !== 'advanced'}
                            className="p-6"
                        >
                            <AdvancedFold
                                fields={fields}
                                values={values}
                                onChange={onChange}
                                seo={seo}
                                leading={
                                    noindex !== undefined ||
                                    canonical !== undefined
                                        ? {
                                              node: (
                                                  <>
                                                      {noindex && (
                                                          <div className="grid gap-1">
                                                              <BooleanControl
                                                                  field={
                                                                      noindex
                                                                  }
                                                                  value={
                                                                      values[
                                                                          noindex
                                                                              .key
                                                                      ]
                                                                  }
                                                                  onChange={(
                                                                      next,
                                                                  ) =>
                                                                      onChange(
                                                                          noindex.key,
                                                                          next,
                                                                      )
                                                                  }
                                                                  id="editor-seo-noindex"
                                                                  describedBy="editor-seo-noindex-hint"
                                                              />
                                                              <p
                                                                  id="editor-seo-noindex-hint"
                                                                  className="text-ink-2 text-caption"
                                                              >
                                                                  {__(
                                                                      'Asks search engines not to list this :noun.',
                                                                      { noun },
                                                                  )}
                                                              </p>
                                                          </div>
                                                      )}
                                                      {canonical && (
                                                          <CanonicalChoice
                                                              value={
                                                                  values[
                                                                      canonical
                                                                          .key
                                                                  ]
                                                              }
                                                              onChange={(
                                                                  next,
                                                              ) =>
                                                                  onChange(
                                                                      canonical.key,
                                                                      next,
                                                                  )
                                                              }
                                                              chosen={chosen}
                                                              onChoose={
                                                                  setChosen
                                                              }
                                                              seo={seo}
                                                              listed={
                                                                  search.listing ===
                                                                  'listed'
                                                              }
                                                              noun={noun}
                                                              contentId={
                                                                  contentId
                                                              }
                                                          />
                                                      )}
                                                  </>
                                              ),
                                              set: [
                                                  values[noindex?.key ?? ''] ===
                                                  true
                                                      ? 'Hidden from search engines'
                                                      : null,
                                                  typeof storedItem === 'string'
                                                      ? 'Canonical: another item'
                                                      : storedAddress !== ''
                                                        ? 'Canonical: another site'
                                                        : null,
                                              ].filter(
                                                  (part): part is string =>
                                                      part !== null,
                                              ),
                                              holds: [
                                                  noindex !== undefined
                                                      ? 'Hide from search engines'
                                                      : null,
                                                  canonical !== undefined
                                                      ? 'canonical address'
                                                      : null,
                                              ].filter(
                                                  (part): part is string =>
                                                      part !== null,
                                              ),
                                          }
                                        : null
                                }
                            />
                        </TabsContent>
                    </Tabs>
                </div>
            </div>
        </section>
    );
}
