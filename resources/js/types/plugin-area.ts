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

export type AreaCustomRowAction = {
    id: string;
    label: string;
    confirm: string | null;
    icon: string | null;
};

export type AreaTableBlock = {
    id: string;
    type: 'table';
    record: { id: string; label: string; plural: string };
    columns: {
        id: string;
        label: string;
        kind: 'text' | 'number' | 'badge' | 'link';
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
    /** Declared actions marked for rows, in menu order before Enable/Disable. */
    customActions?: AreaCustomRowAction[];
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
        thing?: string | null;
        before?: string | null;
    }[];
    failed: string | null;
    empty?: boolean;
};

export type AreaChartData = {
    labels: string[];
    series: {
        id: string;
        label: string;
        values: number[];
        dashed?: boolean;
    }[];
    failed: string | null;
    empty?: boolean;
};

export type AreaPeriodProps = {
    id: string;
    options: { id: string; label: string }[];
    range: string;
    compare: string;
    timezone: string;
    lower: string;
    before: string;
};

export type AreaRecordHeader = {
    status: string | null;
    tone: 'ok' | 'neutral' | 'info' | 'warn' | 'danger';
    meta: string[];
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
    /** Statistics period when the page has figures or charts. */
    period?: AreaPeriodProps | null;
    /** Deferred tab counts by page id. */
    counts?: Record<string, number> | null;
    /** How many of this plugin's scripts wait for approval. */
    waitingScripts?: number;
    waitingNoun?: string;
    waitingNouns?: string;
    reviewScripts?: string;
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
    /** Status and meta under the title; null means title only. */
    header?: AreaRecordHeader | null;
    /**
     * The code fields' scripts that wait for approval, by the field's path,
     * and whether this person may approve them here (the decided Q2).
     */
    scripts: {
        waiting: Record<string, { id: string; script: string }[]>;
        approver: boolean;
    };
};
