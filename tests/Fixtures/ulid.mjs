// Prints 500 IDs from the browser's ulid(), one per line, for BrowserIdsTest.
// Node strips the TypeScript types itself, so the file is imported as it is.
import { ulid } from '../../resources/js/lib/ulid.ts';

const ids = [];

for (let i = 0; i < 500; i++) {
    ids.push(ulid());
}

process.stdout.write(ids.join('\n') + '\n');
