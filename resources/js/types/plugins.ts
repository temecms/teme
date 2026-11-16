import type { ExtensionOrigin, ExtensionsData } from './extensions';

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
    settingHosts?: string[];
};

/**
 * The settings a plugin takes hosts from, by their labels, and the hosts
 * they name now; the outbound line the dialog shows in place of the plain
 * one it replaces, and the hosts under it.
 */
export type PluginSettingHosts = {
    settings: string[];
    hosts: string[];
    replaces: string;
    line: string;
    now: string;
};

/** One of a plugin’s recorded tables, with its approximate row count (-1 unknown). */
export type PluginTableRow = { name: string; rows: number };

/** One plugin as Site › Plugins draws it (I-3353, I-3442). */
export type PluginRowPage = {
    name: string;
    code: string;
    label: string;
    version: string;
    description: string;
    author: string;
    authorUrl: string;
    homepage: string;
    state: PluginState;
    badge: string;
    reason: string;
    enabled: boolean;
    needsAttention: boolean;
    action: 'enable' | 'review' | 'migrate' | 'disable' | null;
    hasSettings: boolean;
    adds: string[];
    agreedVersion: string | null;
    agreed: PluginAccessLine[];
    asks: PluginAccessLine[];
    listensFor: string[];
    shown: PluginEnablePost;
    agreedBefore: boolean;
    asksMore: boolean;
    tables: PluginTableRow[];
    canRemoveData: boolean;
    /** Part of the site's build, or installed here from the admin (I-211). */
    origin: ExtensionOrigin;
    /** The source kind it was installed from, when installed here. */
    source: string | null;
    canForget: boolean;
};

export type PluginsPage = {
    plugins: PluginRowPage[];
    settingHosts: Record<string, PluginSettingHosts>;
    /** Installing from the admin; null for anyone who may not install. */
    extensions: ExtensionsData | null;
};
