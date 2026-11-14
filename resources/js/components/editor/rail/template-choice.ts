/** A template the active theme declares for the item's type (EditorSession). */
export type ThemeTemplate = { slug: string; label: string };

/**
 * The Template select's options after "Default", which SelectField adds,
 * and the chosen template when the current theme lacks it (I-443): it stays
 * stored, the page uses the next template down, and it's offered here so the
 * select can still show it.
 */
export function templateChoice(
    declared: ThemeTemplate[],
    value: string,
): { options: { value: string; label: string }[]; missing: string | null } {
    const missing =
        value !== '' && !declared.some((template) => template.slug === value)
            ? value
            : null;

    return {
        options: [
            ...declared.map((template) => ({
                value: template.slug,
                label: template.label,
            })),
            ...(missing === null
                ? []
                : [
                      {
                          value: missing,
                          label: `${missing} (not in this theme)`,
                      },
                  ]),
        ],
        missing,
    };
}
