import { variant } from '@/lib/media-urls';
import type { CardManifest, CardMedia } from '@/types/editor';
import {
    allowsHref,
    isFragment,
    isSitePath,
    linkTarget,
} from '../link-address';
import type { LinkTarget } from '../link-address';
import type { PlayableMedia } from './media-file';

type Attrs = Record<string, unknown>;
type Mark = { type: string; attrs?: Attrs };
type DocNode = {
    type?: string;
    attrs?: Attrs;
    content?: DocNode[];
    marks?: Mark[];
    text?: string;
};

/** What the browser knows while rendering: nothing it would have to fetch. */
type Context = {
    media: Record<string, CardMedia>;
    manifest: CardManifest;
    /** An article's address by its ID; a term's or a person's by `term:{id}` or `user:{id}`. */
    links: Record<string, string>;
};

/**
 * Escapes as PHP's e() and htmlspecialchars(ENT_QUOTES) do: these five
 * characters, the apostrophe as `&#039;`, and nothing else.
 */
export function escape(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ---------------------------------------------------------------------------
// Prose, as tiptap-php's DOMSerializer writes it
// ---------------------------------------------------------------------------

/**
 * An element's attributes as tiptap-php's HTML::renderAttributes() writes
 * them: nulls and empty strings left out, booleans as `true` or `false`.
 * PHP escapes attributes with htmlentities(), which also turns non-ASCII
 * letters into named entities; this escapes only the five, so an attribute
 * with an accented letter (an unencoded link address) differs (B-140).
 */
function attributes(attrs: [string, unknown][]): string {
    return attrs
        .map(([name, value]) => {
            if (
                value === null ||
                value === undefined ||
                typeof value === 'object'
            ) {
                return '';
            }

            const text =
                typeof value === 'boolean'
                    ? value
                        ? 'true'
                        : 'false'
                    : String(value as string | number);

            return text === '' ? '' : ` ${name}="${escape(text)}"`;
        })
        .join('');
}

/** A paragraph's or heading's alignment, when it is not the default left. */
function alignment(node: DocNode): [string, unknown][] {
    const align = node.attrs?.textAlign;

    // tiptap-php's mergeAttributes() ends every style with a semicolon.
    return typeof align === 'string' && align !== '' && align !== 'left'
        ? [['style', `text-align: ${align};`]]
        : [];
}

const HEADING_LEVELS = [1, 2, 3, 4, 5, 6];

/**
 * The opening and closing tags of each prose node, exactly as tiptap-php's
 * extensions render them. A node not listed renders nothing but its content.
 */
function nodeTags(node: DocNode): [string, string] | null {
    const attrs = node.attrs ?? {};

    switch (node.type) {
        case 'paragraph':
            return [`<p${attributes(alignment(node))}>`, '</p>'];
        case 'heading': {
            const level = HEADING_LEVELS.includes(Number(attrs.level))
                ? Number(attrs.level)
                : 1;

            return [
                `<h${level}${attributes(alignment(node))}>`,
                `</h${level}>`,
            ];
        }
        case 'blockquote':
            return ['<blockquote>', '</blockquote>'];
        case 'bulletList':
            return ['<ul>', '</ul>'];
        case 'orderedList':
            return [
                `<ol${attributes([['start', attrs.start ? attrs.start : null]])}>`,
                '</ol>',
            ];
        case 'listItem':
            return ['<li>', '</li>'];
        case 'taskList':
            return ['<ul data-type="taskList">', '</ul>'];
        case 'taskItem': {
            const checked = attrs.checked ?? false;

            return [
                `<li${attributes([
                    ['data-checked', checked],
                    ['data-type', 'taskItem'],
                ])}><label><input${attributes([
                    ['type', 'checkbox'],
                    ['checked', checked ? 'checked' : null],
                ])}><span></span></label><div>`,
                '</div></li>',
            ];
        }
        case 'codeBlock': {
            const language =
                typeof attrs.language === 'string' && attrs.language !== ''
                    ? `language-${attrs.language}`
                    : null;

            return [
                `<pre><code${attributes([['class', language]])}>`,
                '</code></pre>',
            ];
        }
        case 'horizontalRule':
            return ['<hr>', ''];
        case 'hardBreak':
            return ['<br>', ''];
        case 'table':
            return ['<table><tbody>', '</tbody></table>'];
        case 'tableRow':
            return ['<tr>', '</tr>'];
        case 'tableHeader':
        case 'tableCell': {
            const tag = node.type === 'tableHeader' ? 'th' : 'td';
            const widths = Array.isArray(attrs.colwidth)
                ? attrs.colwidth.join(',')
                : null;
            // TableCellNode's alignment, with mergeAttributes()' semicolon.
            const align =
                typeof attrs.align === 'string' &&
                ['left', 'center', 'right'].includes(attrs.align)
                    ? `text-align: ${attrs.align};`
                    : null;

            return [
                `<${tag}${attributes([
                    ['rowspan', attrs.rowspan],
                    ['colspan', attrs.colspan],
                    ['data-colwidth', widths],
                    ['style', align],
                ])}>`,
                `</${tag}>`,
            ];
        }
        default:
            return null;
    }
}

/**
 * Where a link by ID leads now, as LinkResolver::target() says: an
 * article's, a term's or a person's address from `links`; a library file's
 * from the media map, an image at its widest size; undefined for none.
 */
function targetUrl(link: LinkTarget, context: Context): string | undefined {
    if (link.kind === 'media') {
        const item = context.media[link.id] as PlayableMedia | undefined;

        if (item === undefined) {
            return undefined;
        }

        const widest = Object.entries(item.variants)
            .filter(([key]) => /^w\d+$/.test(key))
            .sort(([, a], [, b]) => b.width - a.width)[0];

        return item.fileUrl ?? widest?.[1].url;
    }

    return context.links[
        link.kind === 'content' ? link.id : `${link.kind}:${link.id}`
    ];
}

/**
 * A mark's opening and closing tags, or null when it draws none: an
 * internal link to something unpublished, or an address the server refuses
 * (LinkMark).
 */
function markTags(mark: Mark, context: Context): [string, string] | null {
    switch (mark.type) {
        case 'bold':
            return ['<strong>', '</strong>'];
        case 'italic':
            return ['<em>', '</em>'];
        case 'underline':
            return ['<u>', '</u>'];
        case 'strike':
            return ['<s>', '</s>'];
        case 'code':
            return ['<code>', '</code>'];
        case 'highlight':
            return ['<mark>', '</mark>'];
        case 'subscript':
            return ['<sub>', '</sub>'];
        case 'superscript':
            return ['<sup>', '</sup>'];
        case 'link': {
            const href =
                typeof mark.attrs?.href === 'string' ? mark.attrs.href : '';

            if (href.startsWith('teme:')) {
                // A link by ID goes to its target's address now, its place kept (LinkMark).
                const link = linkTarget(href);
                const url =
                    link === null ? undefined : targetUrl(link, context);

                return link === null || url === undefined
                    ? null
                    : [
                          `<a${attributes([['href', `${url}${link.place}`]])}>`,
                          '</a>',
                      ];
            }

            // A place on the same page or a path on this site: no target and no rel (LinkMark).
            if (isFragment(href) || isSitePath(href)) {
                return [`<a${attributes([['href', href]])}>`, '</a>'];
            }

            if (href === '' || !allowsHref(href)) {
                return null;
            }

            return [
                `<a${attributes([
                    ['href', href],
                    [
                        'target',
                        mark.attrs?.target === '_blank' ? '_blank' : null,
                    ],
                    ['rel', 'noopener'],
                ])}>`,
                '</a>',
            ];
        }
        default:
            return null;
    }
}

/** The marks the server's renderer registers; any other is skipped whole. */
const MARKS = new Set([
    'bold',
    'italic',
    'underline',
    'strike',
    'code',
    'highlight',
    'subscript',
    'superscript',
    'link',
]);

/** Two marks are the same when their type and attributes are (PHP's `==`). */
function sameMark(a: Mark, b: Mark): boolean {
    return (
        a.type === b.type &&
        JSON.stringify(a.attrs ?? null) === JSON.stringify(b.attrs ?? null)
    );
}

/** Whether `other` lacks the mark, so it opens or closes here. */
function lacksMark(other: DocNode | undefined, mark: Mark): boolean {
    if (other === undefined || other.marks === undefined) {
        return true;
    }

    return !other.marks.some((each) => sameMark(each, mark));
}

type OpenMark = { mark: Mark; tags: [string, string] | null };

/**
 * One node, with tiptap-php's handling of marks: a mark shared by
 * neighbouring text stays open across them, and closing a mark that is not
 * on top closes and reopens the ones above it.
 */
function renderNode(
    node: DocNode,
    previous: DocNode | undefined,
    next: DocNode | undefined,
    stack: OpenMark[],
    context: Context,
): string {
    let html = '';

    const marks = (node.marks ?? []).filter((mark) => MARKS.has(mark.type));

    for (const mark of marks) {
        if (!lacksMark(previous, mark)) {
            continue;
        }

        const tags = markTags(mark, context);
        html += tags?.[0] ?? '';
        stack.push({ mark, tags });
    }

    const tags = nodeTags(node);
    html += tags?.[0] ?? '';

    if (node.content !== undefined) {
        const nested: OpenMark[] = [];

        node.content.forEach((child, index) => {
            html += renderNode(
                child,
                node.content?.[index - 1],
                node.content?.[index + 1],
                nested,
                context,
            );
        });
    } else if (typeof node.text === 'string') {
        html += escape(node.text);
    }

    html += tags?.[1] ?? '';

    if (marks.length > 0) {
        const toClose = [...marks]
            .reverse()
            .filter((mark) => lacksMark(next, mark));
        const toReopen: OpenMark[] = [];

        while (toClose.length > 0) {
            const open = stack.pop();

            if (open === undefined) {
                break;
            }

            html += open.tags?.[1] ?? '';
            const index = toClose.findIndex((mark) =>
                sameMark(mark, open.mark),
            );

            if (index === -1) {
                toReopen.push(open);
            } else {
                toClose.splice(index, 1);
            }
        }

        for (const open of toReopen.reverse()) {
            html += open.tags?.[0] ?? '';
            stack.push(open);
        }
    }

    return html;
}

function renderProse(run: DocNode[], context: Context): string {
    const stack: OpenMark[] = [];

    return run
        .map((node, index) =>
            renderNode(node, run[index - 1], run[index + 1], stack, context),
        )
        .join('');
}

// ---------------------------------------------------------------------------
// Cards, as each CardType::renderHtml() writes them
// ---------------------------------------------------------------------------

const EMBED_SANDBOX = 'allow-scripts allow-same-origin allow-presentation';

function text(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

/** PHP's `$value ?? $fallback`: the fallback for null only, not for ''. */
function or(value: unknown, fallback: string): string {
    return value === null || value === undefined ? fallback : text(value);
}

/** The host, as PHP's parse_url() reads it: case kept, no port or user. */
function host(url: string): string {
    return (
        /^[a-z][a-z0-9+.-]*:\/\/(?:[^@/?#]*@)?([^:/?#]*)/i.exec(url)?.[1] ?? ''
    );
}

/** The frame address for a URL, from the first provider that matches it. */
function frameFor(url: string, manifest: CardManifest): string | null {
    for (const provider of manifest.embedProviders) {
        const groups = new RegExp(provider.match).exec(url);

        if (groups !== null) {
            return provider.frame.replace(/\$(\d)/g, (_, index: string) =>
                encodeURIComponent(groups[Number(index)] ?? ''),
            );
        }
    }

    return null;
}

/** The width the plain `src` aims for, as ImageMarkup::SRC_WIDTH. */
const SRC_WIDTH = 1000;

/**
 * An image as PHP's ImageMarkup::item() draws it: every width in `srcset`,
 * the one nearest 1,000 px as `src`, the theme's `sizes` for the context,
 * the original's size and the focal point. Never a priority image: that
 * needs the page's lead, which the editor has not.
 */
function imageMarkup(
    item: CardMedia,
    context: string,
    manifest: CardManifest,
    alt = '',
    decorative = false,
): string {
    const widths = Object.entries(item.variants)
        .filter(([key]) => /^w\d+$/.test(key))
        .map(([, variant]) => variant)
        .sort((a, b) => a.width - b.width);

    if (widths.length === 0) {
        return '';
    }

    let src = widths[0];

    for (const variant of widths) {
        if (
            Math.abs(variant.width - SRC_WIDTH) <=
            Math.abs(src.width - SRC_WIDTH)
        ) {
            src = variant;
        }
    }

    const text = decorative ? '' : alt.trim() || (item.alt ?? '');
    const srcset = widths
        .map((variant) => `${variant.url} ${variant.width}w`)
        .join(', ');
    const sizes = manifest.imageSizes[context] ?? '100vw';
    const style =
        item.focalX !== 50 || item.focalY !== 50
            ? ` style="object-position: ${item.focalX}% ${item.focalY}%"`
            : '';

    return `<img src="${escape(src.url)}" srcset="${escape(srcset)}" sizes="${escape(sizes)}" alt="${escape(text)}" width="${item.width}" height="${item.height}"${style} loading="lazy" decoding="async">`;
}

type CardRenderer = (attrs: Attrs, context: Context) => string;

const CARDS: Record<string, CardRenderer> = {
    image: (attrs, { media, manifest }) => {
        const item = media[text(attrs.mediaId)];

        if (item === undefined) {
            return '';
        }

        const image = imageMarkup(
            item,
            'content',
            manifest,
            text(attrs.alt),
            Boolean(attrs.decorative),
        );

        if (image === '') {
            return '';
        }

        const caption = text(attrs.caption).trim();
        const credit = text(attrs.credit).trim();
        const figcaption =
            caption === '' && credit === ''
                ? ''
                : `<figcaption>${escape(caption)}${
                      credit === ''
                          ? ''
                          : `<span class="credit">${escape(credit)}</span>`
                  }</figcaption>`;

        return `<figure class="${escape(`card-image card-image--${or(attrs.width, 'column')}`)}">${image}${figcaption}</figure>`;
    },

    gallery: (attrs, { media, manifest }) => {
        const ids = Array.isArray(attrs.mediaIds)
            ? attrs.mediaIds.map(text)
            : [];
        const images = ids
            .map((id) => media[id])
            .filter((item) => item !== undefined)
            .map((item) => imageMarkup(item, 'content', manifest))
            .filter((image) => image !== '')
            .map((image) => `<li>${image}</li>`)
            .join('');

        if (images === '') {
            return '';
        }

        const caption = text(attrs.caption).trim();

        return `<figure class="${escape(`card-gallery card-gallery--${or(attrs.layout, 'grid')}`)}"><ul>${images}</ul>${
            caption === '' ? '' : `<figcaption>${escape(caption)}</figcaption>`
        }</figure>`;
    },

    embed: (attrs, { media, manifest }) => {
        const url = text(attrs.url);
        const title = text(attrs.title).trim();
        const caption = text(attrs.caption).trim();
        const figcaption =
            caption === '' ? '' : `<figcaption>${escape(caption)}</figcaption>`;
        const kind = or(attrs.kind, 'link');
        const frame = kind === 'link' ? null : frameFor(url, manifest);

        if (frame !== null) {
            return `<figure class="${escape(`card-embed card-embed--${kind}`)}"><iframe src="${escape(frame)}" loading="lazy" sandbox="${EMBED_SANDBOX}" referrerpolicy="strict-origin-when-cross-origin" title="${escape(title === '' ? 'Embedded content' : title)}"></iframe>${figcaption}</figure>`;
        }

        const thumbnailItem = media[text(attrs.thumbnailMediaId)];
        const thumbnail =
            thumbnailItem === undefined
                ? null
                : variant(thumbnailItem.variants);

        return `<figure class="card-embed card-embed--link"><a href="${escape(url)}" rel="noopener">${
            thumbnail === null
                ? ''
                : `<img src="${escape(thumbnail)}" alt="" loading="lazy" decoding="async">`
        }<span class="card-embed__title">${escape(title === '' ? url : title)}</span><span class="card-embed__host">${escape(host(url))}</span></a>${figcaption}</figure>`;
    },

    callout: (attrs) =>
        `<div class="${escape(`card-callout card-callout--${or(attrs.tone, 'note')}`)}" role="note"><p>${escape(text(attrs.text))}</p></div>`,

    // The server highlights code with highlight.php, which the browser does
    // not have: here every language renders as escaped plain text, which is
    // what the server does for `plain` (B-140).
    snippet: (attrs) => {
        const caption = text(attrs.caption).trim();

        return `<figure class="card-snippet"><pre><code class="${escape(`language-${or(attrs.language, 'plain')}`)}">${escape(text(attrs.code))}</code></pre>${
            caption === '' ? '' : `<figcaption>${escape(caption)}</figcaption>`
        }</figure>`;
    },

    button: (attrs, { links }) => {
        const contentId = text(attrs.contentId);
        const href = contentId !== '' ? links[contentId] : text(attrs.url);

        if (href === undefined || href === '') {
            return '';
        }

        return `<a class="${escape(`card-button card-button--${or(attrs.style, 'primary')}`)}" href="${escape(href)}">${escape(text(attrs.label))}</a>`;
    },
};

/**
 * One card's HTML, as its CardType::renderHtml() writes it, with the
 * manifest's defaults for any attribute left out. A card this build does
 * not know renders nothing, as on the server.
 */
export function renderCard(
    name: string,
    attrs: Attrs,
    media: Record<string, CardMedia>,
    manifest: CardManifest,
    links: Record<string, string> = {},
): string {
    const entry = manifest.cards.find((card) => card.name === name);
    const render = CARDS[name];

    if (entry === undefined || render === undefined) {
        return '';
    }

    const withDefaults: Attrs = { ...attrs };

    for (const attribute of entry.attributes) {
        if (!(attribute.name in withDefaults) && attribute.default !== null) {
            withDefaults[attribute.name] = attribute.default;
        }
    }

    return render(withDefaults, { media, manifest, links });
}

/**
 * The document's HTML, as DocumentRenderer writes it for the site: prose
 * through tiptap-php's rules, cards through their own. Everything it knows
 * comes from its arguments. `links` maps an article's ID, or `term:{id}` and
 * `user:{id}`, to its address; a link by ID not in it, or to a file not in
 * `media`, renders as plain text, as a target with no address does on the
 * server.
 */
export function renderDocument(
    document: { content?: unknown[] },
    media: Record<string, CardMedia>,
    manifest: CardManifest,
    links: Record<string, string> = {},
): string {
    const context: Context = { media, manifest, links };
    const cards = new Set(manifest.cards.map((card) => card.name));
    let html = '';
    let run: DocNode[] = [];

    for (const node of (document.content ?? []) as DocNode[]) {
        if (typeof node?.type === 'string' && cards.has(node.type)) {
            html += renderProse(run, context);
            run = [];
            html += renderCard(
                node.type,
                node.attrs ?? {},
                media,
                manifest,
                links,
            );
        } else {
            run.push(node);
        }
    }

    return html + renderProse(run, context);
}
