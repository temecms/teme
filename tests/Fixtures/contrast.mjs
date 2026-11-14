// Prints contrast.ts's answers for one JSON file of { candidates, ratios,
// accents, variants }, for ContrastTest. Node strips the TypeScript types
// itself, so the file is imported as it is.
import { readFileSync } from 'node:fs';
import { accentProblems, darkVariant, onColor, ratio } from '../../resources/js/lib/contrast.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(
    JSON.stringify({
        ratios: input.ratios.map(([a, b]) => ratio(a, b)),
        accents: input.accents.map(({ accent, base }) => ({
            onColor: onColor(accent, input.candidates),
            problems: accentProblems(accent, base, input.candidates),
        })),
        variants: input.variants.map(([accent, base]) => darkVariant(accent, base, input.candidates)),
    }),
);
