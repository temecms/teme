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
    /** The address of the plugin’s own icon, or null to draw its initials. */
    icon: string | null;
    state: PluginState;
    badge: string;
    reason: string;
    /** Why its schedules don't run, with what to do; null when they do or it has none (docs/proposals/plugin-sockets-1.md). */
    schedules: string | null;
    /** How big its declared scripts are, compressed, and whether they pass the 20 KB budget; null when it declares none. A warning, never a block. */
    scripts: { bytes: number; over: boolean } | null;
    enabled: boolean;
    needsAttention: boolean;
    action: 'enable' | 'review' | 'migrate' | 'disable' | null;
    hasSettings: boolean;
    adds: string[];
    agreedVersion: string | null;
    agreed: PluginAccessLine[];
    asks: PluginAccessLine[];
    listensFor: string[];
    /** The plugins it needs, by their own names, each with whether it is new (docs/plans/plugin-sockets-3.md). */
    requires: PluginAccessLine[];
    /** The plugins that need it, by their own names. */
    requiredBy: string[];
    shown: PluginEnablePost;
    agreedBefore: boolean;
    asksMore: boolean;
    tables: PluginTableRow[];
    /** What removing its data also takes: its saved settings, and how many secrets. */
    savedData: { saved: boolean; secrets: number };
    canRemoveData: boolean;
    /** Part of the site's build, or installed here from the admin (I-211). */
    origin: ExtensionOrigin;
    /** The source kind it was installed from, when installed here. */
    source: string | null;
    canForget: boolean;
    /** For a plugin granted ai: its AI cost this month and its own budget (null: the site's only), in micro-dollars; else null. */
    ai: { costMicrounits: number; budgetMicrounits: number | null } | null;
};

/** The site’s AI budget and hourly limit, for the consent dialog’s figures. */
export type PluginAiSite = {
    /** Monthly budget in micro-dollars, or null when none is set. */
    budgetMicrounits: number | null;
    hourlyLimit: number;
};

export type PluginsPage = {
    plugins: PluginRowPage[];
    settingHosts: Record<string, PluginSettingHosts>;
    aiSite: PluginAiSite;
    /** Installing from the admin; null for anyone who may not install. */
    extensions: ExtensionsData | null;
};
