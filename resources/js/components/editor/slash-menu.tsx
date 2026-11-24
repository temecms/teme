// Later (I-200): extensions contribute entries to this list in phase 5,
// drawn by core under "From plugins", so they look like the rest of the
// editor.
import type { Editor } from '@tiptap/core';
import { useEditorState } from '@tiptap/react';
import {
    CircleHelp,
    Code,
    Footprints,
    Globe,
    Heading2,
    Heading3,
    Heading4,
    Image,
    Images,
    List,
    ListChecks,
    ListOrdered,
    MessageSquare,
    Minus,
    MousePointerClick,
    Music,
    Pilcrow,
    Quote,
    Square,
    Table,
    Video,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { CSSProperties, KeyboardEvent as ReactKeyboardEvent } from 'react';
import { matchesEveryWord } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Kbd } from '@/components/ui/kbd';
import { ScrollArea } from '@/components/ui/scroll-area';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { CardManifestEntry, PluginCardEntry } from '@/types';
import { cardEntries, pluginCardEntries } from './insert-entries';
import type { EditorContext, RegionEntry } from './region-entry';
import { visibleEntries } from './region-entry';

/** A text block the picker offers, with the Markdown it can also be typed as. */
type TextEntry = RegionEntry & { keys?: string };

/** The text blocks the picker offers beside the cards, looked up while drawing. */
function textEntries(): TextEntry[] {
    return [
        {
            id: 'paragraph',
            owner: 'core',
            label: __('Paragraph'),
            icon: 'paragraph',
            group: 'text',
            order: 10,
            keywords: ['text'],
            run: ({ editor }) => editor.chain().focus().setParagraph().run(),
        },
        {
            id: 'heading-2',
            owner: 'core',
            label: __('Heading'),
            icon: 'heading-2',
            group: 'text',
            order: 20,
            keys: '##',
            keywords: ['h2', 'title', 'section'],
            run: ({ editor }) =>
                editor.chain().focus().setHeading({ level: 2 }).run(),
        },
        {
            id: 'heading-3',
            owner: 'core',
            label: __('Subheading'),
            icon: 'heading-3',
            group: 'text',
            order: 30,
            keys: '###',
            keywords: ['h3', 'heading'],
            run: ({ editor }) =>
                editor.chain().focus().setHeading({ level: 3 }).run(),
        },
        {
            id: 'heading-4',
            owner: 'core',
            label: __('Heading 4'),
            icon: 'heading-4',
            group: 'text',
            order: 40,
            keys: '####',
            keywords: ['h4'],
            run: ({ editor }) =>
                editor.chain().focus().setHeading({ level: 4 }).run(),
        },
        {
            id: 'bulletList',
            owner: 'core',
            label: __('Bulleted list'),
            icon: 'bulletList',
            group: 'text',
            order: 50,
            keys: '-',
            keywords: ['list', 'bullets', 'ul'],
            run: ({ editor }) =>
                editor.chain().focus().toggleBulletList().run(),
        },
        {
            id: 'orderedList',
            owner: 'core',
            label: __('Numbered list'),
            icon: 'orderedList',
            group: 'text',
            order: 60,
            keys: '1.',
            keywords: ['list', 'numbers', 'ol'],
            run: ({ editor }) =>
                editor.chain().focus().toggleOrderedList().run(),
        },
        {
            id: 'taskList',
            owner: 'core',
            label: __('Task list'),
            icon: 'taskList',
            group: 'text',
            order: 70,
            keys: '[ ]',
            keywords: ['todo', 'checklist', 'tasks'],
            run: ({ editor }) => editor.chain().focus().toggleTaskList().run(),
        },
        {
            id: 'blockquote',
            owner: 'core',
            label: __('Quote'),
            icon: 'blockquote',
            group: 'text',
            order: 80,
            keys: '>',
            keywords: ['blockquote', 'quotation'],
            run: ({ editor }) =>
                editor.chain().focus().toggleBlockquote().run(),
        },
        {
            id: 'codeBlock',
            owner: 'core',
            label: __('Code block'),
            icon: 'codeBlock',
            group: 'text',
            order: 90,
            keys: '```',
            keywords: ['code', 'pre'],
            run: ({ editor }) => editor.chain().focus().setCodeBlock().run(),
        },
        {
            id: 'table',
            owner: 'core',
            label: __('Table'),
            icon: 'table',
            group: 'text',
            order: 95,
            keywords: ['grid', 'rows', 'columns'],
            run: ({ editor }) =>
                editor
                    .chain()
                    .focus()
                    .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                    .run(),
        },
        {
            id: 'horizontalRule',
            owner: 'core',
            label: __('Horizontal rule'),
            description: __(
                'A line across the column, between two parts of the text.',
            ),
            icon: 'horizontalRule',
            group: 'text',
            order: 99,
            keys: '---',
            keywords: ['rule', 'divider', 'line', 'hr', 'separator'],
            run: ({ editor }) =>
                editor
                    .chain()
                    .focus()
                    .insertContent({ type: 'horizontalRule' })
                    .run(),
        },
    ];
}

