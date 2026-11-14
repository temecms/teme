// Prints the browser's HTML for one document, for RenderParityTest. Its three
// arguments are the document, the media map and the card manifest, each a
// JSON file. editor-hooks.mjs resolves the editor's imports as Vite does.
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const { renderDocument } = await import('../../resources/js/components/editor/cards/render.ts');

const [documentPath, mediaPath, manifestPath] = process.argv.slice(2);
const read = (path) => JSON.parse(readFileSync(path, 'utf8'));

process.stdout.write(renderDocument(read(documentPath), read(mediaPath), read(manifestPath)));
