// Prints splitRowActions() for one JSON file of { cases }, each a list of
// { label, destructive }, for RowActionOrderTest. row-action-order.ts imports nothing.
import { readFileSync } from 'node:fs';
import { splitRowActions } from '../../resources/js/components/list/row-action-order.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const labels = (items) => items.map((item) => item.label);

process.stdout.write(JSON.stringify(input.cases.map((items) => {
    const { line, more } = splitRowActions(items);

    return { line: labels(line), more: labels(more) };
})));
