/** What a plugin or theme request asks the installer to do. */
export type ExtensionAction =
    | 'install'
    | 'update'
    | 'remove'
    | 'rollback'
    | 'rebuild';

/** Whether a request is for a plugin or a theme. */
export type ExtensionKind = 'plugin' | 'theme';

/** Where a request stands. */
export type ExtensionStatus =
    | 'requested'
    | 'running'
    | 'waiting'
    | 'done'
    | 'failed'
    | 'cancelled';

/** One of the installer's steps, as it recorded it. */
export type ExtensionStep = {
    step: string;
    state: string;
    startedAt: string | null;
    finishedAt: string | null;
    detail: string | null;
};

/** The commands that carry a request out on the server when no installer runs. */
export type ExtensionCommands = {
    docker: string | null;
    server: string | null;
    build: string | null;
};

/** One request as the Install requests tab lists it. */
export type ExtensionRequestLine = {
    id: string;
    action: ExtensionAction;
    kind: ExtensionKind;
    package: string | null;
    constraint: string | null;
    sourceKind: string | null;
    status: ExtensionStatus;
    step: string | null;
    error: string | null;
    requestedBy: string | null;
    requestedAt: string;
    finishedAt: string | null;
};

/** One request as its sheet draws it. */
export type ExtensionRequestDetail = ExtensionRequestLine & {
    steps: ExtensionStep[];
    needs: Record<string, unknown>;
    agreed: Record<string, unknown>;
    report: Record<string, unknown>;
    startedAt: string | null;
    canCancel: boolean;
    canAgree: boolean;
    commands: ExtensionCommands | null;
};

/** A saved package source, never with its key. */
export type ExtensionSourceLine = {
    id: string;
    kind: string;
    label: string;
    url: string | null;
    username: string | null;
    hasKey: boolean;
    createdAt: string;
};

/** What the Plugins and Themes screens know of installing from the admin (I-211). */
export type ExtensionsData = {
    requests: ExtensionRequestLine[];
    open: ExtensionRequestDetail | null;
    installerSeenAt: string | null;
    installerPresent: boolean;
    folder: boolean;
    held: { release: string; reason: string | null } | null;
    sources: ExtensionSourceLine[];
    sourceKinds: string[];
    packagist: boolean;
    /** Whether Teme's plugin and theme directory is offered: on, and holding its key. */
    directory: boolean;
    canRollBack: boolean;
    uploadMax: number;
};

/** Where a plugin or theme comes from: the site's build, or installed here. */
export type ExtensionOrigin = 'image' | 'extensions';

/** What the Add sheet's Upload tab read from an uploaded archive. */
export type ExtensionUpload = { id: string; package: string; version: string };
