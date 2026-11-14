/** Where a taxonomy on Site › Taxonomies comes from. */
export type TaxonomySource = 'code' | 'stored' | 'refused';

/** One row of Site › Taxonomies. */
export type TaxonomyRow = {
    key: string;
    singular: string;
    plural: string;
    segment: string;
    icon: string;
    source: TaxonomySource;
    reason: string | null;
    hierarchical: boolean;
    /** The plurals of the listed types that use it, in registry order. */
    usedBy: string[];
    /** Its public address with {slug} for a term's own, or null when it has no public pages. */
    address: string | null;
    terms: number;
    /** Its term screen; null for a row that can't be used. */
    termsUrl: string | null;
};

export type TaxonomiesIndexPage = {
    locked: boolean;
    taxonomies: TaxonomyRow[];
};

/** A taxonomy's values as its page shows and sends them. */
export type TaxonomyValues = {
    key: string;
    singular: string;
    plural: string;
    segment: string;
    icon: string;
    hierarchical: boolean;
    public: boolean;
    base: string | null;
    /** The keys of the listed types that use it. */
    types: string[];
};

export type TaxonomyPage = {
    mode: 'new' | 'edit' | 'view';
    source: TaxonomySource | null;
    reason: string | null;
    taxonomy: TaxonomyValues;
    made: string | null;
    terms: number;
    /** How many of its terms sit under another. */
    under: number;
    address: string | null;
    /** One term's slug, for the example address; null when it has no terms. */
    example: string | null;
    termsUrl: string | null;
    options: {
        /** Every listed type; locked when it is ticked and its items carry the taxonomy's terms. */
        types: { key: string; plural: string; locked: boolean }[];
    };
};
