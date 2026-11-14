// Prints the Date filter's words for one JSON file of { months, values }, for
// DateWordsTest. Node strips the TypeScript types itself; date-words.ts
// imports nothing.
import { readFileSync } from 'node:fs';
import { dateValue, monthLabel } from '../../resources/js/components/list/date-words.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(JSON.stringify({ months: input.months.map(monthLabel), values: input.values.map(dateValue) }));
