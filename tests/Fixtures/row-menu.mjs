// Prints orderRowMenu() for one JSON file of { cases }, each a list of
// { label, owner, destructive }, for RowMenuOrderTest. row-menu-order.ts imports nothing.
import { readFileSync } from 'node:fs';
import { orderRowMenu } from '../../resources/js/components/list/row-menu-order.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const labels = (items) => items.map((item) => item.label);

process.stdout.write(JSON.stringify(input.cases.map((items) => {
    const { core, plugins, destructive } = orderRowMenu(items);

    return { core: labels(core), plugins: labels(plugins), destructive: labels(destructive) };
})));
