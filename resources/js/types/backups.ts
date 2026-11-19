import type { JobRow } from '@/types/jobs';

export type SetupState = {
    state: 'none' | 'unfinished' | 'set-up' | 'paused';
    placeId: string | null;
    placeName: string | null;
};

// Part 2: 1.12e's verdict as the screen draws it.
export type VerdictLine = {
    level: 'red' | 'amber' | 'green';
    sentence: string;
    more: string[];
};

export type NewestBackup = {
    /** When it was made, in words: "today at 03:10". */
    when: string;
    databaseBytes: number;
    mediaFiles: number;
    mediaBytes: number;
    bytesAdded: number;
    took: string;
};

export type BackupSchedule = {
    hour: string;
    hours: string[];
    keepDaily: number;
    keepWeekly: number;
    keepMonthly: number;
    next: string;
    /** When the next backup starts, in words: "tomorrow at 03:00". */
    nextAt: string;
    kept: string;
    zone: string;
    version: number;
};

export type BackupPlaceKind = 'folder' | 's3' | 'sftp';

export type BackupPlace = {
    id: string;
    name: string;
    kind: BackupPlaceKind;
    kindLabel: string;
    where: string;
    offServer: boolean;
    state: 'ready' | 'failing' | 'paused';
    stateLabel: string;
    error: string | null;
    newest: string | null;
    lastAnswered: string | null;
    missingKeys: string[];
};

export type BackupCheck = {
    id: string;
    kind: 'check' | 'read-back' | 'test-restore';
    kindLabel: string;
    place: string | null;
    passed: boolean;
    sentence: string;
    when: string;
};

/** A backup a place still keeps, as teme:backup:restore names it. */
export type KeptBackup = {
    runId: string;
    placeId: string;
    place: string;
    madeAt: string;
    databaseBytes: number;
    mediaBytes: number;
    mediaFiles: number;
    checked: 'passed' | 'failed' | null;
};

export type RecoveryKeyRow = { label: string; missingAt: string[] };

export type KeyPurpose = 'set-up' | 'add' | 'replace';

export type PendingKey = {
    purpose: KeyPurpose;
    label: string;
    positions: [number, number];
    challengeId: string;
};

export type BackupsPage = {
    backupJob: JobRow | null;
    backUpNow: 'hidden' | 'paused' | 'busy' | 'offered';
    /** Whether any media is in object storage, so backups hold its list and checksums but not its files. */
    mediaInBucket: boolean;
    setup: SetupState;
    running: string | null;
    firstBackup: string | null;
    firstBackupOnItsWay: boolean;
    verdict?: VerdictLine | null;
    newest: NewestBackup | null;
    schedule: BackupSchedule | null;
    places: BackupPlace[];
    backups: KeptBackup[];
    checks: BackupCheck[];
    keys: RecoveryKeyRow[];
    pending: PendingKey | null;
    offServerAdvice: boolean;
};

export type SetUpPage = { suggestedFolder: string };

export type SftpPage = {
    purpose: 'set-up' | 'add' | 'change';
    placeId: string | null;
    name: string;
    host: string;
    port: number;
    user: string;
    path: string;
    publicKey: string;
    fingerprint: string;
};

export type RecoveryKeyPageProps = {
    purpose: KeyPurpose;
    label: string;
    groups: string[];
    positions: [number, number];
    places: string[];
    site: string;
    madeOn: string;
    challengeId: string;
};

export type PlaceFormValues = {
    id: string;
    name: string;
    kind: BackupPlaceKind;
    path: string | null;
    endpoint: string | null;
    bucket: string | null;
    folder: string | null;
    region: string | null;
    host: string | null;
    port: number | null;
    user: string | null;
    publicKey: string | null;
};

export type PlacePage = {
    mode: 'add' | 'change';
    place: PlaceFormValues | null;
    suggestedFolder: string;
};
