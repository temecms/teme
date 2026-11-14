import { CircleCheck, CircleX } from 'lucide-react';
import type { BackupCheck } from '@/types/backups';

/**
 * "Latest checks" (I-362): the newest of each kind at each place, newest
 * first, each in a sentence.
 */
export function ChecksList({ checks }: { checks: BackupCheck[] }) {
    return (
        <section aria-labelledby="backup-checks" className="space-y-3">
            <h2 id="backup-checks" className="text-base font-medium">
                Latest checks
            </h2>

            <p className="text-muted-foreground text-[13px]">
                Every backup is checked where it lands. A seventh of every place
                is read back each night, and once a week a backup is restored
                into a spare database to prove it works.
            </p>

            {checks.length === 0 ? (
                <p className="text-muted-foreground text-sm">No checks yet.</p>
            ) : (
                <ul className="border-border divide-border divide-y rounded-md border">
                    {checks.map((check) => (
                        <li
                            key={check.id}
                            data-test="backup-check"
                            className="space-y-1 p-4"
                        >
                            <p
                                className={
                                    check.passed
                                        ? 'flex items-center gap-1.5 text-sm font-medium'
                                        : 'text-destructive flex items-center gap-1.5 text-sm font-medium'
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
                                {check.passed ? 'Passed' : 'Failed'}
                            </p>
                            <p className="text-sm break-words">
                                {check.sentence}
                            </p>
                            <p className="text-muted-foreground text-[13px]">
                                {check.kindLabel}
                                {check.place !== null &&
                                    ` at ${check.place}`},{' '}
                                {check.when}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
