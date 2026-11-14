// Checks documents against the editor's own schema, as the editor reads a
// stored one (I-452): each goes through nodeFromJSON and check(), so a node
// in the wrong place (text straight in a list item, a cell with nothing in
// it, code beside another mark) is refused here even where the server's
// validator, which checks types and attributes, lets it through. Its
// argument is a JSON file of { manifest, documents }; it prints, for each
// document by name, null or the refusal.
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const { getSchema } = await import('@tiptap/core');
const { temeExtensions } = await import('../../resources/js/components/editor/schema.ts');

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const schema = getSchema(temeExtensions(input.manifest.cards));
const answers = {};

for (const [name, document] of Object.entries(input.documents)) {
    try {
        schema.nodeFromJSON(document).check();
        answers[name] = null;
    } catch (error) {
        answers[name] = String(error?.message ?? error);
    }
}

process.stdout.write(JSON.stringify(answers));
