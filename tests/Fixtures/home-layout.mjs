// Prints home-layout.ts for one JSON file of { call, … }, for HomeLayoutRulesTest.
import { readFileSync } from 'node:fs';
import { moveBy, moveTo, toPreferences } from '../../resources/js/components/dashboard/home-layout.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

const result = (() => {
    switch (input.call) {
        case 'moveBy':
            return moveBy(input.layout, input.owners, input.key, input.step);
        case 'moveTo':
            return moveTo(input.layout, input.owners, input.key, input.index);
        case 'toPreferences':
            return toPreferences(input.layout, input.declared);
        default:
            throw new Error(`Unknown call ${input.call}`);
    }
})();

process.stdout.write(JSON.stringify(result));
