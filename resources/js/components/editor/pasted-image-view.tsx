import type { Editor } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import {
    NodeViewWrapper,
    ReactNodeViewRenderer,
    useEditorState,
} from '@tiptap/react';
import type { ReactNodeViewProps } from '@tiptap/react';
import { useId } from 'react';
import ImportImageController from '@/actions/App/Http/Admin/Media/ImportImageController';
import { Button } from '@/components/ui/button';
import { ulid } from '@/lib/ulid';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useCardEnvironment } from './cards/card-environment';
import { post, reasonOf } from './editor-http';
import type { Answer } from './editor-http';
import {
    PASTED_IMAGE,
    PROBLEM_WORDS,
    PastedImage,
    pastedImageLabel,
    pastedImageProblem,
} from './pasted-images';
import type { PastedImageAttrs, PastedImageState } from './pasted-images';

type Found = { node: ProseMirrorNode; pos: number };

/** Every pasted image in the document, in order, with where it is. */
function pastedImages(editor: Editor): Found[] {
    const found: Found[] = [];

    editor.state.doc.descendants((node, pos) => {
        if (node.type.name === PASTED_IMAGE) {
            found.push({ node, pos });
        }
    });

    return found;
}

/**
 * Sets the state of every pasted image with this address, kept out of the
 * undo history: it records progress, not an edit.
 */
function mark(
    editor: Editor,
    src: string,
    state: PastedImageState,
    message: string | null,
): void {
    if (editor.isDestroyed) {
        return;
    }

    const { tr } = editor.state;

    for (const { node, pos } of pastedImages(editor)) {
        if (node.attrs.src === src) {
            tr.setNodeMarkup(pos, undefined, { ...node.attrs, state, message });
        }
    }

    if (tr.docChanged) {
        editor.view.dispatch(tr.setMeta('addToHistory', false));
    }
}

/**
 * Replaces every pasted image with this address by an Image card for the
 * library's item, each keeping its own pasted alt text and caption, in one
 * step Undo takes back. They wait again first, out of the history, so Undo
 * brings back a pasted image that can be imported, not one still importing.
 */
function replace(editor: Editor, src: string, mediaId: string): void {
    const image = editor.schema.nodes.image;

    if (editor.isDestroyed || image === undefined) {
        return;
    }

    mark(editor, src, 'waiting', null);
    const { tr } = editor.state;

    // From the last, so each earlier position still holds.
    for (const { node, pos } of pastedImages(editor).reverse()) {
        if (node.attrs.src === src) {
            tr.replaceWith(
                pos,
                pos + node.nodeSize,
                image.create({
                    id: ulid(),
                    mediaId,
                    alt: node.attrs.alt as string | null,
                    caption: node.attrs.caption as string | null,
                }),
            );
        }
    }

    editor.view.dispatch(tr);
}

/** Removes every pasted image, in one step Undo takes back. */
function removeAll(editor: Editor): void {
    const { tr } = editor.state;

    for (const { node, pos } of pastedImages(editor).reverse()) {
        tr.delete(pos, pos + node.nodeSize);
    }

    editor.view.dispatch(tr);
}

/** The words for an answer that isn't the image. */
function failure({ status, body }: Answer): string {
    if (status === 422) {
        return reasonOf(body);
    }

    if (status === 403) {
        return 'You can’t add files to the media library. Ask an editor to import this image, or remove it.';
    }

    if (status === 429) {
        return 'Too many images at once. Wait a minute, then try again.';
    }

    return 'Teme couldn’t import this image. Try again.';
}

/**
 * Imports these addresses into the media library (I-453), two at a time and
 * each once, replacing every pasted image that has one by an Image card. A
 * failure stays on its pasted images, with the reason.
 */
async function importPastedImages(
    editor: Editor,
    sources: string[],
    addMedia: (ids: string[]) => Promise<void>,
    uploadedFor: string,
): Promise<void> {
    const queue = [...new Set(sources)];

    const next = async (): Promise<void> => {
        for (let src = queue.shift(); src !== undefined; src = queue.shift()) {
            mark(editor, src, 'importing', null);
            let answer: Answer;

            try {
                answer = await post(ImportImageController.url(), {
                    address: src,
                    // The item open in the editor, which the new file records (I-553).
                    uploaded_for: uploadedFor,
                });
            } catch {
                mark(
                    editor,
                    src,
                    'failed',
                    'Teme couldn’t reach the server. Check your connection, then try again.',
                );
                continue;
            }

            const id = answer.body.id;

            if (
                (answer.status === 200 || answer.status === 201) &&
                typeof id === 'string'
            ) {
                replace(editor, src, id);
                void addMedia([id]);
            } else {
                mark(editor, src, 'failed', failure(answer));
            }
        }
    };

    await Promise.all([next(), next()]);
}

/**
 * A pasted image's box (I-453): where it's from, its alt text and caption,
 * why it can't be imported if it can't, and Import and Remove. Nothing of
 * the other site is drawn: the admin shows no other site's images.
 */
