// Prints what the editor's paste handling makes of one clipboard, for
// PasteTest: { html, embed }. Its argument is a JSON file of { html, text,
// providers, site }. editor-hooks.mjs resolves the editor's imports as Vite does.
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const { cleanPastedHtml, embedFromPastedText } = await import('../../resources/js/components/editor/paste.ts');

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(
    JSON.stringify({
        html: input.html === null ? null : cleanPastedHtml(input.html, input.site ?? null),
        embed: input.text === null ? null : embedFromPastedText(input.text, input.providers),
    }),
);
