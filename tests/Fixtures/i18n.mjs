// Runs the admin's word helpers (resources/js/lib/i18n.ts) for
// AdminWordsDeliveryTest. Node strips the TypeScript types itself; i18n.ts
// imports nothing.
import { readFileSync } from 'node:fs';
import { __, __n, setWords } from '../../resources/js/lib/i18n.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

setWords({ locale: input.locale, messages: input.messages, version: 'test' });

process.stdout.write(JSON.stringify({
    words: input.words.map(([key, replace]) => __(key, replace)),
    counted: input.counted.map(([key, count, replace]) => __n(key, count, replace)),
}));
