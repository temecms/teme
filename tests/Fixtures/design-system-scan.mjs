// Finds what breaks the admin's design system (docs/architecture/design-system.md,
// section 9), for DesignSystemTest. Reads one JSON file of { root }, the folder
// holding js/ and css/ (resources/ in the app), and prints
// { offences, unreadable, contrast }: each offence [rule, path, match], once for
// each place it is written. It parses with Vite's own parser.
import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { parseAst } from 'rolldown/parseAst';

const { root } = JSON.parse(readFileSync(process.argv[2], 'utf8'));

/** Wayfinder's three generated, git-ignored folders. */
const GENERATED = new Set(['actions', 'routes', 'wayfinder']);

/** Where the system is implemented: R5, R7 and R9 do not apply inside. */
const SYSTEM = ['js/components/ui/', 'js/components/patterns/'];

/** The calls whose string arguments are classes. */
const CLASS_CALLS = new Set(['cn', 'cva', 'clsx', 'twMerge']);

/** The page patterns a page's root must be (R7). */
const PATTERNS = new Set(['ListPage', 'FormPage', 'SettingsPage', 'HubPage', 'DashboardPage', 'DetailPage', 'EditorPage', 'PluginAreaPage', 'Workspace', 'AuthPage']);

const RAW_CONTROLS = new Set(['button', 'select', 'textarea', 'table', 'details', 'input', 'dialog']);
const PALETTE = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
const SPACING = 'p|px|py|pt|pr|pb|pl|ps|pe|m|mx|my|mt|mr|mb|ml|ms|me|gap|gap-x|gap-y|space-x|space-y|inset|inset-x|inset-y|top|right|bottom|left|start|end|translate-x|translate-y|scroll-m|scroll-mt|scroll-mb|scroll-p';
const STATUS_HUES = { ok: 150, info: 240, warn: 85, danger: 18, ai: 300 };
const STYLE_ALLOWED = new Set(['left', 'top', 'right', 'bottom', 'transform', 'translate', 'insetInlineStart', 'insetInlineEnd']);

/** Spacing the design system itself gives one component off the 4px grid (R3). */
const SPACING_ALLOWED = { 'js/components/ui/status-pill.tsx': ['px-2.5', 'gap-1.5'], 'js/components/ui/segmented.tsx': ['p-0.5'] };

/** Colour utilities whose suffix is not a colour (R11), by utility. */
const NOT_COLOUR = {
    text: /^(caption|small|body|lead|title|display|xs|sm|base|lg|xl|[2-9]xl|left|center|right|start|end|justify|wrap|nowrap|balance|pretty|ellipsis|clip|transparent|current|inherit|white|black|shadow.*)$/,
    bg: /^(transparent|current|inherit|white|black|none|fixed|local|scroll|auto|cover|contain|center|top|bottom|left|right|left-top|left-bottom|right-top|right-bottom|no-repeat|repeat|repeat-[xy]|repeat-round|repeat-space|(linear|radial|conic|gradient|blend|origin|clip|position|size)(-.*)?)$/,
    border: /^(([trblxyse]-)?\d+|control|x|y|t|r|b|l|s|e|solid|dashed|dotted|double|hidden|none|collapse|separate|spacing(-.*)?|transparent|current|inherit|white|black)$/,
    ring: /^(\d+|inset|offset(-.*)?|transparent|current|inherit|white|black)$/,
    'ring-offset': /^(\d+|transparent|current|inherit|white|black)$/,
    outline: /^(\d+|none|hidden|solid|dashed|dotted|double|offset(-.*)?|transparent|current|inherit|white|black)$/,
    fill: /^(none|current|transparent|inherit|white|black)$/,
    stroke: /^(\d+|none|current|transparent|inherit|white|black)$/,
    from: /^(\d+%|transparent|current|inherit|white|black)$/,
    via: /^(\d+%|transparent|current|inherit|white|black)$/,
    to: /^(\d+%|transparent|current|inherit|white|black)$/,
    decoration: /^(\d+|solid|double|dotted|dashed|wavy|auto|from-font|clone|slice|transparent|current|inherit|white|black)$/,
    divide: /^([xy](-\d+)?|[xy]-reverse|\d+|solid|dashed|dotted|double|none|transparent|current|inherit|white|black)$/,
    placeholder: /^(transparent|current|inherit|white|black)$/,
    caret: /^(transparent|current|inherit|white|black|auto)$/,
    accent: /^(auto|transparent|current|inherit|white|black)$/,
};

