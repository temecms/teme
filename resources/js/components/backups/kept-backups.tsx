import { Copy } from 'lucide-react';
import { toast } from 'sonner';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { Button } from '@/components/ui/button';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SoonTag } from '@/components/ui/soon-tag';
import { StatusPill } from '@/components/ui/status-pill';
import { sizeWords } from '@/lib/backup-words';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import type { KeptBackup } from '@/types/backups';

/** The backup "Use this backup" chose: its run ID and its place, as the restore command names them. */
export type ChosenBackup = { run: string; place: string };

/** The command that lists the backups at the server. */
const LIST_COMMAND = 'php artisan teme:backup:list';

/** The ID of the Restoring panel, which "Use this backup" scrolls to. */
export const RESTORING_ID = 'backup-restoring';

/** Puts $text on the clipboard, and says so or says to copy it by hand. */
async function copy(text: string, done: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(done);
    } catch {
        toast.error(
            __('That could not be copied. Select it and copy it by hand.'),
        );
    }
}

/** The restore command for $chosen, or with its two blanks named. */
function restoreCommand(chosen: ChosenBackup | null): string {
    return chosen === null
        ? 'php artisan teme:backup:restore {run ID} --place="{place}"'
        : `php artisan teme:backup:restore ${chosen.run} --place="${chosen.place}"`;
}

/**
 * The backups each place keeps, newest first (I-361): when each was made
 * and its run ID to copy, the place, its size, whether the check after it
 * passed, and "Use this backup", which fills in the restore command.
 */
export function KeptBackups({
    backups,
    zone,
    chosen,
    onUse,
}: {
    backups: KeptBackup[];
    zone: string;
    chosen: ChosenBackup | null;
    onUse: (chosen: ChosenBackup) => void;
}) {
    if (backups.length === 0) {
        return null;
    }

    const made = (row: KeptBackup) => formatInZone(row.madeAt, zone);
    const columns: Column<KeptBackup>[] = [
        {
            id: 'made',
            owner: 'core',
            className: 'whitespace-normal',
            header: __('Made, and its run ID'),
            cell: (row) => (
                <div className="grid gap-1">
                    <span className="whitespace-nowrap">{made(row)}</span>
                    <span className="flex items-center gap-1">
                        <span className="sr-only">{__('Run ID')} </span>
                        <code className="text-small min-w-0 font-mono break-all">
                            {row.runId}
                        </code>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label={__('Copy the run ID :run', {
                                run: row.runId,
                            })}
                            onClick={() =>
                                void copy(
                                    row.runId,
                                    __('Copied the run ID :run', {
                                        run: row.runId,
                                    }),
                                )
                            }
                        >
                            <Copy aria-hidden="true" />
                        </Button>
                    </span>
                </div>
            ),
        },
        {
            id: 'place',
            owner: 'core',
            header: __('Place'),
            cell: (row) => (
                <span className="whitespace-nowrap">{row.place}</span>
            ),
        },
        {
            id: 'size',
            owner: 'core',
            header: __('Size, and what it holds'),
            cell: (row) => (
                <div className="grid gap-1">
                    <span className="whitespace-nowrap">
                        {sizeWords(row.databaseBytes + row.mediaBytes)}
                    </span>
                    <span className="text-small text-ink-3">
                        {row.mediaFiles > 0
                            ? __('Database, files')
                            : __('Database')}
                    </span>
                </div>
            ),
        },
        {
            id: 'checked',
            owner: 'core',
            header: __('Checked'),
            cell: (row) =>
                row.checked === 'passed' ? (
                    <StatusPill tone="ok">{__('Checked')}</StatusPill>
                ) : row.checked === 'failed' ? (
                    <StatusPill tone="danger">{__('Failed')}</StatusPill>
                ) : (
                    <StatusPill tone="neutral">{__('Not yet')}</StatusPill>
                ),
        },
        {
            id: 'actions',
            owner: 'core',
            className: 'whitespace-normal',
            header: <span className="sr-only">{__('Actions')}</span>,
            cell: (row) => (
                <div className="grid justify-items-start gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        aria-pressed={
                            chosen?.run === row.runId &&
                            chosen.place === row.place
                        }
                        onClick={() =>
                            onUse({ run: row.runId, place: row.place })
                        }
                    >
                        {__('Use this backup')}
                        <span className="sr-only">
                            : {made(row)}, {row.place}
                        </span>
                    </Button>
                    <span className="text-small text-ink-3 flex flex-wrap items-center">
                        {__('Restore from here')}
                        <SoonTag />
                    </span>
                </div>
            ),
        },
    ];

    return (
        <section
            aria-labelledby="backup-list"
            className="grid gap-3"
            data-test="backup-list"
        >
            <div className="grid gap-1">
                <h2
                    id="backup-list"
                    className="text-lead flex items-baseline gap-2 font-semibold"
                >
                    {__('Backups')}
                    <span className="text-body text-ink-3 font-normal">
                        {backups.length.toLocaleString()}
                    </span>
                </h2>
                <p className="text-small text-ink-2">
                    {__('Every backup kept at each place, newest first: what')}{' '}
                    <code className="font-mono">{LIST_COMMAND}</code>{' '}
                    {__('shows at the server.')}
                </p>
            </div>
            <DataTable
                caption={__('Backups, newest first')}
                columns={columns}
                rows={backups}
                rowKey={(row) => `${row.runId}-${row.placeId}`}
                selectedKeys={
                    chosen === null
                        ? undefined
                        : new Set(
                              backups
                                  .filter(
                                      (row) =>
                                          row.runId === chosen.run &&
                                          row.place === chosen.place,
                                  )
                                  .map((row) => `${row.runId}-${row.placeId}`),
                          )
                }
            />
        </section>
    );
}

