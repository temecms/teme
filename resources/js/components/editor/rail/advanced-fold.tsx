import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { BooleanControl } from '@/components/editor/fields/boolean-control';
import { NumberControl } from '@/components/editor/fields/number-control';
import { SelectControl } from '@/components/editor/fields/select-control';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import type { EditorField, EditorSeo } from '@/types/editor';

/** The article type that describes no article (TypeSchemas::NONE). */
const NONE = 'none';

/** A select field's options as value and label, the empty choice left out. */
function optionsOf(field: EditorField): { value: string; label: string }[] {
    const entries = Array.isArray(field.options)
        ? field.options.map((option) => [option, option])
        : Object.entries(field.options);

    return entries
        .filter(([value]) => value !== '')
        .map(([value, label]) => ({ value, label }));
}

/** The label of a value among a field's options, or the value as written. */
function labelOf(field: EditorField, value: string): string {
    return (
        optionsOf(field).find((option) => option.value === value)?.label ??
        value
    );
}

/** A whole number from 0, or null for none. */
function lengthOf(value: unknown): number | null {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0
        ? value
        : null;
}

/** One box and its line of help, as Search and social's Hide row draws it. */
function BoxRow({
    id,
    field,
    checked,
    onChange,
}: {
    id: string;
    field: EditorField;
    checked: boolean;
    onChange: (next: boolean) => void;
}) {
    return (
        <div className="grid gap-1">
            <BooleanControl
                field={field}
                value={checked}
                onChange={(next) => onChange(next === true)}
                id={id}
                describedBy={`${id}-hint`}
            />
            <p id={`${id}-hint`} className="text-ink-2 text-xs">
                {field.description}
            </p>
        </div>
    );
}

/**
 * Hide from search engines, the canonical address, what search engines are
 * told the item is, and how much of it they may quote (I-709, I-714,
 * I-3124), in a fold closed by default at the foot of Search and social
 * (I-879); its trigger names what is set, or what it holds when nothing is,
 * so a choice there is never out of sight. The first two arrive as `leading`.
 */
