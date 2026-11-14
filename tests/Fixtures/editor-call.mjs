// Calls one exported function of the editor's modules with JSON arguments
// and prints the answers, for the tests of its plain helpers (I-452). Its
// argument is a JSON file of { module, calls: [{ name, args }] }, the
// module's path being under resources/js.
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const editorModule = await import(new URL(`../../resources/js/${input.module}`, import.meta.url).href);

process.stdout.write(JSON.stringify(input.calls.map(({ name, args }) => editorModule[name](...args))));
