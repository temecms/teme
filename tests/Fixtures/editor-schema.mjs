// Prints what the editor's own schema can make, for EditorSchemaTest (I-452):
// each node and mark with the attributes it declares; documents holding every
// node type with its default attributes, and every value the editor's parse
// rules can give an attribute; and each card before and after a trip through
// the clipboard. The schema comes from temeExtensions(), the function the
// editor calls. Its argument is a JSON file of { manifest }: the card
// manifest the editor screen sends.
// Node has no DOM, so each attribute's parsed values are listed here from its
// extension's code; a link's and a card's go through their real parse rules,
// which read nothing but getAttribute().
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const { getSchema } = await import('@tiptap/core');
const { createTable } = await import('@tiptap/extension-table');
const { temeExtensions } = await import('../../resources/js/components/editor/schema.ts');
const { ulid } = await import('../../resources/js/lib/ulid.ts');

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const schema = getSchema(temeExtensions(input.manifest.cards));
const { nodes, marks } = schema;
const cardNames = input.manifest.cards.map((card) => card.name);

const words = (text = 'Words') => schema.text(text);
const paragraph = (text = 'Words') => nodes.paragraph.create(null, words(text));
const doc = (...blocks) => nodes.doc.createChecked(null, blocks).toJSON();
const table = (cell) => nodes.table.createChecked(null, [nodes.tableRow.createChecked(null, [cell])]);
const label = (value) => (Array.isArray(value) ? `[${value.map(label).join(', ')}]` : Number.isNaN(value) ? 'NaN' : JSON.stringify(value));
const element = (attributes) => ({
    getAttribute: (name) => attributes[name.toLowerCase()] ?? null,
    hasAttribute: (name) => name.toLowerCase() in attributes,
});

const documents = {};

// (a) Every node type with its default attributes, as the editor makes it.
const defaults = {
    paragraph: nodes.paragraph.create(
        null,
        Object.values(marks).flatMap((type, index) => [
            ...(index === 0 ? [] : [nodes.hardBreak.create()]),
            words(type.name).mark([type.name === 'link' ? type.create({ href: 'https://example.org/' }) : type.create()]),
        ]),
    ),
    heading: nodes.heading.createAndFill(),
    blockquote: nodes.blockquote.createAndFill(),
    bulletList: nodes.bulletList.createAndFill(),
    orderedList: nodes.orderedList.createAndFill(),
    taskList: nodes.taskList.createAndFill(),
    codeBlock: nodes.codeBlock.createAndFill(),
    horizontalRule: nodes.horizontalRule.create(),
    // As the toolbar's and the slash menu's insertTable() make it.
    table: createTable(schema, 3, 3, true),
    // As insertCard() makes each card: the manifest's defaults and a new ID.
    ...Object.fromEntries(cardNames.map((name) => [`${name} card`, nodes[name].create({ id: ulid() })])),
};

documents['every node with its defaults'] = doc(...Object.values(defaults));

for (const [name, block] of Object.entries(defaults)) {
    documents[`defaults: ${name}`] = doc(block);
}

const used = new Set();
nodes.doc.create(null, Object.values(defaults)).descendants((node) => {
    used.add(node.type.name);
    node.marks.forEach((mark) => used.add(mark.type.name));
});

for (const name of [...Object.keys(nodes), ...Object.keys(marks)]) {
    if (name !== 'doc' && !used.has(name)) {
        throw new Error(`The defaults hold no ${name}: add one.`);
    }
}

// (b) Every value the editor's parse rules can give an attribute, from:
// TextAlign's parseHTML (the configured alignments, or null); Heading's rules
// (one per configured level); UniqueID's data-id on every block that carries
// an ID (any string, such as another Tiptap app's UUID); CodeBlock's
// language- class (any word); OrderedList's start (parseInt, so NaN) and
// type (any string); TaskItem's data-checked; a cell's colspan and rowspan
// (fromString, so 0, '' or any size: a pasted colspan of 20,000,000 arrives
// as it is), colwidth (parseInt each) and align (left, center, right or null).
const IDS = ['01K5Z9P2QH7N3X4C8V6B0M1T3D', '01k5z9p2qh7n3x4c8v6b0m1t3d', '9b2c6d1e-4f3a-4c1b-8e2d-7a6b5c4d3e2f'];

