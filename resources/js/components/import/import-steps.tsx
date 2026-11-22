import { StepBar } from '@/components/ui/step-bar';
import { __ } from '@/lib/i18n';
import type { ImportStep } from '@/types/import';

/** The five steps' words, looked up as they are drawn; a site read from its export file starts with Upload. */
const steps = (file: boolean) => [
    file ? __('Upload') : __('Connect'),
    __('Analyze'),
    __('Choose'),
    __('Import'),
    __('Report'),
];

/**
 * Where the step bar stands. Analyze and Choose share the server's plan step:
 * Choose once the plan has arrived with choices to make, Analyze until then.
 */
function current(step: ImportStep, choosing: boolean): number {
    if (step === 'plan') {
        return choosing ? 2 : 1;
    }

    return { connect: 0, import: 3, report: 4 }[step];
}

/**
 * A site's five steps (I-227) on the shared step bar, exactly one of them
 * current, marked for screen readers as well as by sight.
 */
export function ImportSteps({
    step,
    choosing = false,
    file = false,
}: {
    step: ImportStep;
    choosing?: boolean;
    file?: boolean;
}) {
    return (
        <StepBar
            steps={steps(file)}
            current={current(step, choosing)}
            aria-label={__('Import steps')}
            data-test="import-step"
        />
    );
}
