import type { Editor } from '@tiptap/core';
import type { Transaction } from '@tiptap/pm/state';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import AiRequestController from '@/actions/App/Http/Admin/Ai/AiRequestController';
import { send } from '@/components/editor/editor-http';
import { showToast } from '@/components/flash-toast';
import type { Answer } from '@/components/editor/editor-http';
import type { EditorAi } from '@/types';
import { blockPath, TEXT_BLOCKS } from './selection-target';
import type { BlockTarget } from './selection-target';

/** One request's place in the pane, from asking to a proposal or a failure. */
export type AssistantRequest = {
    id: string | null;
    task: string;
    scope: string;
    before: string | null;
    state: 'asking' | 'working' | 'ready' | 'using' | 'failed';
    values: Record<string, string>;
    sentTo: string[];
    message: string | null;
    slow: boolean;
};

/** What a task asks with: its key, the scope line, the original, and a text block's target. */
export type AskOptions = {
    task: string;
    scope: string;
    before?: string | null;
    target?: BlockTarget | null;
    options?: Record<string, string | number>;
};

/** What the editor's autosave lends asking and using: save first, adopt what the server wrote, open the stale dialog. */
export type AssistantSaving = {
    checkpoint: () => Promise<string | null>;
    adopt: (revisionId: string) => void;
    markStale: (answer: Record<string, unknown>) => void;
};

type Assistant = {
    request: AssistantRequest | null;
    ask: (options: AskOptions) => Promise<void>;
    use: () => Promise<void>;
    putAside: () => Promise<void>;
};

/** The pane's own sentences, beside the server's. */
export const ASSISTANT_WORDS = {
    notSaved:
        'Your work couldn’t be saved, so nothing was sent. The save state says why.',
    offline:
        'Teme couldn’t reach the server. Check your connection and try again.',
    forbidden: 'You can’t ask AI about this.',
    expired: 'This suggestion has expired. Ask again.',
    unexpected: 'Something went wrong, so nothing was changed. Try again.',
    used: 'Suggestion used as one change.',
    slow: 'Still working. If this keeps happening, ask an administrator to check that the site’s background jobs are running.',
};

/** The hourly limit's sentence, with its singular. */
export function limitSentence(limit: number): string {
    return `You’ve reached this site’s limit of ${limit} AI ${limit === 1 ? 'request' : 'requests'} an hour. Try again later.`;
}

/** After this long working the card adds the queue line; after the hand-off's 15 minutes the request has expired. */
const SLOW_AFTER_MS = 75_000;
const EXPIRES_AFTER_MS = 15 * 60_000;

const Context = createContext<Assistant | null>(null);

/** What the server's refusal of an ask means for the person. */
function refusal(answer: Answer, hourlyLimit: number): string {
    if (answer.status === 429) {
        return limitSentence(hourlyLimit);
    }

    if (answer.status === 403) {
        return ASSISTANT_WORDS.forbidden;
    }

    // The server's own words for people: AI unavailable, or the budget spent.
    if (answer.status === 409 && typeof answer.body.message === 'string') {
        return answer.body.message;
    }

    return ASSISTANT_WORDS.unexpected;
}

/**
 * The editor's one AI request at a time (I-317). Asking saves first, opens
 * the pane and collects the answer by polling; Use saves again, has the
 * server write the assistant's revision, then makes the same change as one
 * step the editor's Undo takes back. The target block's position follows
 * every change between asking and using (I-1305). Anything outside the
 * editor, such as the palette, asks with the window event teme:assistant,
 * whose detail is an AskOptions.
 */