const ICONS: Record<string, LucideIcon> = {
    paragraph: Pilcrow,
    'heading-2': Heading2,
    'heading-3': Heading3,
    'heading-4': Heading4,
    bulletList: List,
    orderedList: ListOrdered,
    taskList: ListChecks,
    blockquote: Quote,
    codeBlock: Code,
    table: Table,
    horizontalRule: Minus,
    image: Image,
    gallery: Images,
    audio: Music,
    video: Video,
    embed: Globe,
    callout: MessageSquare,
    button: MousePointerClick,
    code: Code,
    faq: CircleHelp,
    steps: Footprints,
};

/** The cards that hold a picture, a sound or a film, which the picker lists under Media. */
const MEDIA = new Set(['image', 'gallery', 'audio', 'video', 'embed']);

/** The picker's groups, in order, with their headings, looked up while drawing. */
function groups(): { id: string; heading: string }[] {
    return [
        { id: 'text', heading: __('Text') },
        { id: 'media', heading: __('Media') },
        { id: 'cards', heading: __('Cards') },
        { id: 'plugins', heading: __('From plugins') },
    ];
}

function groupOf(entry: RegionEntry): string {
    if (entry.owner !== 'core') {
        return 'plugins';
    }

    if (entry.group === 'text') {
        return 'text';
    }

    return MEDIA.has(entry.id.replace(/^card:/, '')) ? 'media' : 'cards';
}

/** The slash typed on an empty line, and what follows it, or null. */
export function slashAt(
    editor: Editor,
): { query: string; from: number; to: number } | null {
    const { selection } = editor.state;

    if (!selection.empty) {
        return null;
    }

    const { $from } = selection;

    if ($from.parent.type.name !== 'paragraph') {
        return null;
    }

    const text = $from.parent.textContent;
    const match = /^\/([\w-]*)$/.exec(text);

    if (match === null || $from.parentOffset !== text.length) {
        return null;
    }

    return { query: match[1], from: $from.start(), to: $from.end() };
}

/**
 * What opened the picker, other than "/": the handle's "+" (after a block),
 * the "+" between two blocks (here), or the toolbar's Insert (the cursor).
 * `at` is where a new line goes; `anchor` is what the panel hangs from.
 */
export type PickerRequest = {
    from: 'after' | 'here' | 'cursor';
    at?: number;
    name?: string;
    anchor: DOMRect;
    opener: HTMLElement | null;
};

/** The panel's place: under the anchor, or above it when the room below is short. */
function placed(anchor: DOMRect): CSSProperties {
    const room = window.innerHeight - anchor.bottom;
    const left = Math.max(8, Math.min(anchor.left, window.innerWidth - 408));

    return (
        room > 320
            ? {
                  '--picker-x': `${left}px`,
                  '--picker-y': `${anchor.bottom + 6}px`,
              }
            : {
                  '--picker-x': `${left}px`,
                  '--picker-y': `${window.innerHeight - anchor.top + 6}px`,
              }
    ) as CSSProperties;
}

/**
 * The one block picker (docs/architecture/design-system.md, 4.39): "+"
 * after a block, "+" between blocks, "/" on an empty line and the toolbar's
 * Insert all open it. Its search, or the text typed after "/", filters by
 * every word over the name, the description and the keywords; ↑ ↓ Home End
 * move, Enter inserts, Esc closes and returns focus to what opened it.
 */