export function AdvancedFold({
    fields,
    values,
    onChange,
    seo,
    leading,
}: {
    fields: EditorField[];
    values: Record<string, unknown>;
    onChange: (key: string, value: unknown) => void;
    seo: EditorSeo;
    /** Hide and the canonical: their rows, what is set among them, and what they hold. */
    leading: { node: ReactNode; set: string[]; holds: string[] } | null;
}) {
    const field = (handle: string) =>
        fields.find((each) => each.handle === handle);
    const pageType = field('page_type');
    const articleType = field('article_type');
    const nosnippet = field('nosnippet');
    const maxSnippet = field('max_snippet');
    const noarchive = field('noarchive');

    const stringOf = (each: EditorField | undefined): string => {
        const value = each === undefined ? null : values[each.key];

        return typeof value === 'string' ? value : '';
    };
    const pageValue = stringOf(pageType);
    const articleValue = stringOf(articleType);
    const noSnippet = nosnippet !== undefined && values[nosnippet.key] === true;
    const noCache = noarchive !== undefined && values[noarchive.key] === true;
    const length =
        maxSnippet === undefined ? null : lengthOf(values[maxSnippet.key]);

    if (
        leading === null &&
        pageType === undefined &&
        articleType === undefined &&
        nosnippet === undefined &&
        maxSnippet === undefined &&
        noarchive === undefined
    ) {
        return null;
    }

    const offersArticles = seo.schema.mainEntities.length > 0;
    const articleOptions =
        articleType === undefined
            ? []
            : [
                  ...optionsOf(articleType).filter((option) =>
                      seo.schema.mainEntities.includes(option.value),
                  ),
                  { value: NONE, label: labelOf(articleType, NONE) },
              ];

    const set = [
        ...(leading?.set ?? []),
        pageType !== undefined && pageValue !== ''
            ? labelOf(pageType, pageValue)
            : null,
        articleType !== undefined && offersArticles && articleValue !== ''
            ? labelOf(articleType, articleValue)
            : null,
        noSnippet ? 'No snippet' : null,
        !noSnippet && length !== null
            ? `Snippet of ${length} characters`
            : null,
        noCache ? 'No cached copy' : null,
    ].filter((part): part is string => part !== null);
    const kinds =
        pageType !== undefined && articleType !== undefined && offersArticles
            ? 'page and article type'
            : pageType !== undefined
              ? 'page type'
              : articleType !== undefined && offersArticles
                ? 'article type'
                : null;
    const holds = [
        ...(leading?.holds ?? []),
        kinds,
        nosnippet !== undefined ||
        maxSnippet !== undefined ||
        noarchive !== undefined
            ? 'snippet'
            : null,
    ]
        .filter((part): part is string => part !== null)
        .join(', ');
    const summary =
        set.length > 0
            ? set.join(' · ')
            : holds.charAt(0).toUpperCase() + holds.slice(1);

    return (
        <Collapsible className="border-line rounded-md border">
            <CollapsibleTrigger
                id="editor-advanced"
                className="group focus-visible:ring-ring flex min-h-10 w-full items-center gap-3 rounded-md px-3 text-left text-[13px] font-semibold outline-none focus-visible:ring-2"
            >
                Advanced
                <span
                    data-test="advanced-summary"
                    className="text-ink-2 flex-1 text-xs font-normal"
                >
                    {summary}
                </span>
                <ChevronDown
                    aria-hidden
                    className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180"
                />
            </CollapsibleTrigger>
            <CollapsibleContent
                forceMount
                className="grid gap-x-6 gap-y-4 px-3 pb-4 data-[state=closed]:hidden @2xl:grid-cols-2"
            >
                {leading !== null && (
                    <div className="grid content-start gap-4">
                        {leading.node}
                    </div>
                )}
                <div className="grid content-start gap-4">
                    {pageType && (
                        <div className="grid gap-2">
                            <Label htmlFor="editor-page-type">
                                {pageType.label}
                            </Label>
                            <SelectControl
                                field={pageType}
                                value={pageValue}
                                onChange={(next) =>
                                    onChange(pageType.key, next)
                                }
                                id="editor-page-type"
                                describedBy="editor-page-type-hint"
                                emptyLabel={`Default for its type (${labelOf(pageType, seo.schema.pageType)})`}
                                options={optionsOf(pageType)}
                            />
                            <p
                                id="editor-page-type-hint"
                                className="text-ink-2 text-xs"
                            >
                                {pageType.description}
                            </p>
                        </div>
                    )}
                    {articleType && offersArticles && (
                        <div className="grid gap-2">
                            <Label htmlFor="editor-article-type">
                                {articleType.label}
                            </Label>
                            <SelectControl
                                field={articleType}
                                value={articleValue}
                                onChange={(next) =>
                                    onChange(articleType.key, next)
                                }
                                id="editor-article-type"
                                describedBy="editor-article-type-hint"
                                emptyLabel={`Default for its type (${labelOf(articleType, seo.schema.mainEntity ?? NONE)})`}
                                options={articleOptions}
                            />
                            <p
                                id="editor-article-type-hint"
                                className="text-ink-2 text-xs"
                            >
                                {articleType.description}
                            </p>
                        </div>
                    )}
                    {nosnippet && (
                        <BoxRow
                            id="editor-nosnippet"
                            field={nosnippet}
                            checked={noSnippet}
                            onChange={(next) => onChange(nosnippet.key, next)}
                        />
                    )}
                    {maxSnippet && (
                        <div className="grid gap-2">
                            <Label htmlFor="editor-max-snippet">
                                {maxSnippet.label}
                            </Label>
                            <NumberControl
                                field={maxSnippet}
                                value={length}
                                onChange={(next) =>
                                    onChange(maxSnippet.key, next)
                                }
                                id="editor-max-snippet"
                                describedBy="editor-max-snippet-hint"
                                min={0}
                                step={1}
                                placeholder="No limit"
                                disabled={noSnippet}
                            />
                            <p
                                id="editor-max-snippet-hint"
                                className="text-ink-2 text-xs"
                            >
                                {maxSnippet.description}
                                {noSnippet &&
                                    ' Not used while “Don’t show a snippet” is ticked.'}
                            </p>
                        </div>
                    )}
                    {noarchive && (
                        <BoxRow
                            id="editor-noarchive"
                            field={noarchive}
                            checked={noCache}
                            onChange={(next) => onChange(noarchive.key, next)}
                        />
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