const offences = [];
const reads = [];
const unreadable = [];
const contrast = [];
const add = (rule, path, match) => offences.push([rule, path, String(match).replace(/\s+/g, ' ').trim()]);
const inSystem = (path) => SYSTEM.some((dir) => path.startsWith(dir));

function walk(dir, test, skip = new Set()) {
    const found = [];

    if (!existsSync(dir)) {
        return found;
    }

    for (const name of readdirSync(dir)) {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            if (!skip.has(name)) {
                found.push(...walk(path, test, skip));
            }
        } else if (test(name)) {
            found.push(path);
        }
    }

    return found;
}

const rel = (path) => relative(root, path).split(sep).join('/');

// ---------------------------------------------------------------- CSS

/** The CSS's rules as { selector, media, body, start }, by brace matching. */
function cssRules(source) {
    const rules = [];
    const stack = [];
    let start = 0;

    for (let i = 0; i < source.length; i++) {
        if (source[i] === '"' || source[i] === "'") {
            i = source.indexOf(source[i], i + 1);
        } else if (source[i] === '/' && source[i + 1] === '*') {
            i = source.indexOf('*/', i + 2) + 1;
            start = i + 1;
        } else if (source[i] === '{') {
            stack.push({ selector: source.slice(start, i).trim(), open: i });
            start = i + 1;
        } else if (source[i] === '}') {
            const block = stack.pop() ?? { selector: '', open: i };
            const media = stack.map((outer) => outer.selector).join(' ');
            rules.push({ selector: block.selector, media, body: source.slice(block.open + 1, i), start: block.open });
            start = i + 1;
        } else if (source[i] === ';' && stack.length === 0) {
            start = i + 1;
        }
    }

    return rules;
}

/** The declarations written directly in a rule's body, nested blocks left out. */
function declarations(body) {
    const flat = body.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\{[^{}]*\}/g, ';');

    return flat.split(';').map((part) => part.trim()).filter((part) => /^--?[\w-]+\s*:/.test(part) || /^[a-z-]+\s*:/.test(part))
        .map((part) => [part.slice(0, part.indexOf(':')).trim(), part.slice(part.indexOf(':') + 1).trim()]);
}

/** The text between two marker comments, or ''. */
function between(source, open, close) {
    const from = source.indexOf(open);
    const to = source.indexOf(close);

    return from === -1 || to === -1 ? '' : source.slice(from, to);
}

const COLOUR_LITERAL = /#[0-9a-fA-F]{3,8}\b|\b(?:oklch|oklab|rgb|rgba|hsl|hsla|lab|lch)\(|:\s*(?:white|black)\b/;

const cssFiles = walk(join(root, 'css'), (name) => name.endsWith('.css'), new Set(['cards']));
const defined = new Set();
const themeColours = new Set();
let app = '';

