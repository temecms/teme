// Prints regionChecks() for one JSON file of { document, media, max,
// allowed, cardLabels }, for RegionChecksTest. Node strips the TypeScript
// types itself; checks.ts imports only types.
import { readFileSync } from 'node:fs';
import { regionChecks } from '../../resources/js/components/editor/rail/checks.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(JSON.stringify(regionChecks(input)));
