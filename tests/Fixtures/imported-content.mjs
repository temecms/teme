// Prints what the editor's own code makes of Imported content cards, for
// ImportedContentEditorTest (I-329): the cards + Insert and the slash menu
// offer, the box's headline and source lines, and what Convert leaves, read
// back through the editor's own schema. Its argument is a JSON file of
// { manifest, markups, sources }.
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const { getSchema } = await import('@tiptap/core');
const { temeExtensions } = await import('../../resources/js/components/editor/schema.ts');
const { cardEntries } = await import('../../resources/js/components/editor/insert-entries.ts');
const { headline, sourceLine, toParagraphs } = await import('../../resources/js/components/editor/cards/imported-markup.ts');

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const schema = getSchema(temeExtensions(input.manifest.cards));

// As insertContentAt() reads Convert's nodes: through the editor's own schema, which throws on what it can't hold.
const converted = (markup) => {
    const nodes = toParagraphs(markup).map((json) => schema.nodeFromJSON(json));
    nodes.forEach((node) => node.check());

    return nodes.map((node) => node.toJSON());
};

const each = (make) => Object.fromEntries(Object.entries(input.markups).map(([name, markup]) => [name, make(markup)]));

process.stdout.write(JSON.stringify({
    menu: cardEntries(input.manifest.cards).map((entry) => entry.id),
    headlines: each(headline),
    sources: input.sources.map(([kind, name]) => sourceLine(kind, name)),
    converted: each(converted),
}));
