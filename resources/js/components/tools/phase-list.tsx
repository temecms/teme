import { CheckIcon, LoaderCircle, TriangleAlertIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type Phase = {
    key: string;
    label: string;
    description?: string | null;
    state: 'done' | 'now' | 'next' | 'stopped';
    /** The words at the row's end, such as "Done" or "Waiting". */
    word: string;
};

const MARKS = {
    done: 'bg-ok-soft text-ok-ink',
    now: 'bg-accent text-on-accent',
    next: 'bg-surface-3 text-ink-2',
    stopped: 'bg-warn-soft text-warn-ink',
} as const;

const WORDS = {
    done: 'text-ok-ink',
    now: 'text-ink-2',
    next: 'text-ink-2',
    stopped: 'text-warn-ink font-medium',
} as const;

/**
 * The ordered steps of a long task on a Tools screen: a mark (a tick, a
 * turning wheel, a warning, or the step's number), its name and line, and
 * where it stands; the step under way on the accent's soft ground.
 */
export function PhaseList({
    phases,
    label,
}: {
    phases: Phase[];
    /** What the steps are, for a screen reader. */
    label: string;
}) {
    return (
        <ol aria-label={label} className="grid gap-1">
            {phases.map((phase, index) => (
                <li
                    key={phase.key}
                    data-state={phase.state}
                    aria-current={phase.state === 'now' ? 'step' : undefined}
                    className={cn(
                        'flex items-start gap-3 rounded-md px-3 py-2',
                        phase.state === 'now' && 'bg-accent-soft',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'text-caption grid size-6 shrink-0 place-items-center rounded-full font-semibold',
                            MARKS[phase.state],
                        )}
                    >
                        {phase.state === 'done' ? (
                            <CheckIcon className="size-3.5" />
                        ) : phase.state === 'now' ? (
                            <LoaderCircle className="size-3.5 motion-safe:animate-spin" />
                        ) : phase.state === 'stopped' ? (
                            <TriangleAlertIcon className="size-3.5" />
                        ) : (
                            index + 1
                        )}
                    </span>
                    <span className="grid min-w-0 flex-1">
                        <span className="font-medium">{phase.label}</span>
                        {phase.description != null && (
                            <span className="text-small text-ink-3">
                                {phase.description}
                            </span>
                        )}
                    </span>
                    <span
                        className={cn(
                            'text-small shrink-0 text-end',
                            WORDS[phase.state],
                        )}
                    >
                        {phase.word}
                    </span>
                </li>
            ))}
        </ol>
    );
}
