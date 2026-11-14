import { Node } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';

/** The node's name: the editor's own, never sent to the server (I-453). */
export const PASTED_IMAGE = 'pastedImage';

/** Where a pasted image's import stands. */
export type PastedImageState = 'waiting' | 'importing' | 'failed';

/** Why a pasted image can't be imported at all. */
export type PastedImageProblem = 'inside' | 'computer' | 'incomplete';

/** A pasted image's attributes. */
export type PastedImageAttrs = {
    src: string;
    alt: string | null;
    caption: string | null;
    state: PastedImageState;
    message: string | null;
};

/** The longest alt text and caption an Image card keeps, as the server's ImageCard rules say. */
const ALT_MOST = 1000;
const CAPTION_MOST = 2000;

/** The words for each reason a pasted image can't be imported. */
export const PROBLEM_WORDS: Record<PastedImageProblem, string> = {
    inside: 'This image came inside the pasted text, not from a web address, so Teme can’t import it. Save it to your computer, then upload it to the media library.',
    computer:
        'This image is on your computer, not on a website, so Teme can’t import it. Upload it to the media library instead.',
    incomplete: 'This image’s address is incomplete, so Teme can’t import it.',
};

/** Text as a card keeps it: spaces collapsed, trimmed, cut to its limit; null when empty. */
function clip(text: string | null | undefined, most: number): string | null {
    const tidy = (text ?? '').replace(/\s+/g, ' ').trim();

    return tidy === '' ? null : tidy.slice(0, most);
}

/** A pasted <img>'s attributes, with the caption around it if there is one. */
export function imageAttrs(
    image: Pick<Element, 'getAttribute'>,
    caption: string | null,
): PastedImageAttrs {
    const src = (image.getAttribute('src') ?? '').trim();

    return {
        src: src.startsWith('//') ? `https:${src}` : src,
        alt: clip(image.getAttribute('alt'), ALT_MOST),
        caption: clip(caption, CAPTION_MOST),
        state: 'waiting',
        message: null,
    };
}

/**
 * A pasted <figure> holding exactly one image and nothing more than a
 * caption: that image, with its caption. A figure of several (a WordPress
 * gallery's figures inside a figure), of none, or with a table, a list, a
 * quotation or code beside its image is false, so the rules reach what is
 * inside it and the atom swallows nothing.
 */
export function figureAttrs(
    figure: Pick<Element, 'querySelector' | 'querySelectorAll'>,
): PastedImageAttrs | false {
    const images = Array.from(figure.querySelectorAll('img[src]'));
    const [image] = images;

    if (
        images.length !== 1 ||
        image === undefined ||
        figure.querySelector('table, ul, ol, blockquote, pre') !== null
    ) {
        return false;
    }

    return imageAttrs(
        image,
        figure.querySelector('figcaption')?.textContent ?? null,
    );
}

/** Why this address can't be imported, or null when it can be. */
export function pastedImageProblem(src: string): PastedImageProblem | null {
    if (/^https?:\/\/[^/?#\s]/i.test(src)) {
        return null;
    }

    if (/^data:/i.test(src)) {
        return 'inside';
    }

    return /^(?:file|blob):/i.test(src) ? 'computer' : 'incomplete';
}

/**
 * The image's file name, decoded, and its site, for its box; both empty
 * for an address that isn't a web one.
 */
export function pastedImageLabel(src: string): { file: string; site: string } {
    if (pastedImageProblem(src) !== null) {
        return { file: '', site: '' };
    }

    try {
        const url = new URL(src);
        const last =
            url.pathname
                .split('/')
                .filter((part) => part !== '')
                .pop() ?? '';
        let file = last;

        try {
            file = decodeURIComponent(last);
        } catch {
            file = last;
        }

        return { file, site: url.hostname };
    } catch {
        return { file: '', site: '' };
    }
}

/** Whether the document holds a pasted image. */
export function hasPastedImage(document: ProseMirrorNode): boolean {
    let found = false;

    document.descendants((node) => {
        found ||= node.type.name === PASTED_IMAGE;

        return !found;
    });

    return found;
}

/**
 * The document as it is saved: every pasted image left out, since none is
 * in the library yet (I-453). A block they leave empty keeps an empty
 * paragraph, which the editor's schema requires in a blockquote, a list
 * item, a table cell and the document itself.
 */
export function withoutPastedImages(document: JSONContent): JSONContent {
    if (document.content === undefined) {
        return document;
    }

    const content = document.content
        .filter((child) => child.type !== PASTED_IMAGE)
        .map(withoutPastedImages);

    return {
        ...document,
        content:
            content.length === 0 && document.content.length > 0
                ? [{ type: 'paragraph' }]
                : content,
    };
}

/** An attribute only the node's rules set, never a pasted element's own. */
const OWN = { parseHTML: () => null, renderHTML: () => ({}) };

/**
 * A pasted image from another site, waiting in place to be imported into
 * the media library (I-453). The editor's own node, registered beside
 * temeExtensions() and never in it, so neither the server nor the guard
 * meets it; withoutPastedImages() leaves it out of what is saved. Its view
 * is added where the editor is made (pasted-image-view.tsx).
 */
export const PastedImage = Node.create({
    name: PASTED_IMAGE,
    group: 'block',
    atom: true,
    selectable: true,
    draggable: false,

    addAttributes: () => ({
        src: { default: '', ...OWN },
        alt: { default: null, ...OWN },
        caption: { default: null, ...OWN },
        state: { default: 'waiting', ...OWN },
        message: { default: null, ...OWN },
    }),

    parseHTML: () => [
        // A figure holding one image comes first, so its caption comes too.
        {
            tag: 'figure',
            priority: 60,
            getAttrs: (figure) => figureAttrs(figure),
        },
        { tag: 'img[src]', getAttrs: (image) => imageAttrs(image, null) },
    ],

    // As an <img>, so a copy within the editor pastes as a pasted image again.
    renderHTML: ({ node }) => [
        'img',
        {
            src: node.attrs.src as string,
            alt: (node.attrs.alt as string | null) ?? '',
        },
    ],
});
