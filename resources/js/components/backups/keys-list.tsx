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
        <section aria-labelledby="backup-keys" className="space-y-3">
            <h2 id="backup-keys" className="text-base font-medium">
                Recovery keys
            </h2>

            <p className="text-muted-foreground text-[13px]">
                Teme keeps no copy of a recovery key. If this server and every
                recovery key are lost, nobody can open the backups, Teme
                included.
            </p>

            <p className="text-sm">
                {keys.length === 1
                    ? '1 recovery key opens these backups.'
                    : `${keys.length} recovery keys open these backups.`}
            </p>

            {!paused && <KeyActions keys={keys} />}

            <ul className="border-border divide-border divide-y rounded-md border">
                {keys.map((key) => (
                    <li
                        key={key.label}
                        data-test="backup-key"
                        className="flex flex-wrap items-center justify-between gap-2 p-4 text-sm"
                    >
                        <p>
                            <span className="font-medium">{key.label}</span>
                            {key.missingAt.length > 0 && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · Doesn’t open {key.missingAt.join(
                                        ', ',
                                    )}{' '}
                                    yet.
                                </span>
                            )}
                        </p>
                        {!paused && <ReplaceKey label={key.label} />}
                    </li>
                ))}
            </ul>
        </section>
    );
}
