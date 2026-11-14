// Prints documentChecks() for one JSON file of { title, fields, document,
// media }, for EditorRailTest. Node strips the TypeScript types itself; the
// file imports only types through the `@/` alias, and those are erased.
import { readFileSync } from 'node:fs';
import { documentChecks } from '../../resources/js/components/editor/rail/checks.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(JSON.stringify(documentChecks(input)));
