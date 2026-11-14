export type TypeSource = 'code' | 'stored' | 'refused';

export type TypeRow = {
    key: string;
    singular: string;
    plural: string;
    segment: string;
    icon: string;
    source: TypeSource;
    public: boolean;
    pattern: string | null;
    items: number;
    trashed: number;
    reason: string | null;
    listUrl: string | null;
};

export type TypesIndexPage = {
    locked: boolean;
    types: TypeRow[];
};

export type TypeComments = 'open' | 'closed' | null;

export type TypeValues = {
    key: string;
    singular: string;
    plural: string;
    segment: string;
    icon: string;
    single: boolean;
    public: boolean;
    archive: boolean;
    searchable: boolean;
    taxonomies: string[];
    supports: string[];
    comments: TypeComments;
    pattern: string | null;
    permissions: 'shared' | 'own';
};

export type TypePermissions = {
    roles: { key: string; label: string }[];
    actions: { key: string; label: string; ability: string }[];
    holds: Record<string, string[]>;
};

export type TypeFeature = {
    key: string;
    label: string;
    hint: string | null;
    field: string;
};

export type TypeOptions = {
    taxonomies: { key: string; plural: string }[];
    features: TypeFeature[];
};

export type TypePage = {
    mode: 'new' | 'edit' | 'view' | 'locked';
    source: TypeSource | null;
    reason: string | null;
    type: TypeValues;
    made: string | null;
    items: number;
    trashed: number;
    example: string | null;
    listUrl: string | null;
    options: TypeOptions;
    permissions: TypePermissions;
    fields: TypeFieldsSummary;
};

export type TypeFieldsSummary = {
    own: string[];
    fromTeme: string[];
    url: string | null;
};

export type FieldShowValue = 'after' | 'before' | 'hidden';

export type FieldChoice = { value: string; label: string };

export type FieldSettings = {
    choices?: FieldChoice[];
    dateOnly?: boolean;
};

export type FieldRow = {
    key: string;
    handle: string;
    label: string;
    help: string;
    fieldType: string;
    settings: FieldSettings;
    required: boolean;
    search: boolean;
    filterable: boolean;
    show: FieldShowValue;
    summary: string;
    holding: number;
    position: number;
};

export type FieldFromTeme = {
    name: string;
    handles: string;
    fieldType: string;
    where: string;
    feature: string | null;
};

export type RemovedFieldRow = {
    key: string;
    handle: string;
    label: string;
    fieldType: string;
    holding: number;
    removedAt: string;
    removedBy: string | null;
    clearing: null;
};

export type UnreadableFieldRow = {
    key: string;
    handle: string;
    sentence: string;
};

export type FieldCatalogueEntry = {
    name: string;
    label: string;
    line: string;
    icon: string;
    settings: string[];
    search: boolean;
    filterable: boolean;
    required: boolean;
};

export type FieldsPageType = {
    key: string;
    singular: string;
    plural: string;
    noun: string;
    typeUrl: string;
};

export type FieldsPage = {
    type: FieldsPageType;
    locked: boolean;
    fields: FieldRow[];
    fromTeme: FieldFromTeme[];
    removed: RemovedFieldRow[];
    unreadable: UnreadableFieldRow[];
    catalogue: FieldCatalogueEntry[];
};
