import { Link, usePage } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, CircleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { flushSync } from 'react-dom';
import { BooleanControl } from '@/components/editor/fields/boolean-control';
import { TextControl } from '@/components/editor/fields/text-control';
import { TextareaControl } from '@/components/editor/fields/textarea-control';
import { AdvancedFold } from '@/components/editor/rail/advanced-fold';
import { searchCheckLine } from '@/components/editor/rail/checks';
import { CanonicalChoice } from '@/components/editor/rail/canonical-choice';
import type { ChosenItem } from '@/components/editor/rail/canonical-choice';
import { MetadataProposal } from '@/components/editor/rail/metadata-proposal';
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
import { BoxRow } from '@/components/editor/search-social/box-row';
import { orderTabs } from '@/components/editor/search-social/tab-order';
import type { CheckId } from '@/components/editor/rail/checks';
import type { SearchPreview } from '@/components/editor/use-search-preview';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import type { CardMedia, EditorField, EditorSeo } from '@/types/editor';
import { quotedTitle } from '@/lib/titles';

/** A text field's value, or nothing. */
function textOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/** "1 character", "30 characters". */
function characters(text: string): string {
    const n = Array.from(text).length;

    return `${n} ${n === 1 ? 'character' : 'characters'}`;
}

const textareaClass =
    'border-edge bg-surface focus-visible:ring-ring focus-visible:ring-offset-page field-sizing-content min-h-[34px] w-full resize-none rounded-md border px-3 py-2 text-small outline-none focus-visible:ring-2 focus-visible:ring-offset-1';

/** A count under a field: in characters and about the pixels, a warning past the width. */
function Count({
    id,
    children,
    over,
}: {
    id: string;
    children: string;
    over: boolean;
}) {
    return (
        <p
            id={id}
            className={
                over ? 'text-warn-ink text-caption' : 'text-ink-2 text-caption'
            }
        >
            {children}
        </p>
    );
}

/** The box's tabs, core's four in their order (I-3418); a plugin's join once plugins ship admin code. */
const TABS = orderTabs([]);

