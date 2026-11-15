import { CircleCheck, CircleX } from 'lucide-react';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
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
                    <ul className="divide-line grid divide-y">
                        {checks.map((check) => (
                            <li
                                key={check.id}
                                data-test="backup-check"
                                className="grid gap-1 py-3"
                            >
                                <p
                                    className={
                                        check.passed
                                            ? 'flex items-center gap-2 font-medium'
                                            : 'text-danger-ink flex items-center gap-2 font-medium'
                                    }
                                >
                                    {check.passed ? (
                                        <CircleCheck
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    ) : (
                                        <CircleX
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    )}
                                    {check.passed ? __('Passed') : __('Failed')}
                                </p>
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
