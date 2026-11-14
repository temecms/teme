import type {
    AiMonthSummary,
    CardMedia,
    FieldDefinition,
    SecretStatus,
} from '@/types';

/** A service's key: set here, set by the server, or not set (I-1308). Never the key. */
export type AiKeyState = 'set' | 'set-by-server' | 'not-set';

export type AiServiceBlock = {
    kind: 'service';
    key: string;
    label: string;
    state: AiKeyState;
    stateLabel: string;
    variable: string;
    keyField: string;
    fields: FieldDefinition[];
};

/** A model a task may use; prices in micro-dollars per million tokens, or null when unknown. */
export type AiModelOption = {
    value: string;
    label: string;
    provider: string;
    ready: boolean;
    input: number | null;
    output: number | null;
    note: string | null;
};

export type AiTaskRow = {
    key: string;
    label: string;
    note: string | null;
    modelField: string;
    fallbackField: string;
    on: boolean;
    status: string;
    options: AiModelOption[];
};

export type AiModelsBlock = {
    kind: 'models';
    key: string;
    label: string;
    tasks: AiTaskRow[];
};

export type AiFieldsBlock = {
    kind: 'limits' | 'other';
    key: string;
    label: string;
    fields: FieldDefinition[];
};

export type AiSettingsBlock = AiServiceBlock | AiModelsBlock | AiFieldsBlock;

export type AiSettingsPage =
    | { enabled: false }
    | {
          enabled: true;
          description: string;
          anyTaskOn: boolean;
          sections: AiSettingsBlock[];
          month: AiMonthSummary;
          activityUrl: string | null;
          values: Record<string, unknown>;
          secrets: Record<string, SecretStatus>;
          media: Record<string, CardMedia | null>;
          version: number;
      };