for (const path of cssFiles) {
    const file = rel(path);
    const source = readFileSync(path, 'utf8');

    if (file === 'css/app.css') {
        app = source;
    }

    const tokens = between(source, '/* @tokens */', '/* @end-tokens */');
    const accent = between(source, '/* @accent */', '/* @end-accent */');
    const outsideTokens = tokens === '' ? source : source.replace(tokens, '');
    const outsideAccent = accent === '' ? source : source.replace(accent, '');

    for (const [, name] of source.matchAll(/(--[\w-]+)\s*:/g)) {
        defined.add(name);
    }

    for (const [, name] of source.matchAll(/--color-([\w-]+)\s*:/g)) {
        themeColours.add(name);
    }

    for (const rule of cssRules(outsideTokens)) {
        for (const [property, value] of declarations(rule.body)) {
            if (property.startsWith('--')) {
                continue;
            }

            if (COLOUR_LITERAL.test(`${property}: ${value}`)) {
                add('R4', file, `${property}: ${value}`);
            }

            if (property === 'font-size' && !/^var\(--text-(caption|small|body|lead|title|display)\)$/.test(value)) {
                add('R2', file, `${rule.selector} { font-size: ${value} }`);
            }

            if (/^overflow(-x|-y)?$/.test(property) && /^(auto|scroll)$/.test(value)) {
                add('R8', file, `${rule.selector} { ${property}: ${value} }`);
            }

            if (/^(transition|animation)(-duration|-timing-function)?$/.test(property)) {
                const bare = value.replace(/var\([^)]*\)/g, '');

                if (/\d+m?s\b|\b(ease|ease-in|ease-out|ease-in-out|linear)\b|cubic-bezier\(/.test(bare) || /^all\b/.test(bare)) {
                    add('R14', file, `${rule.selector} { ${property}: ${value} }`);
                }
            }
        }
    }

    for (const match of outsideAccent.matchAll(/var\(\s*--accent-[hcl]\s*\)|\b(?:ember|teal|harbour|plum)\b/gi)) {
        add('R13', file, match[0]);
    }

    for (const match of source.matchAll(/var\(\s*(--[\w-]+)/g)) {
        reads.push([file, match[1]]);
    }
}

// ---------------------------------------------------------------- the accent and contrast (R12)

/** A tiny evaluator for the token block's values: numbers, var(), calc(), clamp(), min(), max(). */
function evaluate(expression, scope) {
    const tokens = expression.match(/-?\d*\.?\d+(?:e-?\d+)?|--[\w-]+|[a-z-]+|[()+\-*/,]/gi) ?? [];
    let at = 0;
    const peek = () => tokens[at];
    const take = () => tokens[at++];

    const primary = () => {
        const token = take();

        if (token === '(') {
            const value = sum();
            take();

            return value;
        }

        if (token === '-') {
            return -primary();
        }

        if (/^-?\d*\.?\d+/.test(token)) {
            return Number(token);
        }

        if (token === 'var') {
            take();
            const name = take();
            take();

            return evaluate(String(scope[name] ?? 'NaN'), scope);
        }

        if (['calc', 'clamp', 'min', 'max'].includes(token)) {
            take();
            const args = [sum()];

            while (peek() === ',') {
                take();
                args.push(sum());
            }

            take();

            return token === 'calc' ? args[0] : token === 'min' ? Math.min(...args) : token === 'max' ? Math.max(...args) : Math.min(Math.max(args[1], args[0]), args[2]);
        }

        if (token in scope) {
            return evaluate(String(scope[token]), scope);
        }

        return Number.NaN;
    };

    const product = () => {
        let value = primary();

        while (peek() === '*' || peek() === '/') {
            value = take() === '*' ? value * primary() : value / primary();
        }

        return value;
    };

    const sum = () => {
        let value = product();

        while (peek() === '+' || peek() === '-') {
            value = take() === '+' ? value + product() : value - product();
        }

        return value;
    };

    return sum();
}

/** An oklch() value, plain or relative to another colour, as [L, C, H]. */
function colour(value, scope, depth = 0) {
    const resolved = value.trim().replace(/^var\((--[\w-]+)\)$/, (whole, name) => scope[name] ?? whole);

    if (resolved !== value.trim() && depth < 8) {
        return colour(resolved, scope, depth + 1);
    }

    const relative = resolved.match(/^oklch\(\s*from\s+(var\([^)]*\)|\S+)\s+(.+)\)$/s);

    if (relative) {
        const [l, c, h] = colour(relative[1], scope, depth + 1);
        const parts = splitChannels(relative[2]);

        return parts.slice(0, 3).map((part) => evaluate(part, { ...scope, l, c, h }));
    }

    const plain = resolved.match(/^oklch\((.+)\)$/s);

    if (!plain) {
        return null;
    }

    return splitChannels(plain[1].split('/')[0]).slice(0, 3).map((part) => evaluate(part, scope));
}

/** Three channels separated by spaces, functions kept whole. */
function splitChannels(text) {
    const parts = [];
    let depth = 0;
    let current = '';

    for (const character of text.trim()) {
        if (character === '(') {
            depth++;
        } else if (character === ')') {
            depth--;
        }

        if (/\s/.test(character) && depth === 0) {
            if (current !== '') {
                parts.push(current);
            }

            current = '';
        } else {
            current += character;
        }
    }

    return current === '' ? parts : [...parts, current];
}

const LMS = (L, C, H) => {
    const a = C * Math.cos((H * Math.PI) / 180);
    const b = C * Math.sin((H * Math.PI) / 180);

    return [L + 0.3963377774 * a + 0.2158037573 * b, L - 0.1055613458 * a - 0.0638541728 * b, L - 0.0894841775 * a - 1.291485548 * b].map((v) => v ** 3);
};

