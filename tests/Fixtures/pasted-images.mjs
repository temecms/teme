// Prints what the editor makes of a pasted image (I-453), for
// PastedImagesTest: the node's two parse rules and the attributes they read,
// through the schema the editor builds; why an address can't be imported,
// and the label its box shows; each document as it is saved, checked against
// the editor's schema; and the node's own HTML. Its argument is a JSON file
// of { manifest, images, figures, sources, documents }; a figure is
// { images, caption, holds }, holds naming what else it has (table, ul, ol,
// blockquote, pre). Node has no DOM, so a pasted element is a stand-in
// answering the three calls the rules make.
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const { getSchema } = await import('@tiptap/core');
const { temeExtensions } = await import('../../resources/js/components/editor/schema.ts');
const { PASTED_IMAGE, PastedImage, pastedImageLabel, pastedImageProblem, withoutPastedImages } = await import(
    '../../resources/js/components/editor/pasted-images.ts'
);

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const schema = getSchema([...temeExtensions(input.manifest.cards), PastedImage]);
const type = schema.nodes[PASTED_IMAGE];
const [figureRule, imageRule] = type.spec.parseDOM;

const element = (attributes) => ({ getAttribute: (name) => attributes[name] ?? null });
const figure = ({ images, caption, holds = [] }) => ({
    querySelectorAll: () => images.map(element),
    querySelector: (selector) => {
        if (selector === 'figcaption') {
            return caption === null ? null : { textContent: caption };
        }

        // Any other selector asks what else the figure holds.
        return selector.split(',').some((tag) => holds.includes(tag.trim())) ? {} : null;
    },
});

// A document the editor can hold, as it is saved, which the editor must be able to load again.
const saved = (json) => {
    const stripped = withoutPastedImages(schema.nodeFromJSON(json).toJSON());
    schema.nodeFromJSON(stripped).check();

    return stripped;
};

process.stdout.write(
    JSON.stringify({
        rules: type.spec.parseDOM.map((rule) => ({ tag: rule.tag, priority: rule.priority ?? 50 })),
        images: input.images.map((attributes) => imageRule.getAttrs(element(attributes))),
        figures: input.figures.map((spec) => figureRule.getAttrs(figure(spec))),
        problems: input.sources.map((src) => pastedImageProblem(src)),
        labels: input.sources.map((src) => pastedImageLabel(src)),
        documents: input.documents.map(saved),
        html: type.spec.toDOM(type.create({ src: 'https://photos.example/harbour.jpg', alt: 'The harbour at dawn' })),
    }),
);
