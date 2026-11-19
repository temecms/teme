import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { BackupCheck } from '@/types/backups';

/**
 * "Latest checks" (I-362): the newest of each kind at each place, newest
 * first, each in a sentence.
 */
export function ChecksList({ checks }: { checks: BackupCheck[] }) {
    return (
        <Panel aria-labelledby="backup-checks">
            <PanelHeader>
                <h2 id="backup-checks" className="text-lead font-semibold">
                    {__('Latest checks')}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body">
                <p className="text-small text-ink-2">
                    {__(
                        'Every backup is checked where it lands. A seventh of every place is read back each night, and once a week a backup is restored into a spare database to prove it works.',
                    )}
                </p>

                {checks.length === 0 ? (
                    <p className="text-ink-2">{__('No checks yet.')}</p>
                ) : (
                    <ul className="grid gap-3">
                        {checks.map((check) => (
                            <li
                                key={check.id}
                                data-test="backup-check"
                                className="bg-surface-2 grid justify-items-start gap-1 rounded-lg p-4"
                            >
                                <StatusPill
                                    tone={check.passed ? 'ok' : 'danger'}
                                >
                                    {check.passed ? __('Passed') : __('Failed')}
                                </StatusPill>
                                <p className="break-words">{check.sentence}</p>
                                <p className="text-small text-ink-2">
                                    {check.place === null
                                        ? __(':kind, :when', {
                                              kind: check.kindLabel,
                                              when: check.when,
                                          })
                                        : __(':kind at :place, :when', {
                                              kind: check.kindLabel,
                                              place: check.place,
                                              when: check.when,
                                          })}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </PanelBody>
        </Panel>
    );
}
