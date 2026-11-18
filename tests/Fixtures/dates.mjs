// Prints the admin's dates for one JSON file of { zone, locale, at }, for
// AdminDatesTest. Node strips the TypeScript types itself; dates.ts imports
// nothing.
import { readFileSync } from 'node:fs';
import { formatDate, formatDateTime, setDateContext } from '../../resources/js/lib/dates.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const before = { date: formatDate(input.at), dateTime: formatDateTime(input.at) };

setDateContext(input.zone, input.locale);

process.stdout.write(JSON.stringify({
    before,
    date: formatDate(input.at),
    dateTime: formatDateTime(input.at),
    elsewhere: formatDateTime(input.at, 'America/New_York'),
}));
