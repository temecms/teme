import type { Editor } from '@tiptap/core';
import { useEditorState } from '@tiptap/react';
import { Button } from '@/components/ui/button';
import { __, __n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { RailSection } from './rail-section';

/** Words a person reads in a minute, for "time to read". */
const WORDS_PER_MINUTE = 230;

type Heading = { level: number; text: string; pos: number; skipped: boolean };

export type Overview = {
    characters: number;
    words: number;
    minutes: number;
    headings: Heading[];
    paragraphs: number;
    images: number;
    links: number;
};

/**
 * Counts and the outline of what is in the editor now; nothing is stored.
 * A card whose text is written on it, as a callout's, counts with the text.
 */
export function overviewOf(editor: Editor): Overview {
    const cards: string[] = [];

    editor.state.doc.descendants((node) => {
        if (node.isAtom && typeof node.attrs.text === 'string') {
            cards.push(node.attrs.text);
        }

        return true;
    });

    const text = [editor.getText({ blockSeparator: '\n' }), ...cards].join(
        '\n',
    );
    const words = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
    const headings: Heading[] = [];
    let paragraphs = 0;
    let images = 0;
    let links = 0;
    let previous = 1;

    editor.state.doc.descendants((node, pos) => {
        if (node.type.name === 'heading') {
            const level = Number(node.attrs.level);
            headings.push({
                level,
                text: node.textContent,
                pos,
                skipped: level > previous + 1,
            });
            previous = level;
        } else if (node.type.name === 'paragraph' && node.textContent !== '') {
            paragraphs += 1;
        } else if (node.type.name === 'image') {
            images += 1;
        } else if (node.type.name === 'gallery') {
            images += Array.isArray(node.attrs.mediaIds)
                ? node.attrs.mediaIds.length
                : 0;
        }

        if (
            node.isText &&
            node.marks.some((mark) => mark.type.name === 'link')
        ) {
            links += 1;
        }

        return true;
    });

    return {
        characters: text.replace(/\n/g, '').length,
        words,
        minutes: Math.max(1, Math.ceil(words / WORDS_PER_MINUTE)),
        headings,
        paragraphs,
        images,
        links,
    };
}

/**
 * Words, characters and reading time, under the body
 * (docs/architecture/design-system.md, 4.39), each number first and in
 * bold: "301 words  1,695 characters  1 min read".
 */
export function WordCount({ editor }: { editor: Editor }) {
    const overview = useEditorState({
        editor,
        selector: ({ editor: current }) => overviewOf(current),
    });
    const counts: [number, string][] = [
        [overview.words, __n('word|words', overview.words)],
        [overview.characters, __n('character|characters', overview.characters)],
        [overview.minutes, __('min read')],
    ];

    return (
        <p
            data-test="body-counts"
            className="text-ink-3 text-small mt-8 flex flex-wrap gap-x-4 gap-y-1 pt-4"
        >
            {counts.map(([value, label]) => (
                <span key={label}>
                    <strong className="text-ink-2 font-medium tabular-nums">
                        {value.toLocaleString()}
                    </strong>{' '}
                    {label}
                </span>
            ))}
        </p>
    );
}

/** The heading the cursor is under: the last one at or before it. */
function currentHeading(editor: Editor, headings: Heading[]): number | null {
    const at = editor.state.selection.from;
    let here: number | null = null;

    for (const heading of headings) {
        if (heading.pos <= at) {
            here = heading.pos;
        }
    }

    return here;
}

/**
 * The rail's Outline section (docs/architecture/design-system.md, 4.39):
 * the body's headings, the one the cursor is under marked; choosing one puts
 * the cursor there. Its summary counts them: "6 headings".
 */
export function OutlineSection({ editor }: { editor: Editor }) {
    const state = useEditorState({
        editor,
        selector: ({ editor: current }) => {
            const { headings } = overviewOf(current);

            return { headings, here: currentHeading(current, headings) };
        },
    });

    return (
        <RailSection
            title={__('Outline')}
            testId="rail-outline"
            summary={__n(
                ':count heading|:count headings',
                state.headings.length,
            )}
        >
            {state.headings.length === 0 ? (
                <p className="text-ink-3 text-small">
                    {__(
                        'Headings you add appear here, so you can jump between sections.',
                    )}
                </p>
            ) : (
                <ul className="grid gap-0.5">
                    {state.headings.map((heading) => (
                        <li key={heading.pos}>
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                aria-current={
                                    heading.pos === state.here
                                        ? 'location'
                                        : undefined
                                }
                                onClick={() =>
                                    editor
                                        .chain()
                                        .focus()
                                        .setTextSelection(heading.pos + 1)
                                        .scrollIntoView()
                                        .run()
                                }
                                className={cn(
                                    'text-small text-ink-2 aria-[current=location]:text-accent-ink h-auto min-h-7 w-full justify-start gap-2 rounded-sm py-1 text-start font-normal whitespace-normal aria-[current=location]:font-medium',
                                    heading.level > 2 && 'ps-6',
                                )}
                            >
                                {heading.pos === state.here && (
                                    <span
                                        aria-hidden
                                        className="bg-accent -ms-1 w-0.5 self-stretch rounded-full"
                                    />
                                )}
                                {heading.text || __('Untitled heading')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </RailSection>
    );
}

/**
 * The document overview pane (I-232): counts, and an outline whose entries
 * move the cursor to their heading and flag a skipped level.
 */
export function DocumentOverview({ editor }: { editor: Editor }) {
    const overview = useEditorState({
        editor,
        selector: ({ editor: current }) => overviewOf(current),
    });

    const counts: [string, number][] = [
        ['Characters', overview.characters],
        ['Words', overview.words],
        ['Minutes to read', overview.minutes],
        ['Headings', overview.headings.length],
        ['Paragraphs', overview.paragraphs],
        ['Images', overview.images],
        ['Links', overview.links],
    ];

    return (
        <div className="grid gap-4 p-4">
            <dl className="text-small grid grid-cols-[1fr_auto] gap-x-4 gap-y-2">
                {counts.map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-ink-2">{label}</dt>
                        <dd className="text-right tabular-nums">
                            {value.toLocaleString()}
                        </dd>
                    </div>
                ))}
            </dl>
            <section aria-labelledby="overview-outline" className="grid gap-2">
                <h3 id="overview-outline" className="text-small font-semibold">
                    {__('Outline')}
                </h3>
                {overview.headings.length === 0 ? (
                    <p className="text-ink-2 text-small">
                        {__('No headings yet.')}
                    </p>
                ) : (
                    <ul className="text-small grid gap-0.5">
                        {overview.headings.map((heading) => (
                            <li
                                key={heading.pos}
                                style={{
                                    paddingLeft: (heading.level - 2) * 12,
                                }}
                            >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    onClick={() =>
                                        editor
                                            .chain()
                                            .focus()
                                            .setTextSelection(heading.pos + 1)
                                            .scrollIntoView()
                                            .run()
                                    }
                                    className="text-ink h-auto min-h-0 w-full justify-start rounded-sm py-1 text-left font-normal whitespace-normal"
                                >
                                    {heading.text || __('Untitled heading')}
                                    {heading.skipped && (
                                        <span className="text-warn-ink text-caption ml-2">
                                            {__('skips a level')}
                                        </span>
                                    )}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
