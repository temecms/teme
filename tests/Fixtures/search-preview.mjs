// Prints what the editor's previews make of one JSON file, for
// EditorPreviewTest: { preview } gives the search title, the description and
// the social card; { address, siteHost } the canonical address's problem;
// { trail } the trail a theme draws; { picked } the category that stands for
// the item; { listing } whether search engines may list the item. Node strips the
// TypeScript types itself; both modules import only types.
import { readFileSync } from 'node:fs';
import { fillTitle } from '../../resources/js/lib/title-template.ts';
import { canonicalAddressProblem, kickerName, listing, pickedCategory, searchDescription, searchTitle, socialCard, trailOf } from '../../resources/js/lib/search-preview.ts';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
let output;

if (input.preview !== undefined) {
    const { taxonomies, terms, ...rest } = input.preview;
    const preview = { ...rest, term: kickerName(taxonomies, terms, rest.fields) };

    output = { title: searchTitle(preview, fillTitle).text, description: searchDescription(preview).text, social: socialCard(preview) };
} else if (input.address !== undefined) {
    output = { problem: canonicalAddressProblem(input.address, input.siteHost) };
} else if (input.trail !== undefined) {
    output = { trail: trailOf(input.trail.terms, input.trail.categoryId, input.trail.title) };
} else if (input.picked !== undefined) {
    output = { picked: pickedCategory(input.picked.taxonomies, input.picked.terms, input.picked.fields) };
} else {
    output = { listing: listing(input.listing) };
}

process.stdout.write(JSON.stringify(output));