/** Linear sRGB, unclipped. */
const linear = ([L, C, H]) => {
    const [l, m, s] = LMS(L, C, H);

    return [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s, -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s];
};

const clip = (rgb) => rgb.map((v) => Math.min(1, Math.max(0, v)));
const luminance = (lch) => { const [r, g, b] = clip(linear(lch)); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const ratio = (one, two) => { const [a, b] = [luminance(one), luminance(two)]; return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05); };

const oklab = ([r, g, b]) => {
    const [l, m, s] = [0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b, 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b, 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b].map(Math.cbrt);

    return [0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s, 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s, 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s];
};

/** How far the browser moves a colour to show it on an ordinary screen. */
const clipped = (lch) => {
    const [L, C, H] = lch;
    const exact = [L, C * Math.cos((H * Math.PI) / 180), C * Math.sin((H * Math.PI) / 180)];
    const shown = oklab(clip(linear(lch)));

    return Math.hypot(exact[0] - shown[0], exact[1] - shown[1], exact[2] - shown[2]);
};

/** The pairs of sections 2.2.2 and 2.4: [text or mark, ground, needs]. */
const PAIRS = [
    ['--on-accent', '--accent', 4.5], ['--on-accent', '--accent-hover', 4.5],
    ['--accent', '--surface', 3], ['--accent', '--page', 3],
    ['--ring', '--surface', 3], ['--ring', '--page', 3], ['--ring', '--chrome', 3],
    ...['--surface', '--page', '--chrome', '--surface-2', '--accent-soft'].map((ground) => ['--accent-ink', ground, 4.5]),
    ['--on-accent-soft', '--accent-soft', 4.5], ['--on-accent-soft', '--accent-soft-2', 4.5],
    ['--ink', '--page', 4.5], ['--ink', '--surface', 4.5],
    ['--ink-2', '--surface', 4.5], ['--ink-2', '--surface-3', 4.5],
    ...['--surface', '--page', '--chrome', '--surface-2', '--surface-3', '--accent-soft'].map((ground) => ['--ink-3', ground, 4.5]),
    ['--page', '--ink', 4.5],
    ...['ok', 'info', 'warn', 'danger', 'ai'].map((tone) => [`--${tone}-ink`, `--${tone}-soft`, 4.5]),
    ...['--surface', '--page', '--surface-2', '--chrome'].map((ground) => ['--edge', ground, 3]),
];

/** Under more contrast, the quiet text and the boundaries need 4.5 on every ground they sit on. */
const MORE_PAIRS = [
    ...PAIRS.filter(([fg]) => !['--edge'].includes(fg)),
    ...['--surface', '--page', '--surface-2', '--chrome'].flatMap((ground) => [['--edge', ground, 4.5], ['--line', ground, 4.5]]),
];

function tokenScope(source, selector, media) {
    const scope = {};

    for (const rule of cssRules(source)) {
        if (rule.selector === selector && rule.media === media) {
            for (const [name, value] of declarations(rule.body)) {
                if (name.startsWith('--')) {
                    scope[name] = value;
                }
            }
        }
    }

    return scope;
}

if (app !== '') {
    const light = tokenScope(app, ':root', '');
    const dark = { ...light, ...tokenScope(app, '.dark', '') };
    const more = '@media (prefers-contrast: more)';
    const modes = {
        light: [light, PAIRS],
        dark: [dark, PAIRS],
        'light, more contrast': [{ ...light, ...tokenScope(app, ':root', more) }, MORE_PAIRS],
        'dark, more contrast': [{ ...dark, ...tokenScope(app, ':root', more), ...tokenScope(app, '.dark', more) }, MORE_PAIRS],
    };

    for (const [mode, [scope, pairs]] of Object.entries(modes)) {
        for (const [fg, bg, needs] of pairs) {
            const [one, two] = [colour(scope[fg] ?? '', scope), colour(scope[bg] ?? '', scope)];

            if (one === null || two === null || [...one, ...two].some(Number.isNaN)) {
                add('R12', 'css/app.css', `${mode}: ${fg} on ${bg} cannot be read`);
                continue;
            }

            const found = Math.round(ratio(one, two) * 100) / 100;
            contrast.push([mode, fg, bg, found, needs]);

            if (found < needs) {
                add('R12', 'css/app.css', `${mode}: ${fg} on ${bg} is ${found}, needs ${needs}`);
            }
        }

        const solid = colour(scope['--accent'] ?? '', scope);

        if (solid !== null && clipped(solid) > 0.04) {
            add('R12', 'css/app.css', `${mode}: --accent is clipped by ${Math.round(clipped(solid) * 1000) / 1000}, more than 0.04`);
        }
    }

    const hue = evaluate(String(light['--accent-h'] ?? 'NaN'), light);

    for (const [tone, toneHue] of Object.entries(STATUS_HUES)) {
        const distance = Math.min(Math.abs(hue - toneHue), 360 - Math.abs(hue - toneHue));

        if (!(distance >= 20)) {
            add('R12', 'css/app.css', `--accent-h ${hue} is ${distance} degrees from ${tone}, needs 20`);
        }
    }

    if (!/@media\s*\(prefers-reduced-motion:\s*reduce\)/.test(app)) {
        add('R14', 'css/app.css', 'no prefers-reduced-motion block');
    }
}

