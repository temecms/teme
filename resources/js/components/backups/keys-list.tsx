import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { __, __n } from '@/lib/i18n';
import type { RecoveryKeyRow } from '@/types/backups';
import { KeyActions, ReplaceKey } from './key-actions';

/**
 * "Recovery keys" (I-359): how many open the backups, each by its label and
 * the places it doesn't open yet. Teme keeps no copy of any of them.
 */
export function KeysList({
    keys,
    paused = false,
}: {
    keys: RecoveryKeyRow[];
    /** A restore's places wait for an answer: no key can change meanwhile. */
    paused?: boolean;
}) {
    return (
        <Panel aria-labelledby="backup-keys">
            <PanelHeader>
                <h2 id="backup-keys" className="text-lead font-semibold">
                    {__('Recovery keys')}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body">
                <p className="text-small text-ink-2">
                    {__(
                        'Teme keeps no copy of a recovery key. If this server and every recovery key are lost, nobody can open the backups, Teme included.',
                    )}
                </p>

                <p className="font-semibold">
                    {__n(
                        ':count recovery key opens these backups.|:count recovery keys open these backups.',
                        keys.length,
                    )}
                </p>

                {!paused && <KeyActions keys={keys} />}

                <ul className="grid gap-3">
                    {keys.map((key) => (
                        <li
                            key={key.label}
                            data-test="backup-key"
                            className="bg-surface-2 flex flex-wrap items-center justify-between gap-2 rounded-lg p-4"
                        >
                            <p>
                                <span className="font-medium">{key.label}</span>
                                {key.missingAt.length > 0 && (
                                    <span className="text-ink-2">
                                        {' · '}
                                        {__('Doesn’t open :places yet.', {
                                            places: key.missingAt.join(', '),
                                        })}
                                    </span>
                                )}
                            </p>
                            {!paused && <ReplaceKey label={key.label} />}
                        </li>
                    ))}
                </ul>
            </PanelBody>
        </Panel>
    );
}