/** Each tab's name. */
function tabName(tab: string): string {
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

/** Where a search check's warning leads in the box: its tab and its field. */
/** The tab each AI proposal waits on, under its field. */
const PROPOSAL_TABS: Record<string, string> = {
    seo_title: 'search',
    meta_description: 'search',
    social_title: 'social',
    social_description: 'social',
};

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
 * Search and social, at the foot of the writing column (I-230), closed until
 * opened (I-3124): a box (I-3415) whose strip names it and folds it, one line
 * for core's search checks, and the search result at true size; open, the
 * tabs Search, Social, Schema and Advanced, one at a time, then the checks.
 * Every value is a built-in field saved with the item's revision (I-147,
 * I-293, I-693), kept as typed across tabs and folds. With AI, each field's
 * proposal sits under it.
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
    onGoToChecks,
    tab: shownTab,
    onTabChange,
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
    /** Opens the side panel at its Checks, or null where it can't show. */
    onGoToChecks: (() => void) | null;
    /** The tab shown, when the editor leads to a field; the box keeps its own otherwise. */
    tab?: string;
    onTabChange?: (tab: string) => void;
}) {
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
    const hasExcerpt = field('excerpt') !== undefined;
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
    const line = searchCheckLine(searchChecks);
    const structuredCheck = search.checks.find(
        (check) => check.id === 'structured-data',
    );

    // A check's warning opens its tab and puts the cursor on its field.
    const fix = (check: CheckId) => {
        const target = FIXES[check];

        if (target === undefined) {
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
    const titleOver = search.title.width > 580;
    const titleCount = `${search.title.fromTemplate ? 'From the title template: ' : ''}${characters(search.title.text)}, about ${search.title.width} of 580 pixels${titleOver ? '. Results will cut it.' : ''}`;

    const description = search.description;
    const descriptionOver = description.width > 920;
    const descriptionCount =
        description.text === null
            ? hasExcerpt
                ? 'None, and no excerpt to fall back on.'
                : 'None. Search engines choose their own text.'
            : `${description.from === 'excerpt' ? 'From the excerpt: ' : description.from === 'tagline' ? 'From the site’s tagline: ' : ''}${characters(description.text)}, about ${description.width} of 920 pixels${descriptionOver ? '. Results will cut it.' : ''}`;

    return (
        <section
            aria-labelledby="editor-search-label"
            data-test="search-section"
            className="bg-surface shadow-raised @container grid grid-cols-1 rounded-lg"
        >
            <div className="bg-chrome flex min-h-13 items-center gap-3 rounded-t-lg pr-4 pl-6">
                <div className="grid min-w-0 flex-1 gap-0.5 py-2">
                    <h2
                        id="editor-search-label"
                        tabIndex={-1}
                        className="text-body focus-visible:ring-ring rounded-sm font-semibold outline-none focus-visible:ring-2"
                    >
                        {__('Search and social')}
                    </h2>
                    <p className="text-ink-2 text-caption">
                        {__(
                            'How this :noun appears in search results and when it’s shared.',
                            { noun },
                        )}
                    </p>
                </div>
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
                data-test="search-check-line"
                className="border-line flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-6 py-3"
            >
                <div className="text-caption flex min-w-0 flex-1 items-start gap-2">
                    {line.warnings.length > 0 ? (
                        <CircleAlert
                            role="img"
                            aria-label={__('Warning')}
                            className="text-warn-ink mt-px size-4 shrink-0"
                        />
                    ) : (
                        <CheckCircle2
                            role="img"
                            aria-label={__('Passed')}
                            className="text-ok-ink mt-px size-4 shrink-0"
                        />
                    )}
                    <p>
                        {line.warnings.map((warning) => (
                            <span key={warning.id}>
                                <strong className="font-semibold">
                                    {warning.label}.
                                </strong>{' '}
                                {warning.message}{' '}
                            </span>
                        ))}
                        {line.warnings.length === 0 ? (
                            <strong className="font-semibold">
                                {line.summary}
                            </strong>
                        ) : (
                            line.summary
                        )}
                    </p>
                </div>
                {onGoToChecks !== null ? (
                    <TextButton
                        data-test="search-checks-link"
                        onClick={onGoToChecks}
                        className="text-caption font-normal decoration-current"
                    >
                        {__('Every check is in the side panel.')}
                    </TextButton>
                ) : (
                    <p className="text-ink-2 text-caption">
                        {__('Every check is in the side panel.')}
                    </p>
                )}
            </div>
            <div className="grid grid-cols-1 gap-3 px-6 pt-4 pb-6">
                {open && (
                    <Segmented
                        aria-label={__('Result size')}
                        value={size}
                        onValueChange={(next) => setSize(next as ResultSize)}
                        className="justify-self-start"
                    >
                        <SegmentedItem value="desktop">
                            {__('Desktop')}
                        </SegmentedItem>
                        <SegmentedItem value="mobile">
                            {__('Mobile')}
                        </SegmentedItem>
                    </Segmented>
                )}
                <SearchResult
                    search={search}
                    address={address}
                    canonicalNote={canonicalNote}
                    plural={plural}
                    descriptionFrom={search.description.from}
                    size={size}
                />
            </div>
            <div
                id="editor-search-fields"
                hidden={!open}
                className="grid grid-cols-1 gap-4 px-6 pb-6"
            >
                {proposes && <SuggestMetadataNote state={suggestion} />}
                <Tabs value={tab} onValueChange={setTab}>
                    <TabsList className="flex-wrap">
                        {TABS.map((each) => (
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
                                {tabName(each)}
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
                    {TABS.map((each) => (
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
                        className="grid gap-3"
                    >
                        {seoTitle && (
                            <BoxRow
                                guide={
                                    <>
                                        <Label htmlFor="editor-seo-title">
                                            {seoTitle.label}
                                        </Label>
                                        <p
                                            id="editor-seo-title-hint"
                                            className="text-ink-2 text-caption"
                                        >
                                            {__(
                                                'The title search engines show, exactly as you type it.',
                                            )}{' '}
                                            {home
                                                ? __(
                                                      'Leave empty to use the home page’s title template.',
                                                  )
                                                : __(
                                                      'Leave empty to use the title template for :plural.',
                                                      { plural },
                                                  )}
                                        </p>
                                        <Count
                                            id="editor-seo-title-count"
                                            over={titleOver}
                                        >
                                            {titleCount}
                                        </Count>
                                    </>
                                }
                            >
                                <TextControl
                                    field={seoTitle}
                                    value={titleText}
                                    onChange={(next) =>
                                        onChange(seoTitle.key, next)
                                    }
                                    id="editor-seo-title"
                                    describedBy="editor-seo-title-hint editor-seo-title-count"
                                    placeholder={
                                        search.title.fromTemplate
                                            ? search.title.text
                                            : undefined
                                    }
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
                                        <p
                                            id="editor-seo-description-hint"
                                            className="text-ink-2 text-caption"
                                        >
                                            {__(
                                                'The summary search engines may show under the title.',
                                            )}{' '}
                                            {!hasExcerpt
                                                ? __(
                                                      'Leave empty and search engines choose their own.',
                                                  )
                                                : home
                                                  ? __(
                                                        'Leave empty to use the excerpt, or else the site’s tagline.',
                                                    )
                                                  : __(
                                                        'Leave empty to use the excerpt.',
                                                    )}
                                        </p>
                                        <Count
                                            id="editor-seo-description-count"
                                            over={descriptionOver}
                                        >
                                            {descriptionCount}
                                        </Count>
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
                                    describedBy="editor-seo-description-hint editor-seo-description-count"
                                    rows={3}
                                    className={textareaClass}
                                />
                                <MetadataProposal
                                    handle="meta_description"
                                    label={metaDescription.label}
                                    metadata={metadata}
                                />
                            </BoxRow>
                        )}
                    </TabsContent>
                    <TabsContent
                        value="social"
                        forceMount
                        hidden={tab !== 'social'}
                        className="grid gap-3"
                    >
                        <p
                            data-test="social-line"
                            className="text-ink-2 text-small"
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
                                    placeholder={__('Same as search: :value', {
                                        value: search.title.text,
                                    })}
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
                                        onChange(socialDescription.key, next)
                                    }
                                    id="editor-social-description"
                                    describedBy="editor-social-description-hint"
                                    rows={2}
                                    className={textareaClass}
                                    placeholder={
                                        search.description.text === null
                                            ? undefined
                                            : __('Same as search: :value', {
                                                  value: search.description
                                                      .text,
                                              })
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
                        )}
                        <SocialCard
                            search={search}
                            address={address}
                            media={media}
                            loading={loading}
                            seo={seo}
                            noun={noun}
                        />
                    </TabsContent>
                    <TabsContent
                        value="schema"
                        forceMount
                        hidden={tab !== 'schema'}
                        className="text-small grid gap-2"
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
                    <TabsContent
                        value="advanced"
                        forceMount
                        hidden={tab !== 'advanced'}
                    >
                        <AdvancedFold
                            fields={fields}
                            values={values}
                            onChange={onChange}
                            seo={seo}
                            leading={
                                noindex !== undefined || canonical !== undefined
                                    ? {
                                          node: (
                                              <>
                                                  {noindex && (
                                                      <div className="grid gap-1">
                                                          <BooleanControl
                                                              field={noindex}
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
                                                                  canonical.key
                                                              ]
                                                          }
                                                          onChange={(next) =>
                                                              onChange(
                                                                  canonical.key,
                                                                  next,
                                                              )
                                                          }
                                                          chosen={chosen}
                                                          onChoose={setChosen}
                                                          seo={seo}
                                                          listed={
                                                              search.listing ===
                                                              'listed'
                                                          }
                                                          noun={noun}
                                                          contentId={contentId}
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
                <ul
                    data-test="search-checks"
                    className="border-line text-small grid gap-2 border-t pt-4"
                >
                    {searchChecks.map((check) => (
                        <li key={check.id} className="flex items-start gap-2">
                            {check.state === 'ok' ? (
                                <CheckCircle2
                                    aria-hidden
                                    className="text-ok-ink mt-1 size-4 shrink-0"
                                />
                            ) : (
                                <CircleAlert
                                    aria-hidden
                                    className="text-warn-ink mt-1 size-4 shrink-0"
                                />
                            )}
                            <span>
                                {check.state === 'warn' &&
                                FIXES[check.id] !== undefined ? (
                                    <TextButton
                                        type="button"
                                        onClick={() => fix(check.id)}
                                    >
                                        {check.label}
                                    </TextButton>
                                ) : (
                                    <span className="font-medium">
                                        {check.label}
                                    </span>
                                )}
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
                        </li>
                    ))}
                </ul>
                {/* Later (I-286): the SEO provider's panel, its keyphrase and score, goes here, after core's checks: a plugin declares it and core draws it (phase 5). */}
            </div>
        </section>
    );
}