// ---------------------------------------------------------------- the admin's code

/** Splits a class token into its variants and its utility, `!` and a leading `-` set aside. */
function utility(token) {
    let depth = 0;
    let cut = -1;

    for (let i = 0; i < token.length; i++) {
        if (token[i] === '[' || token[i] === '(') {
            depth++;
        } else if (token[i] === ']' || token[i] === ')') {
            depth--;
        } else if (token[i] === ':' && depth === 0) {
            cut = i;
        }
    }

    const variants = cut === -1 ? '' : token.slice(0, cut);
    const base = token.slice(cut + 1).replace(/^!/, '').replace(/!$/, '').replace(/^-/, '');

    return { variants, base };
}

const CLASSLIKE = /^[a-z0-9!@*\-[\]():/._%,=&>~#'"+]+$/;

/** A string that reads as a list of classes: lower case, no address, one hyphen or colon at least. */
const classLike = (text) => {
    const parts = text.trim().split(/\s+/).filter(Boolean);

    return parts.length > 0 && text.length < 2000 && !/:\/\//.test(text) && parts.every((part) => CLASSLIKE.test(part) && !part.startsWith('/') && !part.startsWith('.') && !part.startsWith('@'))
        && parts.some((part) => part.includes('-') || part.includes(':'));
};

function classRules(text, file) {
    const parts = text.trim().split(/\s+/).filter(Boolean);
    const based = parts.map((part) => ({ part, ...utility(part) }));

    for (const { part, variants, base } of based) {
        const arbitrary = base.match(/^([a-z0-9-]+)-\[(.+)\]$/);

        if (arbitrary || /^\[.+\]$/.test(base)) {
            const [, name, value] = arbitrary ?? [null, null, null];

            if (name === 'text' && /^(\d|calc|length:|clamp)/.test(value)) {
                add('R2', file, part);
            } else if (!/^grid-cols-\[minmax\(0,1fr\)(_auto)?\]$/.test(base) && !/^aspect-\[\d+\/\d+\]$/.test(base) && !/^transition-\[[a-z,-]+\]$/.test(base)
                && !(name === 'tracking' && inSystem(file))) {
                add('R1', file, part);
            }
        }

        if (/^text-(xs|sm|base|lg|xl|[2-9]xl)(\/.+)?$/.test(base)) {
            add('R2', file, part);
        }

        const allowedSpacing = SPACING_ALLOWED[file] ?? [];

        if (new RegExp(`^(${SPACING})-(1\\.5|2\\.5|3\\.5|7|9|11|14|15)$`).test(base) && !allowedSpacing.includes(base)) {
            add('R3', file, part);
        }

        const half = base.match(new RegExp(`^(${SPACING})-0\\.5$`));

        if (half && !['gap', 'gap-x', 'gap-y'].includes(half[1]) && !allowedSpacing.includes(base)) {
            add('R3', file, part);
        }

        if (new RegExp(`^(bg|text|border(-[trblxyse])?|ring|fill|stroke|from|via|to|decoration|outline|shadow|divide|accent|caret|placeholder)-(${PALETTE})-\\d{2,3}(/\\d+)?$`).test(base)
            || /^(bg|text|border(-[trblxyse])?|ring|outline|fill|stroke|from|via|to|shadow|divide|decoration|caret|placeholder)-(white|black)(\/\d+)?$/.test(base)) {
            add('R4', file, part);
        }

        const rounded = base.match(/^rounded(-(?:t|r|b|l|s|e|tl|tr|br|bl|ss|se|es|ee))?(?:-(.+))?$/);

        if ((rounded && (rounded[2] === undefined || ['none', '2xl', '3xl', '4xl'].includes(rounded[2])) && !(rounded[2] === 'none' && ['-s', '-e'].includes(rounded[1]) && (inSystem(file) || file === 'js/pages/dev/catalogue.tsx'))
            // The save bar is square where it spans a phone's foot (DS 4.12); its file alone may say so.
            && !(rounded[2] === 'none' && rounded[1] === undefined && file === 'js/components/patterns/save-bar.tsx'))
            || /^shadow(-(2xs|xs|sm|md|lg|xl|2xl))?$/.test(base)
            || /^font-(thin|extralight|light|bold|extrabold|black)$/.test(base)
            || /^z-\d+$/.test(base)
            || /^leading-\d+$/.test(base)
            || /^tracking-(tighter|tight|wide|wider|widest)$/.test(base)) {
            add('R6', file, part);
        }

        if (/^overflow(-x|-y)?-(auto|scroll)$/.test(base) && file !== 'js/components/ui/scroll-area.tsx') {
            add('R8', file, part);
        }

        if (base.startsWith('animate-') && base !== 'animate-none' && !variants.split(':').includes('motion-safe') && !inSystem(file)) {
            add('R14', file, part);
        }

        if (/^(transition|transition-all)$/.test(base) || /^duration-\d+$/.test(base) || /^ease-(in|out|in-out|linear)$/.test(base)) {
            add('R14', file, part);
        }

        if (/\b(ember|teal|harbour|plum)\b/i.test(base)) {
            add('R13', file, part);
        }

        for (const [, name] of part.matchAll(/(?<!var)\((--[\w-]+)\)/g)) {
            reads.push([file, name]);
        }

        const coloured = base.match(/^(bg|text|border(?:-[trblxyse])?|ring-offset|ring|outline|fill|stroke|from|via|to|decoration|divide|placeholder|caret|accent)-([a-z][a-z0-9-]*?)(\/\d+)?$/);

        if (coloured) {
            const kind = coloured[1].startsWith('border') ? 'border' : coloured[1];

            if (!themeColours.has(coloured[2]) && !(NOT_COLOUR[kind]?.test(coloured[2]) ?? false)) {
                add('R11', file, part);
            }
        }
    }

    const removed = based.filter(({ base }) => base === 'outline-none' || base === 'outline-hidden');
    const replaced = based.some(({ variants, base }) => variants.split(':').includes('focus-visible') && /^(outline|ring)(-|$)/.test(base) && base !== 'outline-none' && base !== 'outline-hidden');

    if (removed.length > 0 && !replaced) {
        for (const { part } of removed) {
            add('R10', file, part);
        }
    }

    if (!inSystem(file) && based.some(({ base }) => /^bg-(ok|warn|danger|info|warning|success|destructive)-(soft|muted)$/.test(base)) && based.some(({ base }) => /^(border|p|px|py)(-|$)/.test(base))) {
        add('R9', file, `classes: ${parts.filter((part) => /^(bg-|border|p-|px-|py-)/.test(utility(part).base)).join(' ')}`);
    }
}

/** Every string an expression holds, deep. */
function strings(node, found = []) {
    if (node === null || typeof node !== 'object') {
        return found;
    }

    if (node.type === 'Literal' && typeof node.value === 'string') {
        found.push(node.value);
    } else if (node.type === 'TemplateLiteral') {
        found.push(node.quasis.map((quasi) => quasi.value.cooked ?? quasi.value.raw).join(' '));
    }

    for (const [key, value] of Object.entries(node)) {
        if (key !== 'type' && value !== null && typeof value === 'object') {
            strings(value, found);
        }
    }

    return found;
}

const propName = (key) => key?.type === 'Identifier' || key?.type === 'JSXIdentifier' ? key.name : key?.type === 'Literal' ? String(key.value) : null;
const FUNCTIONS = new Set(['FunctionDeclaration', 'FunctionExpression', 'ArrowFunctionExpression']);

/** R7: a page's default export must return a page pattern, and set no frame of its own. */
function pageFrame(program, file) {
    const declared = new Map();

    for (const statement of program.body) {
        if (statement.type === 'FunctionDeclaration' && statement.id) {
            declared.set(statement.id.name, statement);
        }

        if (statement.type === 'VariableDeclaration') {
            for (const each of statement.declarations) {
                if (each.id?.type === 'Identifier' && each.init && FUNCTIONS.has(each.init.type)) {
                    declared.set(each.id.name, each.init);
                }
            }
        }
    }

    const exported = program.body.find((statement) => statement.type === 'ExportDefaultDeclaration')?.declaration;
    const page = exported?.type === 'Identifier' ? declared.get(exported.name) : exported;

    if (!page || !FUNCTIONS.has(page.type)) {
        return;
    }

    const returned = [];
    const visit = (node) => {
        if (node === null || typeof node !== 'object') {
            return;
        }

        if (node !== page && FUNCTIONS.has(node.type)) {
            return;
        }

        if (node.type === 'ReturnStatement' && node.argument) {
            returned.push(node.argument);
        }

        for (const [key, value] of Object.entries(node)) {
            if (key !== 'type' && value !== null && typeof value === 'object') {
                visit(value);
            }
        }
    };

    if (page.body?.type === 'BlockStatement') {
        visit(page.body);
    } else {
        returned.push(page.body);
    }

    const elementName = (element) => {
        const name = element.openingElement.name;

        return name.type === 'JSXIdentifier' ? name.name : name.type === 'JSXMemberExpression' ? `${name.object.name}.${name.property.name}` : 'other';
    };
    const unwrap = (node) => {
        let value = node;

        while (value?.type === 'ParenthesizedExpression') {
            value = value.expression;
        }

        if (value?.type === 'ConditionalExpression') {
            return [...unwrap(value.consequent), ...unwrap(value.alternate)];
        }

        if (value?.type === 'LogicalExpression') {
            return unwrap(value.right);
        }

        if (value?.type === 'JSXFragment' || (value?.type === 'JSXElement' && ['Fragment', 'React.Fragment'].includes(elementName(value)))) {
            return value.children.filter((child) => child.type === 'JSXElement').flatMap(unwrap);
        }

        return value?.type === 'JSXElement' ? [value] : [];
    };

    const roots = returned.flatMap(unwrap).filter((element) => elementName(element) !== 'Head');

    if (roots.length === 0) {
        add('R7', file, 'root:none');
    }

    const frame = (element) => {
        const className = element.openingElement.attributes.find((attribute) => attribute.type === 'JSXAttribute' && propName(attribute.name) === 'className');

        for (const text of className ? strings(className.value) : []) {
            for (const part of text.split(/\s+/).filter(Boolean)) {
                const { base } = utility(part);

                if (/^(p|px|py|max-w)-/.test(base) || base === 'mx-auto' || base === 'container') {
                    add('R7', file, `frame:${part}`);
                }
            }
        }
    };

    for (const element of roots) {
        const name = elementName(element);

        if (!PATTERNS.has(name)) {
            add('R7', file, `root:${name}`);
        }

        frame(element);

        for (const child of element.children.filter((each) => each.type === 'JSXElement')) {
            frame(child);
        }
    }
}

const definedInCode = new Set();
const jsFiles = walk(join(root, 'js'), (name) => /\.tsx?$/.test(name) && !name.endsWith('.d.ts'), GENERATED);

for (const path of jsFiles) {
    const file = rel(path);
    const source = readFileSync(path, 'utf8');
    let program;

    try {
        program = parseAst(source, { lang: path.endsWith('.tsx') ? 'tsx' : 'ts' }, path);
    } catch {
        unreadable.push(file);
        continue;
    }

    // inClass: inside a className, a class call or a className field, whose strings are read as classes already.
    const visit = (node, inClass) => {
        if (Array.isArray(node)) {
            node.forEach((child) => visit(child, inClass));

            return;
        }

        if (node === null || typeof node !== 'object' || typeof node.type !== 'string') {
            return;
        }

        if (['ImportDeclaration', 'ExportAllDeclaration'].includes(node.type) || (node.type === 'ExportNamedDeclaration' && node.source)) {
            return;
        }

        let classes = inClass;

        switch (node.type) {
            case 'JSXAttribute': {
                const name = propName(node.name);

                if (name === 'className' && !inClass) {
                    strings(node.value).forEach((text) => classRules(text, file));
                    classes = true;
                }

                if (name === 'role' && node.value?.type === 'Literal' && node.value.value === 'note' && !inSystem(file)) {
                    add('R9', file, 'role="note"');
                }

                if (name === 'style' && node.value?.type === 'JSXExpressionContainer' && node.value.expression.type === 'ObjectExpression') {
                    for (const property of node.value.expression.properties) {
                        if (property.type !== 'Property') {
                            continue;
                        }

                        const key = propName(property.key);
                        const value = property.value.type === 'Literal' ? String(property.value.value) : null;

                        if (key?.startsWith('--')) {
                            definedInCode.add(key);
                        } else if (!STYLE_ALLOWED.has(key)) {
                            add('R15', file, `style: ${key}`);
                        }

                        if (key === 'fontSize') {
                            add('R2', file, 'style: fontSize');
                        }

                        if (/^overflow(X|Y)?$/.test(key ?? '') && /^(auto|scroll)$/.test(value ?? '')) {
                            add('R8', file, `style: ${key}: ${value}`);
                        }

                        if (/^(white|black)$/.test(value ?? '')) {
                            add('R4', file, `style: ${key}: ${value}`);
                        }
                    }
                }

                break;
            }
            case 'JSXOpeningElement': {
                const name = node.name.type === 'JSXIdentifier' ? node.name.name : null;

                if (name && RAW_CONTROLS.has(name) && !inSystem(file)) {
                    const type = node.attributes.find((attribute) => attribute.type === 'JSXAttribute' && propName(attribute.name) === 'type');
                    const kind = type?.value?.type === 'Literal' ? String(type.value.value) : null;

                    if (!(name === 'input' && ['hidden', 'file'].includes(kind))) {
                        add('R5', file, kind ? `<${name} type="${kind}">` : `<${name}>`);
                    }
                }

                break;
            }
            case 'CallExpression': {
                if (node.callee.type === 'Identifier' && CLASS_CALLS.has(node.callee.name) && !inClass) {
                    strings(node.arguments).forEach((text) => classRules(text, file));
                    classes = true;
                }

                if (node.callee.type === 'MemberExpression' && propName(node.callee.property) === 'setProperty' && node.arguments[0]?.type === 'Literal') {
                    definedInCode.add(String(node.arguments[0].value));
                }

                break;
            }
            case 'Property': {
                const key = propName(node.key);

                if ((key === 'className' || key === 'class') && !inClass) {
                    strings(node.value).forEach((text) => classRules(text, file));
                    classes = true;
                }

                if (key?.startsWith('--')) {
                    definedInCode.add(key);
                }

                break;
            }
            case 'Literal':
            case 'TemplateLiteral': {
                const text = strings(node)[0];

                if (typeof text === 'string') {
                    if (!inClass && classLike(text)) {
                        classRules(text, file);
                    }

                    if (/(?<![\w&])#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})(?![\w-])/.test(text) || /\b(?:oklch|oklab|rgb|rgba|hsl|hsla|lab|lch)\(/.test(text)
                        || /\b(?:color|background|background-color|border-color|fill|stroke)\s*:\s*(?:white|black)\b/.test(text)) {
                        add('R4', file, text.length > 80 ? `${text.slice(0, 77)}...` : text);
                    }

                    for (const match of text.matchAll(/var\(\s*--accent-[hcl]\s*\)/g)) {
                        add('R13', file, match[0]);
                    }

                    for (const [, name] of text.matchAll(/var\(\s*(--[\w-]+)/g)) {
                        reads.push([file, name]);
                    }
                }

                return;
            }
        }

        for (const [key, value] of Object.entries(node)) {
            if (key !== 'type' && value !== null && typeof value === 'object') {
                visit(value, classes);
            }
        }
    };

    visit(program.body, false);

    if (file.startsWith('js/pages/') && !file.startsWith('js/pages/dev/')) {
        pageFrame(program, file);
    }
}

for (const [file, name] of reads) {
    if (!defined.has(name) && !definedInCode.has(name) && !/^--(radix-|tw-|spacing$|default-)/.test(name)) {
        add('R11', file, `var(${name})`);
    }
}

offences.sort((a, b) => a.join('\t').localeCompare(b.join('\t')));
process.stdout.write(JSON.stringify({ offences, unreadable: unreadable.sort(), contrast }));
