/** What the server shares as `brand`: the shell's mark and the line under the site's name. */
export type Brand = {
    /** One capital letter or digit, from the site's title. */
    mark: string;
    product: string;
    /** Null until someone has signed in. */
    version: string | null;
    environment: string;
};