export function AssistantProvider({
    contentId,
    editor,
    saving,
    ai,
    onOpen,
    children,
}: {
    contentId: string;
    editor: Editor | null;
    saving: AssistantSaving;
    ai: EditorAi | null;
    onOpen: () => void;
    children: ReactNode;
}) {
    const [request, setRequest] = useState<AssistantRequest | null>(null);
    const current = useRef<AssistantRequest | null>(null);
    const held = useRef<{ target: BlockTarget; pos: number | null } | null>(
        null,
    );
    const timer = useRef<number | null>(null);

    const show = useCallback((next: AssistantRequest | null) => {
        current.current = next;
        setRequest(next);
    }, []);

    const stopCollecting = useCallback(() => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
    }, []);

    // The target block's position follows every change, or is lost with the block.
    useEffect(() => {
        if (editor === null) {
            return;
        }

        const follow = ({ transaction }: { transaction: Transaction }) => {
            const block = held.current;

            if (
                block === null ||
                block.pos === null ||
                !transaction.docChanged
            ) {
                return;
            }

            const mapped = transaction.mapping.mapResult(block.pos, 1);
            block.pos = mapped.deleted ? null : mapped.pos;
        };

        editor.on('transaction', follow);

        return () => {
            editor.off('transaction', follow);
        };
    }, [editor]);

    useEffect(() => stopCollecting, [stopCollecting]);

    // Every second for ten seconds, then every two, until the answer or a failure.
    const collect = useCallback(
        (id: string, startedAt: number, polls: number) => {
            timer.current = window.setTimeout(
                async () => {
                    timer.current = null;
                    let answer: Answer;

                    try {
                        answer = await send(AiRequestController.show(id));
                    } catch {
                        // Offline for a moment: ask again.
                        collect(id, startedAt, polls + 1);

                        return;
                    }

                    const was = current.current;

                    // Put aside, or replaced by another request, meanwhile.
                    if (was === null || was.id !== id) {
                        return;
                    }

                    const sentTo = Array.isArray(answer.body.sentTo)
                        ? (answer.body.sentTo as string[])
                        : was.sentTo;
                    const waited = Date.now() - startedAt;

                    if (answer.status === 404 || waited > EXPIRES_AFTER_MS) {
                        show({
                            ...was,
                            state: 'failed',
                            message: ASSISTANT_WORDS.expired,
                        });
                    } else if (answer.body.state === 'ready') {
                        show({
                            ...was,
                            state: 'ready',
                            values: answer.body.values as Record<
                                string,
                                string
                            >,
                            sentTo,
                            slow: false,
                        });
                    } else if (answer.body.state === 'failed') {
                        show({
                            ...was,
                            state: 'failed',
                            message:
                                typeof answer.body.message === 'string'
                                    ? answer.body.message
                                    : ASSISTANT_WORDS.unexpected,
                            sentTo,
                            slow: false,
                        });
                    } else {
                        show({ ...was, sentTo, slow: waited > SLOW_AFTER_MS });
                        collect(id, startedAt, polls + 1);
                    }
                },
                polls < 10 ? 1000 : 2000,
            );
        },
        [show],
    );

    // Cancel, Discard and Dismiss alike: the server cancels or puts it aside.
    const putAside = useCallback(async () => {
        const was = current.current;
        stopCollecting();
        held.current = null;

        if (was !== null && was.id !== null) {
            try {
                await send(AiRequestController.destroy(was.id));
            } catch {
                // Offline: the server forgets it within 15 minutes anyway.
            }
        }

        show(null);
    }, [show, stopCollecting]);

    const ask = useCallback(
        async (options: AskOptions) => {
            if (current.current !== null) {
                await putAside();
            }

            onOpen();

            const asked: AssistantRequest = {
                id: null,
                task: options.task,
                scope: options.scope,
                before: options.before ?? null,
                state: 'asking',
                values: {},
                sentTo: [],
                message: null,
                slow: false,
            };
            show(asked);
            held.current = options.target
                ? { target: options.target, pos: options.target.pos }
                : null;

            const revisionId = await saving.checkpoint();

            if (current.current !== asked) {
                return;
            }

            const block = held.current;

            if (
                revisionId === null ||
                (options.target &&
                    (block === null || block.pos === null || editor === null))
            ) {
                show({
                    ...asked,
                    state: 'failed',
                    message:
                        revisionId === null
                            ? ASSISTANT_WORDS.notSaved
                            : ASSISTANT_WORDS.unexpected,
                });

                return;
            }

            let answer: Answer;

            try {
                answer = await send(AiRequestController.store(), {
                    task: options.task,
                    subject_type: 'content',
                    subject_id: contentId,
                    revision_id: revisionId,
                    target:
                        block !== null && block.pos !== null && editor !== null
                            ? blockPath(editor.state.doc, block.pos)
                            : null,
                    options: {
                        ...(options.target
                            ? {
                                  from: options.target.from,
                                  to: options.target.to,
                              }
                            : {}),
                        ...options.options,
                    },
                });
            } catch {
                show({
                    ...asked,
                    state: 'failed',
                    message: ASSISTANT_WORDS.offline,
                });

                return;
            }

            // Put aside while it was sent: cancel it quietly.
            if (current.current !== asked) {
                if (answer.status === 202) {
                    void send(
                        AiRequestController.destroy(String(answer.body.id)),
                    ).catch(() => undefined);
                }

                return;
            }

            if (answer.status !== 202) {
                show({
                    ...asked,
                    state: 'failed',
                    message: refusal(answer, ai?.hourlyLimit ?? 60),
                });

                return;
            }

            const id = String(answer.body.id);
            show({ ...asked, id, state: 'working' });
            collect(id, Date.now(), 0);
        },
        [ai, collect, contentId, editor, onOpen, putAside, saving, show],
    );

    const use = useCallback(async () => {
        const was = current.current;

        if (
            editor === null ||
            was === null ||
            was.id === null ||
            was.state !== 'ready'
        ) {
            return;
        }

        const id = was.id;
        show({ ...was, state: 'using', message: null });
        // No update event: locking the editor isn't a change to save.
        editor.setEditable(false, false);

        // The change, made but not dispatched: the block where it is now, its asked range replaced.
        const change = () => {
            const block = held.current;
            const node =
                block === null || block.pos === null
                    ? null
                    : editor.state.doc.nodeAt(block.pos);

            if (
                block === null ||
                block.pos === null ||
                node === null ||
                !TEXT_BLOCKS.includes(node.type.name)
            ) {
                return null;
            }

            const start = block.pos + 1;

            return {
                pos: block.pos,
                tr: editor.state.tr.insertText(
                    was.values.text ?? '',
                    start + block.target.from,
                    start + block.target.to,
                ),
            };
        };

        try {
            const revisionId = await saving.checkpoint();

            if (revisionId === null) {
                show({
                    ...was,
                    state: 'failed',
                    message: ASSISTANT_WORDS.notSaved,
                });

                return;
            }

            const made = change();
            const answer = await send(AiRequestController.apply(id), {
                revision_id: revisionId,
                path:
                    made === null
                        ? null
                        : blockPath(editor.state.doc, made.pos),
                block:
                    made === null
                        ? null
                        : (made.tr.doc.nodeAt(made.pos)?.toJSON() ?? null),
            });

            if (answer.status === 200) {
                const again = change();

                if (again !== null) {
                    // The server's revision is the base, and this change is in it.
                    saving.adopt(String(answer.body.revisionId));
                    editor.view.dispatch(again.tr);
                }

                held.current = null;
                show(null);
                usedToast(() => editor.chain().focus().undo().run());

                return;
            }

            if (answer.status === 409 && answer.body.stale === true) {
                // Saved elsewhere since: the editor's own stale dialog.
                saving.markStale(answer.body);
                held.current = null;
                show(null);

                return;
            }

            show({
                ...was,
                state: 'failed',
                message:
                    answer.status === 409 &&
                    typeof answer.body.message === 'string'
                        ? answer.body.message
                        : ASSISTANT_WORDS.unexpected,
            });
        } catch {
            show({ ...was, message: ASSISTANT_WORDS.offline });
        } finally {
            editor.setEditable(true, false);
        }
    }, [editor, saving, show]);

    // The palette, outside the editor, asks through an event (I-1318).
    useEffect(() => {
        const asked = (event: Event) => {
            const detail = (event as CustomEvent<AskOptions | undefined>)
                .detail;

            if (detail !== undefined && typeof detail.task === 'string') {
                void ask(detail);
            }
        };

        window.addEventListener('teme:assistant', asked);

        return () => window.removeEventListener('teme:assistant', asked);
    }, [ask]);

    const value = useMemo(
        () => ({ request, ask, use, putAside }),
        [request, ask, use, putAside],
    );

    return <Context.Provider value={value}>{children}</Context.Provider>;
}

/** The editor's assistant: its request, and asking, using and putting aside. */
export function useAssistant(): Assistant {
    const assistant = useContext(Context);

    if (assistant === null) {
        throw new Error('useAssistant() needs an AssistantProvider around it.');
    }

    return assistant;
}

/** "Suggestion used as one change.", with Undo, as every toast is drawn. */
function usedToast(undo: () => void): void {
    showToast({
        type: 'success',
        message: ASSISTANT_WORDS.used,
        action: { label: 'Undo', onClick: undo },
    });
}
