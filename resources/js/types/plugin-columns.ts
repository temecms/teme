export type PluginColumn = {
    key: string;
    label: string;
    plugin: string;
    kind: 'text' | 'number' | 'badge';
    width: number;
};

export type PluginCellValue =
    | { kind: 'text'; value: string }
    | { kind: 'number'; value: number }
    | {
          kind: 'badge';
          value: string;
          tone: 'ok' | 'neutral' | 'info' | 'warn' | 'danger';
      }
    | {
          kind: 'link';
          value: string;
          href: string | null;
          host: string | null;
      };
