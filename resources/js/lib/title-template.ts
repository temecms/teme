/**
 * Teme's title tokens, in the order Search and social lists them: the
 * server's TitleTemplate::TOKENS (I-851).
 */
export const TITLE_TOKENS = [
    'title',
    'site',
    'tagline',
    'sep',
    'page',
    'term',
    'author',
    'type',
    'excerpt',
    'date',
    'year',
    'search',
];

/**
 * A title template filled in exactly as TitleTemplate::fill() fills it on
 * the server (I-290): the template cut at each {sep}; in each piece every
 * token replaced in one pass, an unknown or missing one by nothing; white
 * space run together and trimmed; empty pieces dropped; the rest joined by
 * the separator, a space on each side, or by one space when it is empty.
 */
export function fillTitle(
    template: string,
    tokens: Record<string, string>,
    separator: string,
): string {
    const pieces = template
        .split('{sep}')
        .map((piece) =>
            piece
                .replace(/\{([a-z]+)\}/g, (_match, name: string) =>
                    name === 'sep' ? '' : (tokens[name] ?? ''),
                )
                .replace(/\s+/gu, ' ')
                .trim(),
        )
        .filter((piece) => piece !== '');
    const joiner = separator.trim();

    return pieces.join(joiner === '' ? ' ' : ` ${joiner} `);
}
