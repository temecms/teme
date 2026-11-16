import { __ } from '@/lib/i18n';
import type {
    ExtensionAction,
    ExtensionKind,
    ExtensionOrigin,
    ExtensionStatus,
} from '@/types';

/** The installer's steps, in the order it takes them. */
export const STEPS = [
    'checking',
    'preparing',
    'fetching',
    'verifying',
    'validating',
    'switching',
    'finishing',
] as const;

/** A step's name, looked up while the sheet draws. */
export function stepLabel(step: string): string {
    switch (step) {
        case 'checking':
            return __('Check the server');
        case 'preparing':
            return __('Prepare a new copy');
        case 'fetching':
            return __('Download');
        case 'verifying':
            return __('Verify the download');
        case 'validating':
            return __('Check the package');
        case 'switching':
            return __('Switch over');
        case 'finishing':
            return __('Finish');
        default:
            return step;
    }
}

/** How a step's state reads, beside its icon. */
export function stepStateLabel(state: string): string {
    switch (state) {
        case 'done':
            return __('Done');
        case 'running':
            return __('Under way');
        case 'failed':
            return __('Failed');
        case 'waiting':
            return __('Waiting for you');
        default:
            return __('Not yet');
    }
}

/** What a request asks for, as a word. */
export function actionLabel(action: ExtensionAction): string {
    switch (action) {
        case 'install':
            return __('Install');
        case 'update':
            return __('Update');
        case 'remove':
            return __('Remove');
        case 'rollback':
            return __('Roll back');
        case 'rebuild':
            return __('Rebuild for a new Teme');
    }
}

/** Where a package comes from, as the Add sheet's tabs name it. */
export function sourceLabel(kind: string | null): string {
    switch (kind) {
        case 'packagist':
            return __('Packagist');
        case 'git':
            return __('Git address');
        case 'composer':
            return __('Vendor source');
        case 'upload':
            return __('Upload ZIP');
        case null:
            return '—';
        default:
            return kind;
    }
}

/** Where an installed plugin or theme comes from, under its version. */
export function originLabel(
    origin: ExtensionOrigin,
    source: string | null,
): string {
    if (origin === 'image') {
        return __('Part of the site’s build');
    }

    return source === null
        ? __('Installed here')
        : __('Installed here, from :source', { source: sourceLabel(source) });
}

/** A request's status, as its pill says it. */
export function statusLabel(status: ExtensionStatus): string {
    switch (status) {
        case 'requested':
            return __('Waiting for the installer');
        case 'running':
            return __('Under way');
        case 'waiting':
            return __('Waiting for your agreement');
        case 'done':
            return __('Done');
        case 'failed':
            return __('Failed');
        case 'cancelled':
            return __('Cancelled');
    }
}

/** A status pill's tone; the word is always beside it. */
export function statusTone(
    status: ExtensionStatus,
): 'ok' | 'neutral' | 'warn' | 'danger' {
    switch (status) {
        case 'done':
            return 'ok';
        case 'failed':
            return 'danger';
        case 'waiting':
            return 'warn';
        default:
            return 'neutral';
    }
}

/** A kind's word, for the requests list. */
export function kindLabel(kind: ExtensionKind): string {
    return kind === 'plugin' ? __('Plugin') : __('Theme');
}

/** What one kind of finding asks the person to agree to, as a heading. */
function needHeading(key: string): string {
    switch (key) {
        case 'files':
            return __('Files that run on every request');
        // The keys the installer's StageValidator writes.
        case 'composer_plugins':
            return __('Composer plugins, which won’t run here');
        case 'source':
            return __('A different source than before');
        case 'replace':
            return __('An upload that replaces an installed version');
        default:
            return key;
    }
}

/** One finding's value as lines of words: a list, a package with its files, or current → new. */
function needLines(value: unknown): string[] {
    if (typeof value === 'string' || typeof value === 'number') {
        return [String(value)];
    }

    if (Array.isArray(value)) {
        return value.flatMap(needLines);
    }

    if (value !== null && typeof value === 'object') {
        const entry = value as Record<string, unknown>;

        if ('from' in entry && 'to' in entry) {
            const name =
                typeof entry.package === 'string' ? `${entry.package}: ` : '';

            return [`${name}${String(entry.from)} → ${String(entry.to)}`];
        }

        if (typeof entry.package === 'string') {
            const rest = Object.entries(entry)
                .filter(([key]) => key !== 'package')
                .flatMap(([, item]) => needLines(item));

            return [
                rest.length > 0
                    ? `${entry.package}: ${rest.join(', ')}`
                    : entry.package,
            ];
        }

        return Object.entries(entry).map(
            ([key, item]) => `${key}: ${needLines(item).join(', ')}`,
        );
    }

    return [];
}

/** What waits for the person's agreement, as headed lists of lines. */
export function needsGroups(
    needs: Record<string, unknown>,
): { key: string; heading: string; lines: string[] }[] {
    return Object.entries(needs).map(([key, value]) => ({
        key,
        heading: needHeading(key),
        lines: needLines(value),
    }));
}
