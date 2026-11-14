// Finds the admin's words written outside the catalogue, for AdminWordsTest
// (docs/architecture/admin-design.md). Reads one JSON file of { root } and
// prints { words, atLoad, unreadable }, a word once for each place it is
// written. It parses with Vite's own parser
// (rolldown, which Vite 8 is built on), so it reads what the build reads.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { parseAst } from 'rolldown/parseAst';

const { root } = JSON.parse(readFileSync(process.argv[2], 'utf8'));

/** Wayfinder's three generated, git-ignored folders. */
const GENERATED = new Set(['actions', 'routes', 'wayfinder']);

/** Props and fields whose string is something a person reads. */
const WORD_PROPS = new Set([
    'aria-label', 'aria-description', 'aria-placeholder', 'aria-valuetext',
    'alt', 'title', 'placeholder', 'label', 'description', 'heading',
    'emptyLabel', 'confirmLabel', 'cancelLabel', 'tooltip', 'message',
]);

/** The catalogue's look-ups (resources/js/lib/i18n.ts). */
const LOOK_UPS = new Set(['__', '__n']);

const FUNCTIONS = new Set(['FunctionDeclaration', 'FunctionExpression', 'ArrowFunctionExpression']);

/** A word: a letter, and more than one character, once its spaces are folded. */
function word(text) {
    const folded = text.replace(/\s+/g, ' ').trim();

    return /\p{L}/u.test(folded) && [...folded].length > 1 ? folded : null;
}

/** The strings an expression can end as, through conditions and fallbacks only. */
function strings(node) {
    if (!node) {
        return [];
    }

    switch (node.type) {
        case 'Literal':
            return typeof node.value === 'string' ? [node.value] : [];
        case 'TemplateLiteral':
            return [node.quasis.map((q) => q.value.cooked ?? q.value.raw).join('{}')];
        case 'ConditionalExpression':
            return [...strings(node.consequent), ...strings(node.alternate)];
        case 'LogicalExpression':
            return [...strings(node.left), ...strings(node.right)];
        case 'ParenthesizedExpression':
        case 'TSAsExpression':
        case 'TSSatisfiesExpression':
        case 'TSNonNullExpression':
            return strings(node.expression);
        default:
            return [];
    }
}

function propName(key) {
    if (key?.type === 'Identifier' || key?.type === 'JSXIdentifier') {
        return key.name;
    }

    return key?.type === 'Literal' && typeof key.value === 'string' ? key.value : null;
}

function files(dir) {
    const found = [];

    for (const name of readdirSync(dir)) {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            if (!GENERATED.has(name)) {
                found.push(...files(path));
            }
        } else if (/\.tsx?$/.test(name) && !name.endsWith('.d.ts')) {
            found.push(path);
        }
    }

    return found;
}

const words = [];
const atLoad = [];
const unreadable = [];

for (const path of files(root)) {
    const file = relative(root, path).split(sep).join('/');
    const source = readFileSync(path, 'utf8');
    let program;

    try {
        program = parseAst(source, { lang: path.endsWith('.tsx') ? 'tsx' : 'ts' }, path);
    } catch {
        unreadable.push(file);
        continue;
    }

    const add = (texts) => {
        for (const text of texts) {
            const found = word(text);

            if (found !== null) {
                words.push(`${file}\t${found}`);
            }
        }
    };

    const visit = (node, inFunction) => {
        if (Array.isArray(node)) {
            node.forEach((child) => visit(child, inFunction));

            return;
        }

        if (node === null || typeof node !== 'object' || typeof node.type !== 'string') {
            return;
        }

        const inside = inFunction || FUNCTIONS.has(node.type);

        switch (node.type) {
            case 'JSXText':
                add([node.value]);
                break;
            // A child's expression: an attribute's never reaches here (below).
            case 'JSXExpressionContainer':
                add(strings(node.expression));
                break;
            case 'JSXAttribute': {
                const value = node.value?.type === 'JSXExpressionContainer' ? node.value.expression : node.value;

                if (WORD_PROPS.has(propName(node.name))) {
                    add(strings(value));
                }

                visit(value, inside);

                return;
            }
            case 'Property':
                if (!node.computed && WORD_PROPS.has(propName(node.key))) {
                    add(strings(node.value));
                }
                break;
            case 'CallExpression': {
                const callee = node.callee;

                if (callee.type === 'Identifier' && LOOK_UPS.has(callee.name) && !inFunction) {
                    atLoad.push([file, source.slice(0, node.start).split('\n').length]);
                }

                if (callee.type === 'Identifier' ? callee.name === 'toast' : callee.type === 'MemberExpression' && callee.object?.name === 'toast') {
                    add(strings(node.arguments[0]));
                }
                break;
            }
        }

        for (const value of Object.values(node)) {
            if (value !== null && typeof value === 'object') {
                visit(value, inside);
            }
        }
    };

    visit(program.body, false);
}

process.stdout.write(JSON.stringify({
    words: words.sort((a, b) => (a < b ? -1 : a > b ? 1 : 0)).map((line) => line.split('\t')),
    atLoad,
    unreadable,
}));
