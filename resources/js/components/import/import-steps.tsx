import { Check } from 'lucide-react';
import type { ImportStep } from '@/types/import';

const STEPS: { key: string; label: string }[] = [
    { key: 'connect', label: 'Connect' },
    { key: 'analyze', label: 'Analyze' },
    { key: 'choose', label: 'Choose' },
    { key: 'import', label: 'Import' },
    { key: 'report', label: 'Report' },
];

/** The first step the page's step stands at: Analyze and Choose share the plan step. */
const POSITION: Record<ImportStep, number> = {
    connect: 0,
    plan: 1,
    import: 3,
    report: 4,
};

/**
 * A site's five steps (I-227), the current one marked for screen readers,
 * never by colour alone.
 */
export function ImportSteps({ step }: { step: ImportStep }) {
    const current = POSITION[step];

    return (
        <ol
            aria-label="Import steps"
            data-test="import-step"
            className="flex flex-wrap gap-x-6 gap-y-2 text-[13px]"
        >
            {STEPS.map((item, index) => {
                const done = index < current;
                const now =
                    index === current || (step === 'plan' && index === 2);

                return (
                    <li
                        key={item.key}
                        aria-current={now ? 'step' : undefined}
                        className={
                            now
                                ? 'text-foreground flex items-center gap-2 font-medium'
                                : 'text-muted-foreground flex items-center gap-2'
                        }
                    >
                        <span
                            aria-hidden="true"
                            className="border-border inline-flex size-6 items-center justify-center rounded-full border tabular-nums"
                        >
                            {done ? <Check className="size-3.5" /> : index + 1}
                        </span>
                        {item.label}
                        {done && <span className="sr-only"> (done)</span>}
                        {now && (
                            <span className="sr-only"> (current step)</span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
