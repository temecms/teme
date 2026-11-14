// The admin's words: the same catalogue PHP's __() reads, lang/{locale}.json,
// keyed by the English text (docs/architecture/admin-design.md). Look a word
// up while drawing, never where a module loads: a module's top level runs
// before the first page's words arrive.
// Later (I-162): a second admin language fills messages; until then English is the key and the word.

/** The catalogue a page carries in its `i18n` prop. */
export type Words = {
    locale: string;
    messages: Record<string, string>;
    version: string;
};

/** Values for a line's `:placeholders`, by name. */
export type Replacements = Record<string, string | number>;

/** config/app.php's fallback_locale: Laravel chooses a missing line's plural form by its rules. */
const FALLBACK_LOCALE = 'en';

/** Plural categories in the order Laravel's forms are written: one before other. */
const CATEGORY_ORDER = ['zero', 'one', 'two', 'few', 'many', 'other'];

/** Laravel's condition on a form: `{0}`, `[1,19]`, `[20,*]`. */
const CONDITION = /^[{[]([-?\d|*,.]*)[}\]]([\s\S]*)$/;

let current: Words = { locale: FALLBACK_LOCALE, messages: {}, version: '' };

/** Puts a page's words in use; app.tsx calls it with every page's `i18n`. */
export function setWords(words: Words | undefined): void {
    if (words !== undefined) {
        current = words;
    }
}

/** The word for an English key, its placeholders filled; the key itself when the catalogue has none, as Laravel's __(). */
export function __(key: string, replace: Replacements = {}): string {
    return fill(line(key) || key, replace);
}

/**
 * The form of a counted line that fits `count`, as Laravel's trans_choice():
 * a matching `{n}` or `[from,to]` form first, else the form for the plural
 * category. `:count` is filled unless given.
 */
export function __n(
    key: string,
    count: number,
    replace: Replacements = {},
): string {
    const translated = line(key);
    const locale =
        translated && translated !== key ? current.locale : FALLBACK_LOCALE;

    return fill(choose(translated || key, count, locale), {
        count,
        ...replace,
    });
}

/** The catalogue's own line for a key, never a member every object inherits, such as `toString`. */
function line(key: string): string | undefined {
    return Object.hasOwn(current.messages, key)
        ? current.messages[key]
        : undefined;
}

/** Laravel's makeReplacements(): `:name`, `:Name` and `:NAME`, the longest first at each place, a filled value never filled again (PHP's strtr()). */
function fill(line: string, replace: Replacements): string {
    const pairs = new Map<string, string>();

    for (const [name, raw] of Object.entries(replace)) {
        const value = String(raw);

        pairs.set(`:${upperFirst(name)}`, upperFirst(value));
        pairs.set(`:${name.toUpperCase()}`, value.toUpperCase());
        pairs.set(`:${name}`, value);
    }

    if (pairs.size === 0) {
        return line;
    }

    const names = [...pairs.keys()]
        .sort((a, b) => b.length - a.length)
        .map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

    return line.replace(
        new RegExp(names.join('|'), 'g'),
        (found) => pairs.get(found) ?? found,
    );
}

/** Laravel's MessageSelector::choose(). */
function choose(line: string, count: number, locale: string): string {
    const segments = line.split('|');

    for (const segment of segments) {
        const match = CONDITION.exec(segment);

        if (match !== null && holds(match[1], count)) {
            return match[2].trim();
        }
    }

    const forms = segments.map((segment) =>
        segment.replace(/^[{[][-?\d|*,.]*[}\]]/, ''),
    );
    const index = pluralIndex(count, locale);

    return forms.length === 1 || forms[index] === undefined
        ? forms[0]
        : forms[index];
}

/** Laravel's extractFromString(): whether a `{n}` or `[from,to]` condition holds for a count. */
function holds(condition: string, count: number): boolean {
    const comma = condition.indexOf(',');

    if (comma !== -1) {
        const from = condition.slice(0, comma);
        const to = condition.slice(comma + 1);

        if (
            (to === '*' && count >= Number(from)) ||
            (from === '*' && count <= Number(to)) ||
            (count >= Number(from) && count <= Number(to))
        ) {
            return true;
        }
    }

    return condition !== '' && Number(condition) === count;
}

/** A form's place for a count: the locale's plural categories in CLDR order, which is Laravel's getPluralIndex() for English. */
function pluralIndex(count: number, locale: string): number {
    const rules = new Intl.PluralRules(locale);
    const categories = [...rules.resolvedOptions().pluralCategories].sort(
        (a, b) => CATEGORY_ORDER.indexOf(a) - CATEGORY_ORDER.indexOf(b),
    );

    return Math.max(0, categories.indexOf(rules.select(Math.abs(count))));
}

function upperFirst(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}