function PastedImageView({
    node,
    editor,
    selected,
    deleteNode,
}: ReactNodeViewProps) {
    const { addMedia, uploadedFor } = useCardEnvironment();
    const id = useId();
    const attrs = node.attrs as PastedImageAttrs;
    const problem = pastedImageProblem(attrs.src);
    const { file, site } = pastedImageLabel(attrs.src);
    const importing = attrs.state === 'importing';

    return (
        <NodeViewWrapper
            role="group"
            aria-labelledby={
                problem === null ? `${id}-title ${id}-file` : `${id}-title`
            }
            aria-busy={importing}
            data-pasted-image=""
            contentEditable={false}
            className={cn(
                'border-edge bg-surface my-2 rounded-lg border border-dashed px-3 py-2.5',
                selected && 'outline-accent outline-2',
            )}
        >
            <p
                id={`${id}-title`}
                className="text-caption text-ink-3 font-semibold uppercase"
            >
                {__('Pasted image, not imported')}
            </p>
            {problem === null && (
                <p id={`${id}-file`} className="text-small mt-1 break-all">
                    {file === ''
                        ? __('An image from :site', { site })
                        : __(':file from :site', { file, site })}
                </p>
            )}
            <p className="text-small text-ink-2 mt-1">
                {attrs.alt === null
                    ? __('No alt text')
                    : __('Alt text: :alt', { alt: attrs.alt })}
            </p>
            {attrs.caption !== null && (
                <p className="text-small text-ink-2">
                    {__('Caption: :caption', { caption: attrs.caption })}
                </p>
            )}
            {problem !== null && (
                <p role="alert" className="text-small text-ink-2 mt-1">
                    {PROBLEM_WORDS[problem]}
                </p>
            )}
            {problem === null &&
                attrs.state === 'failed' &&
                attrs.message !== null && (
                    <p role="alert" className="text-small text-danger-ink mt-1">
                        {attrs.message}
                    </p>
                )}
            <div className="mt-2 flex flex-wrap gap-2">
                {problem === null && (
                    <Button
                        type="button"
                        size="sm"
                        disabled={importing}
                        onClick={() =>
                            void importPastedImages(
                                editor,
                                [attrs.src],
                                addMedia,
                                uploadedFor.id,
                            )
                        }
                    >
                        {importing
                            ? __('Importing…')
                            : attrs.state === 'failed'
                              ? __('Try again')
                              : __('Import')}
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={importing}
                    onClick={deleteNode}
                >
                    {__('Remove')}
                </Button>
            </div>
        </NodeViewWrapper>
    );
}

/** The pasted image's node with its box, as the editor registers it. */
export const PastedImageWithView = PastedImage.extend({
    addNodeView: () => ReactNodeViewRenderer(PastedImageView),
});

/** What the bar needs to know of the pasted images. */
function summarise(editor: Editor): {
    count: number;
    importable: string[];
    importing: boolean;
} {
    const images = pastedImages(editor);

    return {
        count: images.length,
        importable: images
            .map(({ node }) => node.attrs as PastedImageAttrs)
            .filter(
                (attrs) =>
                    pastedImageProblem(attrs.src) === null &&
                    attrs.state !== 'importing',
            )
            .map((attrs) => attrs.src),
        importing: images.some(({ node }) => node.attrs.state === 'importing'),
    };
}

/**
 * The bar above the body while pasted images wait (I-453): how many, that
 * they aren't saved until imported, and Import all and Remove all.
 */
export function PastedImagesBar({ editor }: { editor: Editor }) {
    const { addMedia, uploadedFor } = useCardEnvironment();
    const { count, importable, importing } = useEditorState({
        editor,
        selector: ({ editor: current }) => summarise(current),
    });

    if (count === 0) {
        return null;
    }

    return (
        <div
            role="region"
            aria-label="Pasted images"
            data-test="pasted-images"
            className="border-line bg-surface-2/40 flex flex-wrap items-center gap-x-3 gap-y-2 border-b px-5 py-2.5 text-[13px]"
        >
            <p aria-live="polite" className="min-w-0 flex-1">
                {count === 1
                    ? '1 pasted image isn’t in the media library yet, so it isn’t saved until it is. Import it only if you have the right to use it.'
                    : `${count} pasted images aren’t in the media library yet, so they aren’t saved until they are. Import them only if you have the right to use them.`}
            </p>
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={importing || importable.length === 0}
                    onClick={() =>
                        void importPastedImages(
                            editor,
                            importable,
                            addMedia,
                            uploadedFor.id,
                        )
                    }
                >
                    {importing
                        ? 'Importing…'
                        : count === 1
                          ? 'Import'
                          : 'Import all'}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={importing}
                    onClick={() => removeAll(editor)}
                >
                    {count === 1 ? 'Remove' : 'Remove all'}
                </Button>
            </div>
        </div>
    );
}
