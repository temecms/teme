import { StepBar } from '@/components/ui/step-bar';
import { __ } from '@/lib/i18n';

/**
 * The three steps of setting backups up (I-2401) on the shared step bar, the
 * current one marked for screen readers as well as by sight.
 */
export function SetupSteps({ current }: { current: 1 | 2 | 3 }) {
    return (
        <StepBar
            steps={[
                __('Choose a place'),
                __('Keep your recovery key'),
                __('Type part of it back'),
            ]}
            current={current - 1}
            aria-label={__('Setup steps')}
            className="grid sm:grid-cols-3"
        />
    );
}
