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
    setup: SetupState;
    running: string | null;
    firstBackup: string | null;
    firstBackupOnItsWay: boolean;
    verdict?: VerdictLine | null;
    newest: NewestBackup | null;
    schedule: BackupSchedule | null;
    places: BackupPlace[];
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
