/**
 * The site's shortest password, read from the rules the server sends for
 * the browser's password manager ("minlength: 12; maxlength: 256;"), so a
 * field's hint names the number the site's setting holds. Null when the
 * rules name none.
 */
export function passwordMinimum(rules: string): number | null {
    const found = /minlength:\s*(\d+)/.exec(rules);

    return found === null ? null : Number(found[1]);
}
