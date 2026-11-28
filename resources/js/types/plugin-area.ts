import type { VisibleWhenRule } from '@/lib/visible-when';
import type { PluginCellValue } from '@/types/plugin-columns';

/** The plugin an area belongs to: its code, its own name, and its picture when it has one (I-3468). */
export type AreaPluginRef = {
    code: string;
    name: string;
    /** The area-header icon URL, or null when the package has none. */
    icon?: string | null;
};

export type AreaFiguresBlock = {
    id: string;
    type: 'figures';
    items: {
        id: string;
        label: string;
        format: 'number' | 'percent' | 'text';
    }[];
};

export type AreaChartBlock = {
    id: string;
    type: 'chart';
    label: string;
    kind: 'line' | 'bar';
    series: { id: string; label: string }[];
};

export type AreaTableBlock = {
    id: string;
    type: 'table';
    record: { id: string; label: string; plural: string };
    columns: {
        id: string;
        label: string;
        kind: 'text' | 'number' | 'badge';
        sortable: boolean;
    }[];
    search: boolean;
    filters: {
        id: string;
        label: string;
        options: { value: string; label: string }[];
    }[];
    perPage: number;
    rowActions: string[];
    bulkActions: string[];
};

export type AreaActionsBlock = {
    id: string;
    type: 'actions';
    actions: { id: string; label: string; description: string | null }[];
};

export type AreaNoticeBlock = {
    id: string;
    type: 'notice';
    text: string;
    tone: 'ok' | 'neutral' | 'info' | 'warn' | 'danger';
};

export type AreaBlock =
    | AreaFiguresBlock
    | AreaChartBlock
    | AreaTableBlock
    | AreaActionsBlock
    | AreaNoticeBlock;

export type AreaFiguresData = {
    items: {
        id: string;
        label: string;
        format: 'number' | 'percent' | 'text';
        value: string | number | null;
        change: number | null;
    }[];
    failed: string | null;
};

export type AreaChartData = {
    labels: string[];
    series: { id: string; label: string; values: number[] }[];
    failed: string | null;
};

export type AreaRowsData = {
    rows: {
        id: string;
        cells: Record<string, PluginCellValue>;
        unavailable: string[];
    }[];
    total: number | null;
    failed: string | null;
};

/** What one table asks: its search, filters, sort and page. */
export type AreaTableAsk = {
    page?: string;
    search?: string;
    filters?: Record<string, string>;
    sort?: string;
    direction?: 'asc' | 'desc';
};

export type PluginAreaPageProps = {
    plugin: AreaPluginRef;
    area: { label: string; description: string };
    tabs: {
        id: string;
        label: string;
        current: boolean;
        href?: string;
        /** Drawn beside the tab label when the page has a count. */
        count?: number;
    }[];
    current: { id: string; label: string };
    blocks: AreaBlock[];
    asks: Record<string, AreaTableAsk>;
    /** The top bar's parent: Tools for an area placed there, else none. */
    parent: { title: string; href: string } | null;
} & Record<`block_${string}`, unknown>;

export type AreaChoice = { value: string; label: string };

export type AreaField = {
    key: string;
    type:
        | 'text'
        | 'textarea'
        | 'number'
        | 'boolean'
        | 'select'
        | 'multiselect'
        | 'email'
        | 'url'
        | 'color'
        | 'code'
        | 'datetime'
        | 'types'
        | 'terms'
        | 'items'
        | 'region'
        | 'repeater';
    label: string;
    description: string;
    required: boolean;
    min: number | null;
    max: number | null;
    maxLength: number | null;
    visibleWhen: VisibleWhenRule;
    scripts: boolean;
    choices: AreaChoice[];
    fields: AreaField[];
    minItems: number;
    maxItems: number;
    itemLabel: string;
    itemTitle: string | null;
    empty: unknown;
    /** A terms field stores each term's slug, not its ID. */
    storeAs: 'id' | 'slug' | null;
    /** A select whose options the plugin lists as the form opens. */
    fromPlugin?: boolean;
    /** The line shown when the plugin couldn't list its options this time; null when it could. */
    choicesFailed?: string | null;
    /** The chosen items, or the stored slugs, with the name each one has now (null: none). */
    chips: { value: string; label: string | null; kind?: string }[];
    /** The slugs a terms field stores that no term has now. */
    stale: string[];
    /** The taxonomy a terms field draws its terms from. */
    taxonomy?: string | null;
    /** The taxonomy's singular name in lower case, for a sentence about one of its terms. */
    taxonomySingular?: string | null;
    /** The plugin's name, as the words it shows name it. */
    plugin?: string;
};

export type PluginRecordPageProps = {
    plugin: AreaPluginRef;
    back: string;
    /** The area's label, for the top-bar crumbs (design system 4.31). */
    area: { label: string };
    record: { id: string; label: string; plural: string };
    id: string | null;
    title: string;
    sections: {
        id: string;
        label: string;
        description: string;
        fields: AreaField[];
    }[];
    values: Record<string, unknown>;
    failed: string | null;
    deletable: boolean;
    /**
     * The code fields' scripts that wait for approval, by the field's path,
     * and whether this person may approve them here (the decided Q2).
     */
    scripts: {
        waiting: Record<string, { id: string; script: string }[]>;
        approver: boolean;
    };
};