const VALUES = {
    paragraph: { textAlign: ['left', 'center', 'right', 'justify'], id: IDS },
    heading: {
        level: [2, 3, 4],
        textAlign: ['center'],
        id: IDS,
    },
    blockquote: { id: IDS },
    codeBlock: { language: ['php', 'C++', 'Objective_C'], id: IDS },
    orderedList: { start: [3, 0, -2, Number.NaN], type: ['a', 'I', '1', 'disc'] },
    listItem: { id: IDS },
    taskItem: { checked: [true], id: IDS },
    table: { id: IDS },
    tableCell: { colspan: [2, 0, '', 20000000], rowspan: [2, 0, 70000], colwidth: [[120], [Number.NaN]], align: ['left', 'center', 'right'] },
    tableHeader: { colspan: [2, 0, '', 20000000], rowspan: [2, 0, 70000], colwidth: [[120], [Number.NaN]], align: ['left', 'center', 'right'] },
};

const holders = {
    paragraph: (attrs) => nodes.paragraph.create(attrs, words()),
    heading: (attrs) => nodes.heading.create({ level: 2, ...attrs }, words()),
    blockquote: (attrs) => nodes.blockquote.create(attrs, paragraph()),
    codeBlock: (attrs) => nodes.codeBlock.create(attrs, words('echo 1;')),
    orderedList: (attrs) => nodes.orderedList.create(attrs, nodes.listItem.create(null, paragraph())),
    listItem: (attrs) => nodes.bulletList.create(null, nodes.listItem.create(attrs, paragraph())),
    taskItem: (attrs) => nodes.taskList.create(null, nodes.taskItem.create(attrs, paragraph())),
    table: (attrs) => nodes.table.createChecked(attrs, [nodes.tableRow.createChecked(null, [nodes.tableCell.create(null, paragraph())])]),
    tableCell: (attrs) => table(nodes.tableCell.create(attrs, paragraph())),
    tableHeader: (attrs) => table(nodes.tableHeader.create(attrs, paragraph())),
};

for (const [type, attributes] of Object.entries(VALUES)) {
    for (const [attribute, values] of Object.entries(attributes)) {
        for (const value of values) {
            documents[`${type}.${attribute} = ${label(value)}`] = doc(holders[type]({ [attribute]: value }));
        }
    }
}

