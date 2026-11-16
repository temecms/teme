import { TriangleAlert } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** A language's short mark: "EN", or the whole tag ("EN-GB") where two of the site's languages share the first part. */
export function languageMark(tag: string, all: string[]): string {
    const base = tag.split('-')[0];
    const shared = all.filter((other) => other.split('-')[0] === base);

    return (shared.length > 1 ? tag : base).toUpperCase();
}

/** One of the site's languages, as a list draws its marks. */
export type MarkLanguage = { tag: string; name: string };

/** A row's version in one language: there, missing, or out of date. */
export type MarkState = 'there' | 'missing' | 'outOfDate';

/**
 * A row's language marks (docs/proposals/multilingual-screens.md, 4): one
 * per site language, in the site's order on every row, under the title. A
 * filled mark for a version that exists, an outlined one for a missing
 * one, a warning mark for one out of date. Each says what it means in a
 * tooltip and to screen readers, never by its look alone.
 */
export function LanguageMarks({
    languages,
    states,
    className,
}: {
    languages: MarkLanguage[];
    /** The row's state in each language, by tag; a language not named is missing. */
    states: Record<string, MarkState>;
    className?: string;
}) {
    const tags = languages.map((language) => language.tag);

    return (
        <ul
            data-test="language-marks"
            aria-label={__('Languages')}
            className={cn('flex flex-wrap items-center gap-1', className)}
        >
            {languages.map((language) => {
                const state = states[language.tag] ?? 'missing';
                const words =
                    state === 'there'
                        ? __(':language: translated', {
                              language: language.name,
                          })
                        : state === 'outOfDate'
                          ? __(':language: out of date', {
                                language: language.name,
                            })
                          : __(':language: not translated', {
                                language: language.name,
                            });

                return (
                    <li
                        key={language.tag}
                        data-language={language.tag}
                        data-state={state}
                    >
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span
                                    tabIndex={0}
                                    className={cn(
                                        'text-caption focus-visible:outline-ring inline-flex h-5 items-center gap-1 rounded-sm px-1 font-semibold outline-none focus-visible:outline-2',
                                        state === 'there' &&
                                            'bg-accent-soft text-on-accent-soft border border-transparent',
                                        state === 'missing' &&
                                            'text-ink-3 border-edge border',
                                        state === 'outOfDate' &&
                                            'text-warn-ink border-warn border',
                                    )}
                                >
                                    {state === 'outOfDate' && (
                                        <TriangleAlert
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                    )}
                                    <span aria-hidden="true">
                                        {languageMark(language.tag, tags)}
                                    </span>
                                    <span className="sr-only">{words}</span>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>{words}</TooltipContent>
                        </Tooltip>
                    </li>
                );
            })}
        </ul>
    );
}