export function BlockPicker({
    editor,
    cards,
    pluginCards = [],
    context,
    request,
    onClose,
}: {
    editor: Editor;
    cards: CardManifestEntry[];
    /** The plugins' cards, offered in a theme's region (docs/guides/developers/plugins/cards.md). */
    pluginCards?: PluginCardEntry[];
    context: EditorContext;
    request: PickerRequest | null;
    onClose: () => void;
}) {
    const slash = useEditorState({
        editor,
        selector: ({ editor: current }) => slashAt(current),
    });
    const [dismissed, setDismissed] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [active, setActive] = useState(0);
    const panel = useRef<HTMLDivElement>(null);
    const id = useId();
    const listId = `${id}-list`;
    const headId = `${id}-head`;

    const bySlash =
        request === null && slash !== null && dismissed !== slash.from;
    const open = request !== null || bySlash;
    const query = bySlash ? slash.query : search;

    useEffect(() => {
        if (slash === null) {
            setDismissed(null);
        }
    }, [slash]);

    useEffect(() => setSearch(''), [request]);
    useEffect(() => setActive(0), [query, request]);

    const shown = useMemo(() => {
        if (!open) {
            return [];
        }

        const all = visibleEntries(
            [
                ...textEntries(),
                ...cardEntries(cards),
                ...pluginCardEntries(pluginCards),
            ],
            context,
        ).filter((entry) =>
            matchesEveryWord(
                {
                    value: entry.id,
                    label: entry.label,
                    keywords: [
                        entry.description ?? '',
                        ...(entry.keywords ?? []),
                    ],
                },
                query,
            ),
        );

        // In the groups' order, each group in the list's own order.
        return groups().flatMap((group) =>
            all.filter((entry) => groupOf(entry) === group.id),
        );
    }, [open, cards, pluginCards, context, query]);

    const close = useCallback(
        (returnFocus: boolean) => {
            if (bySlash && slash !== null) {
                setDismissed(slash.from);
            }

            onClose();

            if (returnFocus) {
                const opener = request?.opener ?? null;

                if (
                    opener !== null &&
                    opener.isConnected &&
                    opener.checkVisibility()
                ) {
                    opener.focus();
                } else {
                    editor.commands.focus();
                }
            }
        },
        [bySlash, slash, onClose, request, editor],
    );

    const choose = useCallback(
        (entry: RegionEntry) => {
            if (bySlash && slash !== null) {
                editor
                    .chain()
                    .focus()
                    .deleteRange({ from: slash.from, to: slash.to })
                    .run();
            } else if (request?.at !== undefined) {
                // A new line where the picker was opened, and the choice made of it.
                editor
                    .chain()
                    .insertContentAt(request.at, { type: 'paragraph' })
                    .setTextSelection(request.at + 1)
                    .focus()
                    .run();
            } else {
                editor.commands.focus();
            }

            onClose();
            entry.run(context);
        },
        [bySlash, slash, request, editor, onClose, context],
    );

    const keys = useCallback(
        (event: KeyboardEvent | ReactKeyboardEvent): boolean => {
            const count = shown.length;

            if (event.key === 'ArrowDown' && count > 0) {
                setActive((index) => (index + 1) % count);
            } else if (event.key === 'ArrowUp' && count > 0) {
                setActive((index) => (index - 1 + count) % count);
            } else if (event.key === 'Home' && count > 0 && !bySlash) {
                setActive(0);
            } else if (event.key === 'End' && count > 0 && !bySlash) {
                setActive(count - 1);
            } else if (event.key === 'Enter') {
                if (count > 0) {
                    choose(shown[Math.min(active, count - 1)]);
                }
            } else if (event.key === 'Escape') {
                close(true);
            } else {
                return false;
            }

            event.preventDefault();
            event.stopPropagation();

            return true;
        },
        [shown, bySlash, active, choose, close],
    );

    // From "/", the text keeps focus: its keys drive the list.
    useEffect(() => {
        if (!bySlash) {
            return;
        }

        const dom = editor.view.dom;
        const listen = (event: KeyboardEvent) => {
            keys(event);
        };

        dom.addEventListener('keydown', listen, true);

        return () => dom.removeEventListener('keydown', listen, true);
    }, [bySlash, keys, editor]);

    // A click outside closes it.
    useEffect(() => {
        if (request === null) {
            return;
        }

        const away = (event: PointerEvent) => {
            if (!panel.current?.contains(event.target as Node)) {
                onClose();
            }
        };

        document.addEventListener('pointerdown', away, true);

        return () => document.removeEventListener('pointerdown', away, true);
    }, [request, onClose]);

    // The highlighted option stays in view.
    useLayoutEffect(() => {
        document
            .getElementById(`${id}-option-${active}`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [active, id, shown]);

    if (!open) {
        return null;
    }

    const anchor =
        request?.anchor ??
        (() => {
            const at = editor.view.coordsAtPos(slash!.from);

            return new DOMRect(at.left, at.top, 0, at.bottom - at.top);
        })();
    const below = window.innerHeight - anchor.bottom > 320;
    const head =
        request?.from === 'after'
            ? __('Insert after: :name', { name: request.name ?? '' })
            : request?.from === 'here'
              ? __('Insert here')
              : request?.from === 'cursor'
                ? __('Insert at the cursor')
                : query === ''
                  ? __('Turn this empty line into…')
                  : __('Filtering by “:text”: keep typing to narrow', {
                        text: `/${query}`,
                    });
    const activeId = shown.length > 0 ? `${id}-option-${active}` : undefined;
    let index = -1;

    return (
        <div
            ref={panel}
            data-test="block-picker"
            className={cn(
                'bg-surface text-ink shadow-float fixed left-(--picker-x) z-(--z-float) flex max-h-120 w-100 flex-col overflow-hidden rounded-lg',
                below ? 'top-(--picker-y)' : 'bottom-(--picker-y)',
            )}
            style={placed(anchor)}
        >
            <p id={headId} className="text-caption text-ink-3 px-3 pt-3 pb-2">
                {head}
            </p>
            {!bySlash && (
                <div className="px-2 pb-2">
                    <Input
                        autoFocus
                        role="combobox"
                        aria-expanded="true"
                        aria-controls={listId}
                        aria-autocomplete="list"
                        aria-activedescendant={activeId}
                        aria-labelledby={headId}
                        placeholder={__('Search')}
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) => keys(event)}
                    />
                </div>
            )}
            <ScrollArea className="min-h-0 flex-1 px-1 pb-1">
                <div role="listbox" id={listId} aria-labelledby={headId}>
                    {groups().map((group) => {
                        const inGroup = shown.filter(
                            (entry) => groupOf(entry) === group.id,
                        );

                        if (inGroup.length === 0) {
                            return null;
                        }

                        return (
                            <div
                                key={group.id}
                                role="group"
                                aria-labelledby={`${id}-${group.id}`}
                            >
                                <p
                                    id={`${id}-${group.id}`}
                                    className="text-caption text-ink-3 px-2 pt-2 pb-1 font-semibold uppercase"
                                >
                                    {group.heading}
                                </p>
                                {inGroup.map((entry) => {
                                    index += 1;
                                    const at = index;
                                    const Icon =
                                        ICONS[entry.icon ?? ''] ?? Square;
                                    const typed = (entry as TextEntry).keys;

                                    return (
                                        <div
                                            key={entry.id}
                                            id={`${id}-option-${at}`}
                                            role="option"
                                            aria-selected={at === active}
                                            onMouseDown={(event) => {
                                                event.preventDefault();
                                                choose(entry);
                                            }}
                                            onMouseMove={() => setActive(at)}
                                            className="aria-selected:bg-surface-3 flex min-h-11 cursor-default items-center gap-3 rounded-md px-2"
                                        >
                                            <span className="bg-surface-2 text-ink-2 grid size-8 shrink-0 place-items-center rounded-md">
                                                <Icon className="size-4" />
                                            </span>
                                            <span className="grid min-w-0 flex-1">
                                                <span className="text-body truncate font-medium">
                                                    {entry.label}
                                                </span>
                                                {entry.description && (
                                                    <span className="text-small text-ink-3 truncate">
                                                        {entry.description}
                                                    </span>
                                                )}
                                            </span>
                                            {typed !== undefined && (
                                                <Kbd>{typed}</Kbd>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })}
                </div>
                {shown.length === 0 && (
                    <p className="text-small text-ink-3 px-2 py-3">
                        {__('No block matches. Esc closes this.')}
                    </p>
                )}
            </ScrollArea>
            <p className="bg-surface-2 text-caption text-ink-3 px-3 py-2">
                {__('↑ ↓ move · Enter insert · Esc close')}
            </p>
        </div>
    );
}
