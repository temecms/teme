// Applies an autosave's link changes to a document as the editor does
// (link-changes.ts) and prints the result, for LinkEditorTest (I-452). Its
// argument is a JSON file of { manifest, document, links }.
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const { getSchema } = await import('@tiptap/core');
const { EditorState } = await import('@tiptap/pm/state');
const { temeExtensions } = await import('../../resources/js/components/editor/schema.ts');
const { applyLinkChanges } = await import('../../resources/js/components/editor/link-changes.ts');

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const schema = getSchema(temeExtensions(input.manifest.cards));
const state = EditorState.create({ schema, doc: schema.nodeFromJSON(input.document) });
const tr = applyLinkChanges(state, input.links);

process.stdout.write(JSON.stringify({ changed: tr !== null, document: (tr ?? state.tr).doc.toJSON() }));