// Links, through the editor's own rule for a pasted <a>. A link the rule
// refuses is pasted as its words, so it makes no document.
const LINKS = {
    'a link opening in a new tab, with a title': { href: 'https://example.org/tickets', target: '_blank', title: 'Tickets' },
    'a link as another Tiptap editor copies it': { href: 'https://example.org/', target: '_blank', rel: 'noopener noreferrer nofollow' },
    'a link whose title is a number': { href: 'https://example.org/', title: '2024' },
    'a link with target _self': { href: 'https://example.org/', target: '_self', class: 'external' },
    'a link to a place on the same page': { href: '#getting-there' },
    'a link to a place whose name has a space': { href: '#getting there' },
    'a link to an address with no scheme': { href: '//example.org/map' },
    'a link to an address on this site': { href: '/tickets/' },
    'a link to a place in another page on this site': { href: '/tickets/#times' },
    'a link to an ftp address': { href: 'ftp://example.org/files' },
    'a link relative to another page': { href: 'page.html' },
    'a link to a text message': { href: 'sms:+441234567890' },
    'a link to an email address': { href: 'mailto:box@example.org' },
    'an internal link in capitals': { href: 'teme:content/01K5Z9P2QH7N3X4C8V6B0M1T2R' },
    'an internal link to a place': { href: 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#times' },
};

for (const [name, attributes] of Object.entries(LINKS)) {
    const attrs = marks.link.spec.parseDOM[0].getAttrs(element(attributes));

    if (attrs !== false) {
        documents[name] = doc(nodes.paragraph.create(null, words().mark([marks.link.create(attrs)])));
    }
}

// A case for every attribute, so a new one Tiptap adds fails here first.
for (const [type, nodeType] of Object.entries(nodes)) {
    for (const attribute of Object.keys(nodeType.spec.attrs ?? {})) {
        if (!cardNames.includes(type) && VALUES[type]?.[attribute] === undefined) {
            throw new Error(`No value covers ${type}.${attribute}: add the values its parse rule can give.`);
        }
    }
}

for (const [type, markType] of Object.entries(marks)) {
    for (const attribute of Object.keys(markType.spec.attrs ?? {})) {
        if (type !== 'link' || !Object.values(LINKS).some((attributes) => attribute in attributes)) {
            throw new Error(`No case covers the ${type} mark's ${attribute}: add one.`);
        }
    }
}

// (c) Each card through the clipboard: its own HTML, read back by its own rule.
const throughClipboard = (json) => {
    const node = schema.nodeFromJSON(json);
    const [, rendered] = node.type.spec.toDOM(node);
    const attributes = Object.fromEntries(
        Object.entries(rendered)
            .filter(([, value]) => value !== null && value !== undefined)
            .map(([name, value]) => [name.toLowerCase(), String(value)]),
    );
    const attrs = node.type.spec.parseDOM[0].getAttrs(element(attributes));

    return { before: node.toJSON(), after: node.type.create(attrs === false ? null : attrs).toJSON() };
};

const cards = {};

for (const name of cardNames) {
    const fixture = JSON.parse(readFileSync(new URL(`./Documents/${name}.json`, import.meta.url), 'utf8'));
    cards[name] = throughClipboard(fixture.content.find((node) => node.type === name));
}

cards['a callout reading 2024'] = throughClipboard({ type: 'callout', attrs: { id: ulid(), tone: 'note', text: '2024' } });

// The values the Audio and Video cards' own views write (I-452): a size read
// from the file, loop mode, and tracks as Add a track makes them, from the
// views' own helpers.
const { newTrack, sizeOf } = await import('../../resources/js/components/editor/cards/media-file.ts');
const AV = '01K6A0D1000000000000000';

documents['video as its view leaves it'] = doc(nodes.video.create({
    id: ulid(),
    mediaId: `${AV}VD1`,
    posterMediaId: `${AV}PT1`,
    caption: 'Dawn over the harbour wall',
    loop: true,
    ...sizeOf({ videoWidth: 1920, videoHeight: 1080 }),
    tracks: [newTrack(`${AV}VT1`, 'en', true), { ...newTrack(`${AV}VT2`, 'pt-BR', false), kind: 'subtitles' }],
}));
documents['video whose size the browser couldn’t read'] = doc(nodes.video.create({ id: ulid(), mediaId: `${AV}VD1`, ...sizeOf({ videoWidth: 0, videoHeight: 0 }) }));
documents['audio as its view leaves it'] = doc(nodes.audio.create({
    id: ulid(),
    mediaId: `${AV}AD1`,
    caption: 'The tide report',
    transcript: 'Good morning from the quay.\r\n\r\nHigh water is at 6:12.',
}));

// The values the Imported content card's own view writes (I-452): what
// Convert leaves, from the view's own helper, with a picture and a link
// around code; and a card marked kept.
const { toParagraphs } = await import('../../resources/js/components/editor/cards/imported-markup.ts');
const importedCard = JSON.parse(readFileSync(new URL('./Documents/imported.json', import.meta.url), 'utf8')).content[0];
const importedConverted = nodes.doc.createChecked(null, toParagraphs([
    ...importedCard.attrs.markup,
    { element: 'p', children: [{ text: 'Quay ' }, { element: 'img', attrs: { mediaId: '01K6A0D1000000000000000MD1', alt: 'The quay' } }, { element: 'a', attrs: { href: 'https://example.org/' }, children: [{ element: 'code', children: [{ text: 'npm' }] }] }] },
]).map((json) => schema.nodeFromJSON(json)));
importedConverted.check();

documents['imported content converted to paragraphs'] = importedConverted.toJSON();
documents['imported content kept'] = doc(nodes.imported.create({ ...importedCard.attrs, id: ulid(), kept: true }));

// Links by ID to a category or tag, an author and a library file (I-1702),
// as the link box and the importer write them, through the editor's own rule
// for a pasted <a>; in the wrong case too, which the server repairs.
const LINK_TARGETS = {
    'a link to a category by ID, at a place': 'teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s#latest',
    'a link to a category by ID, in capitals': 'teme:term/01K5Z9P2QH7N3X4C8V6B0M1T2S',
    'a link to an author by ID': 'teme:user/42',
    'a link to a file by ID, at a page': 'teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T#page=3',
    'a link to a file by ID, in small letters': 'teme:media/01k5z9p2qh7n3x4c8v6b0m1t2t',
};

for (const [name, href] of Object.entries(LINK_TARGETS)) {
    const attrs = marks.link.spec.parseDOM[0].getAttrs(element({ href }));

    if (attrs === false) {
        throw new Error(`The editor refuses ${name}, which the server keeps: check normaliseHref().`);
    }

    documents[name] = doc(nodes.paragraph.create(null, words().mark([marks.link.create(attrs)])));
}

const attributesOf = (types) => Object.fromEntries(Object.entries(types).map(([name, type]) => [name, Object.keys(type.spec.attrs ?? {})]));

process.stdout.write(JSON.stringify({ nodes: attributesOf(nodes), marks: attributesOf(marks), documents, cards }));
