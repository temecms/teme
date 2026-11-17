/**
 * The one show/hide rule of a plugin's forms and settings pages
 * (docs/plans/plugin-sockets-2.md, A2), line for line as the server's
 * App\Domain\Plugins\Area\VisibleWhen: every key must match, a list matches
 * any of its values, and a hidden field counts as empty for the fields
 * after it.
 */
export type VisibleWhenRule = Record<string, unknown>;

/** A value as the rule compares it: a list of strings. */
function flat(value: unknown): string[] {
    const list = Array.isArray(value) ? value : [value];

    return list
        .filter(
            (one) =>
                one === null ||
                one === undefined ||
                ['string', 'number', 'boolean'].includes(typeof one),
        )
        .map((one) =>
            typeof one === 'boolean'
                ? one
                    ? 'true'
                    : 'false'
                : one === null || one === undefined
                  ? ''
                  : String(one),
        );
}

/** Whether every condition holds against these values. */
export function shows(
    when: VisibleWhenRule | null | undefined,
    values: Record<string, unknown>,
): boolean {
    for (const [key, wanted] of Object.entries(when ?? {})) {
        const have = flat(values[key] ?? null);
        const want = flat(wanted);

        if (!want.some((one) => have.includes(one))) {
            return false;
        }
    }

    return true;
}

/**
 * The keys of the fields that show, in declared order; a hidden field
 * counts as empty for the fields after it.
 */
export function visibleKeys(
    fields: { key: string; visibleWhen?: VisibleWhenRule | null }[],
    values: Record<string, unknown>,
): Set<string> {
    const kept: Record<string, unknown> = {};
    const shown = new Set<string>();

    for (const field of fields) {
        if (shows(field.visibleWhen, kept)) {
            kept[field.key] = values[field.key] ?? null;
            shown.add(field.key);
        }
    }

    return shown;
}
