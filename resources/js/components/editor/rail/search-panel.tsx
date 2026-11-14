import { CheckCircle2, CircleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { BooleanControl } from '@/components/editor/fields/boolean-control';
import { TextControl } from '@/components/editor/fields/text-control';
import { TextareaControl } from '@/components/editor/fields/textarea-control';
import { AdvancedFold } from '@/components/editor/rail/advanced-fold';
import { searchCheckLine } from '@/components/editor/rail/checks';
import { CanonicalChoice } from '@/components/editor/rail/canonical-choice';
import type { ChosenItem } from '@/components/editor/rail/canonical-choice';
import { MetadataProposal } from '@/components/editor/rail/metadata-proposal';
import { SearchPreviews } from '@/components/editor/rail/search-previews';
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
import type { SearchPreview } from '@/components/editor/use-search-preview';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
    'border-edge bg-surface focus-visible:ring-ring focus-visible:ring-offset-page field-sizing-content min-h-[34px] w-full resize-none rounded-md border px-3 py-2 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-offset-1';

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
            className={over ? 'text-warn-ink text-xs' : 'text-ink-2 text-xs'}
        >
            {children}
        </p>
    );
}

/**
 * Search and social, at the foot of the writing column (I-230), closed until
 * Edit (I-3124): the search result and the social card, one line for core's
 * search checks, and, open, the item's title and description for search
 * results and its social card text and image, with Hide from search engines
 * and the canonical address in Advanced; every value is a built-in field
 * saved with the item's revision (I-147, I-293, I-693). With AI, each field's
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
}) {
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

    useEffect(() => {
        if (waitingHere) {
            onOpenChange(true);
        }
    }, [waitingHere, onOpenChange]);

    const line = searchCheckLine(search.checks.filter((check) => check.search));

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
            className="border-line bg-surface @container grid gap-4 rounded-lg border px-5 py-4"
        >
            <div className="flex items-start gap-3">
                <div className="grid flex-1 gap-1">
                    <h2
                        id="editor-search-label"
                        tabIndex={-1}
                        className="text-[15px] font-semibold outline-none"
                    >
                        Search and social
                    </h2>
                    <p className="text-ink-2 text-xs">
                        How this {noun} appears in search results and when it’s
                        shared.
                    </p>
                </div>
                {open && proposes && (
                    <SuggestMetadataButtons state={suggestion} />
                )}
                <Button
                    size="sm"
                    variant="outline"
                    data-test="search-toggle"
                    aria-expanded={open}
                    aria-controls="editor-search-fields"
                    onClick={() => onOpenChange(!open)}
                >
                    {open ? 'Done' : 'Edit'}
                    <span className="sr-only">
                        {open
                            ? ' with search and social'
                            : ' search and social'}
                    </span>
                </Button>
            </div>
            {open && proposes && <SuggestMetadataNote state={suggestion} />}
            <SearchPreviews
                search={search}
                address={address}
                canonicalNote={canonicalNote}
                media={media}
                loading={loading}
                seo={seo}
                noun={noun}
                plural={plural}
                descriptionFrom={search.description.from}
            />
            <div
                data-test="search-check-line"
                className="border-line flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-3"
            >
                <div className="flex min-w-0 flex-1 items-start gap-2 text-xs">
                    {line.warnings.length > 0 ? (
                        <CircleAlert
                            role="img"
                            aria-label="Warning"
                            className="text-warn-ink mt-px size-4 shrink-0"
                        />
                    ) : (
                        <CheckCircle2
                            role="img"
                            aria-label="Passed"
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
                    <button
                        type="button"
                        data-test="search-checks-link"
                        onClick={onGoToChecks}
                        className="text-accent-ink focus-visible:ring-ring min-h-6 rounded-sm text-xs underline outline-none focus-visible:ring-2"
                    >
                        Every check is in the side panel.
                    </button>
                ) : (
                    <p className="text-ink-2 text-xs">
                        Every check is in the side panel.
                    </p>
                )}
            </div>
            {/* Later (I-286): the SEO provider's panel, its keyphrase and score, goes here, after core's checks: a plugin declares it and core draws it (phase 5). */}
            <div
                id="editor-search-fields"
                hidden={!open}
                className="border-line grid gap-4 border-t pt-4"
            >
                <div className="grid grid-cols-[minmax(0,1fr)] gap-x-8 gap-y-5 @2xl:grid-cols-2">
                    <div className="grid content-start gap-3">
                        <h3 className="text-[13px] font-semibold">Search</h3>
                        {seoTitle && (
                            <div className="grid gap-2">
                                <Label htmlFor="editor-seo-title">
                                    {seoTitle.label}
                                </Label>
                                <TextControl
                                    field={seoTitle}
                                    value={titleText}
                                    onChange={(next) =>
                                        onChange(seoTitle.key, next)
                                    }
                                    id="editor-seo-title"
                                    describedBy="editor-seo-title-hint editor-seo-title-count"
                                />
                                <p
                                    id="editor-seo-title-hint"
                                    className="text-ink-2 text-xs"
                                >
                                    The title search engines show, exactly as
                                    you type it.{' '}
                                    {home
                                        ? 'Leave empty to use the home page’s title template.'
                                        : `Leave empty to use the title template for ${plural}.`}
                                </p>
                                <Count
                                    id="editor-seo-title-count"
                                    over={titleOver}
                                >
                                    {titleCount}
                                </Count>
                                <MetadataProposal
                                    handle="seo_title"
                                    label={seoTitle.label}
                                    metadata={metadata}
                                />
                            </div>
                        )}
                        {metaDescription && (
                            <div className="grid gap-2">
                                <Label htmlFor="editor-seo-description">
                                    {metaDescription.label}
                                </Label>
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
                                <p
                                    id="editor-seo-description-hint"
                                    className="text-ink-2 text-xs"
                                >
                                    The summary search engines may show under
                                    the title.{' '}
                                    {!hasExcerpt
                                        ? 'Leave empty and search engines choose their own.'
                                        : home
                                          ? 'Leave empty to use the excerpt, or else the site’s tagline.'
                                          : 'Leave empty to use the excerpt.'}
                                </p>
                                <Count
                                    id="editor-seo-description-count"
                                    over={descriptionOver}
                                >
                                    {descriptionCount}
                                </Count>
                                <MetadataProposal
                                    handle="meta_description"
                                    label={metaDescription.label}
                                    metadata={metadata}
                                />
                            </div>
                        )}
                    </div>
                    <div className="grid content-start gap-3">
                        <h3 className="text-[13px] font-semibold">Social</h3>
                        {socialTitle && (
                            <div className="grid gap-2">
                                <Label htmlFor="editor-social-title">
                                    {socialTitle.label}
                                </Label>
                                <TextControl
                                    field={socialTitle}
                                    value={values[socialTitle.key]}
                                    onChange={(next) =>
                                        onChange(socialTitle.key, next)
                                    }
                                    id="editor-social-title"
                                    describedBy="editor-social-title-hint"
                                />
                                <p
                                    id="editor-social-title-hint"
                                    className="text-ink-2 text-xs"
                                >
                                    The title on the card when it’s shared.
                                    Leave empty to use the SEO title, or else
                                    the title.
                                </p>
                                <MetadataProposal
                                    handle="social_title"
                                    label={socialTitle.label}
                                    metadata={metadata}
                                />
                            </div>
                        )}
                        {socialDescription && (
                            <div className="grid gap-2">
                                <Label htmlFor="editor-social-description">
                                    {socialDescription.label}
                                </Label>
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
                                />
                                <p
                                    id="editor-social-description-hint"
                                    className="text-ink-2 text-xs"
                                >
                                    Leave empty to use the meta description, or
                                    else the excerpt.
                                </p>
                                <MetadataProposal
                                    handle="social_description"
                                    label={socialDescription.label}
                                    metadata={metadata}
                                />
                            </div>
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
                    </div>
                </div>
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
                                                          values[noindex.key]
                                                      }
                                                      onChange={(next) =>
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
                                                      className="text-ink-2 text-xs"
                                                  >
                                                      Asks search engines not to
                                                      list this {noun}.
                                                  </p>
                                              </div>
                                          )}
                                          {canonical && (
                                              <CanonicalChoice
                                                  value={values[canonical.key]}
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
                                      values[noindex?.key ?? ''] === true
                                          ? 'Hidden from search engines'
                                          : null,
                                      typeof storedItem === 'string'
                                          ? 'Canonical: another item'
                                          : storedAddress !== ''
                                            ? 'Canonical: another site'
                                            : null,
                                  ].filter(
                                      (part): part is string => part !== null,
                                  ),
                                  holds: [
                                      noindex !== undefined
                                          ? 'Hide from search engines'
                                          : null,
                                      canonical !== undefined
                                          ? 'canonical address'
                                          : null,
                                  ].filter(
                                      (part): part is string => part !== null,
                                  ),
                              }
                            : null
                    }
                />
            </div>
        </section>
    );
}
