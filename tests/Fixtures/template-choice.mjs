// Prints templateChoice() for one JSON file of { declared, value }, for
// TemplateChoiceTest. Node strips the TypeScript types itself.
import { readFileSync } from 'node:fs';
import { templateChoice } from '../../resources/js/components/editor/rail/template-choice.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(JSON.stringify(templateChoice(input.declared, input.value)));
