// Prints coreChecks() for one JSON file of { checks }, or imageCardCount()
// and internalLinkCount() for { document, ownId }, for CoreChecksTest;
// { withheld } gives each node's sentence, { line } the search checks' one
// line, { source } where the description comes from. Node strips the
// TypeScript types itself; checks.ts imports only types.
import { readFileSync } from 'node:fs';
import { coreChecks, descriptionSource, imageCardCount, internalLinkCount, searchCheckLine, withheldMessage } from '../../resources/js/components/editor/rail/checks.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(JSON.stringify(input.withheld !== undefined
    ? input.withheld.map(withheldMessage)
    : input.checks !== undefined
      ? coreChecks(input.checks)
      : input.line !== undefined
        ? searchCheckLine(coreChecks(input.line).filter((check) => check.search))
        : input.source !== undefined
          ? { says: descriptionSource(input.source) }
          : { images: imageCardCount(input.document), links: internalLinkCount(input.document, input.ownId) }));
