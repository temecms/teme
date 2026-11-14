/** A term on its taxonomy's screen. */
export type TermRow = {
    id: string;
    name: string;
    slug: string;
    description: string;
    /** "Parent › Child", for a search's rows and the edit sheet. */
    path: string;
    /** How far under a top-level term it sits; 0 in a search. */
    depth: number;
    parentId: string | null;
    parentName: string | null;
    /** How many terms sit directly under it. */
    children: number;
    /** By content type key: how many of its items carry it, leaving out the trash. */
    counts: Record<string, number>;
    /** Its public address, such as /genre/action/; null when its taxonomy has no public pages. */
    address: string | null;
};

/** A taxonomy, as the server shares it for the sidebar and the terms screens. */
export type TaxonomyInfo = {
    key: string;
    singular: string;
    plural: string;
    segment: string;
    icon: string;
    hierarchical: boolean;
};

export type TermsIndexPage = {
    /** The URL segment, such as categories. */
    taxonomy: string;
    declared: {
        key: string;
        singular: string;
        plural: string;
        hierarchical: boolean;
    };
    /** The taxonomy's page on Site › Taxonomies, for a person who may manage types; else null. */
    setup: string | null;
    /** The taxonomies filed with this one under its type that the person may manage, this one among them. */
    tabs: { segment: string; label: string; count: number }[];
    /** The listed types whose items the counts count: a column each. */
    types: { key: string; segment: string; singular: string; plural: string }[];
    rows: TermRow[];
    newer: string | null;
    older: string | null;
    search: string;
    /** A nesting taxonomy's every term, by name; empty for a flat one. */
    tree: { id: string; name: string; parentId: string | null }[];
};