/**
 * Restoring in three steps (I-364): where it runs, the command with the
 * chosen backup's run ID to copy, and what happens.
 */
export function Restoring({ chosen }: { chosen: ChosenBackup | null }) {
    const command = restoreCommand(chosen);

    return (
        <Panel aria-labelledby={RESTORING_ID}>
            <PanelHeader>
                <h2 id={RESTORING_ID} className="text-lead font-semibold">
                    {__('Restoring')}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body">
                <ol className="grid list-decimal gap-4 ps-5">
                    <li>
                        <strong className="font-semibold">
                            {__('Restoring is run at a terminal on the server')}
                        </strong>
                        {__(
                            ', by whoever runs it. The admin doesn’t start it yet.',
                        )}
                    </li>
                    <li>
                        <div className="grid justify-items-start gap-2">
                            <span>
                                <strong className="font-semibold">
                                    {__('Run this command')}
                                </strong>
                                {__(
                                    ', with the run ID of the backup you chose. “Use this backup” on a row above fills it in.',
                                )}
                            </span>
                            <div className="flex w-full flex-wrap items-center gap-2">
                                <ScrollArea
                                    axis="x"
                                    label={__(
                                        'The restore command, scrolls sideways',
                                    )}
                                    className="bg-surface-2 text-small flex-1 rounded-md px-3 py-2 font-mono whitespace-nowrap"
                                    data-test="backup-restore-command"
                                >
                                    {command}
                                </ScrollArea>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={chosen === null}
                                    aria-describedby={
                                        chosen === null
                                            ? 'backup-restore-command-hint'
                                            : undefined
                                    }
                                    onClick={() =>
                                        void copy(
                                            command,
                                            __('Copied the command'),
                                        )
                                    }
                                >
                                    <Copy aria-hidden="true" />
                                    {__('Copy the command')}
                                </Button>
                            </div>
                            {chosen === null && (
                                <span
                                    id="backup-restore-command-hint"
                                    className="text-small text-ink-2"
                                >
                                    {__('Choose a backup above first.')}
                                </span>
                            )}
                        </div>
                    </li>
                    <li>
                        <strong className="font-semibold">
                            {__('What happens:')}
                        </strong>{' '}
                        {__(
                            'Teme checks everything first, then backs the site up as it is now (a safety backup), puts the site into maintenance mode and restores. When it is done, you say whether this server is now the site, or a copy.',
                        )}
                    </li>
                </ol>
            </PanelBody>
        </Panel>
    );
}
