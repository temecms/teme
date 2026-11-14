// Lets Node load the editor's TypeScript the way Vite does, for the tests
// that run the editor's own code (I-452): the `@/` alias, relative imports
// written without an extension, and card-views.tsx, whose React views Node
// cannot strip, as an empty table of views. The tests want the schema, never
// a view. Import this first, then load the editor's modules with a dynamic
// import(): static imports are resolved before any hook is registered.
import { existsSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const source = resolve(dirname(fileURLToPath(import.meta.url)), '../../resources/js');

/** The file an import without an extension names, as Vite finds it. */
function fileFor(base) {
    for (const file of [`${base}.ts`, `${base}.tsx`, resolve(base, 'index.ts')]) {
        if (existsSync(file)) {
            return file;
        }
    }

    return null;
}

registerHooks({
    resolve(specifier, context, nextResolve) {
        let base = null;

        if (specifier.startsWith('@/')) {
            base = resolve(source, specifier.slice(2));
        } else if (/^\.\.?\//.test(specifier) && !/\.[cm]?[jt]sx?$/.test(specifier) && context.parentURL?.startsWith('file:')) {
            base = resolve(dirname(fileURLToPath(context.parentURL)), specifier);
        }

        const file = base === null ? null : fileFor(base);

        return nextResolve(file === null ? specifier : pathToFileURL(file).href, context);
    },
    load(url, context, nextLoad) {
        if (url.endsWith('/components/editor/cards/card-views.tsx')) {
            return {
                format: 'module',
                shortCircuit: true,
                source: 'export const cardViews = {}; export const cardSettings = {}; export const UnknownCard = () => null;',
            };
        }

        if (url.endsWith('.tsx')) {
            throw new Error(`${url} is a React file, which Node cannot load: keep views out of the modules the tests run.`);
        }

        return nextLoad(url, context);
    },
});
