/** One line of what a plugin asks to do, in words, and whether an update adds it. */
export type PluginAccessLine = { words: string; new: boolean };

export type PluginState =
    | 'not_enabled'
    | 'enabled'
    | 'disabled'
    | 'disabled_on_server'
    | 'stopped_by_teme'
    | 'waiting'
    | 'cant_be_used'
    | 'code_gone'
    | 'some_parts_stopped';

/** What the page posts to enable a plugin: exactly what the dialog showed. */
export type PluginEnablePost = {
    version: string;
    capabilities: string[];
    hosts: string[];
    hooks: string[];
};

/** One plugin as Site › Plugins draws it (I-3353). */
export type PluginRowPage = {
    name: string;
    code: string;
    label: string;
    version: string;
    description: string;
    author: string;
    homepage: string;
    state: PluginState;
    badge: string;
    reason: string;
    enabled: boolean;
    needsAttention: boolean;
    action: 'enable' | 'review' | 'disable' | null;
    hasSettings: boolean;
    adds: string[];
    agreedVersion: string | null;
    agreed: PluginAccessLine[];
    asks: PluginAccessLine[];
    listensFor: string[];
    shown: PluginEnablePost;
    agreedBefore: boolean;
    asksMore: boolean;
};

export type PluginsPage = {
    plugins: PluginRowPage[];
};
