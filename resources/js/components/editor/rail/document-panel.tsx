import type { Editor } from '@tiptap/core';
import { useEditorState } from '@tiptap/react';
import { __ } from '@/lib/i18n';

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

/** Counts and the outline of what is in the editor now; nothing is stored. */
export function overviewOf(editor: Editor): Overview {
    const text = editor.getText({ blockSeparator: '\n' });
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

/** Words, characters and reading time, under the body (docs/architecture/design-system.md, 4.39). */
export function WordCount({ editor }: { editor: Editor }) {
    const overview = useEditorState({
        editor,
        selector: ({ editor: current }) => overviewOf(current),
    });
    const counts: [string, string][] = [
        [__('Words'), overview.words.toLocaleString()],
        [__('Characters'), overview.characters.toLocaleString()],
        [__('Reading time'), __(':count min', { count: overview.minutes })],
    ];

    return (
        <dl
            data-test="body-counts"
            className="text-ink-3 text-small mt-8 flex flex-wrap gap-x-6 gap-y-1 px-4 py-4 md:px-16"
        >
            {counts.map(([label, value]) => (
                <div key={label} className="flex gap-2">
                    <dt>{label}</dt>
                    <dd className="text-ink-2 font-medium tabular-nums">
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
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
            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-[13px]">
                {counts.map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-ink-2">{label}</dt>
                        <dd className="text-right tabular-nums">
                            {value.toLocaleString()}
                        </dd>
                    </div>
                ))}
            </dl>
            <section
                aria-labelledby="overview-outline"
                className="grid gap-1.5"
            >
                <h3 id="overview-outline" className="text-[13px] font-semibold">
                    Outline
                </h3>
                {overview.headings.length === 0 ? (
                    <p className="text-ink-2 text-[13px]">No headings yet.</p>
                ) : (
                    <ul className="grid gap-0.5 text-[13px]">
                        {overview.headings.map((heading) => (
                            <li
                                key={heading.pos}
                                style={{
                                    paddingLeft: (heading.level - 2) * 12,
                                }}
                            >
                                <button
                                    type="button"
                                    onClick={() =>
                                        editor
                                            .chain()
                                            .focus()
                                            .setTextSelection(heading.pos + 1)
                                            .scrollIntoView()
                                            .run()
                                    }
                                    className="hover:bg-surface-3 focus-visible:ring-ring w-full rounded-sm px-1.5 py-1 text-left outline-none focus-visible:ring-2"
                                >
                                    {heading.text || 'Untitled heading'}
                                    {heading.skipped && (
                                        <span className="text-warn-ink ml-1.5 text-[11px]">
                                            skips a level
                                        </span>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
