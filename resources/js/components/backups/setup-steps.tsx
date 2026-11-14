const STEPS = [
    'Choose a place',
    'Keep your recovery key',
    'Type part of it back',
];

/**
 * The three steps of setting backups up (I-2401), the current one marked for
 * screen readers as well as by sight.
 */
export function SetupSteps({ current }: { current: 1 | 2 | 3 }) {
    return (
        <ol
            aria-label="Setup steps"
            className="flex flex-wrap gap-x-6 gap-y-2 text-[13px]"
        >
            {STEPS.map((step, index) => {
                const number = index + 1;
                const state =
                    number < current
                        ? ' (done)'
                        : number === current
                          ? ' (current step)'
                          : '';

                return (
                    <li
                        key={step}
                        aria-current={number === current ? 'step' : undefined}
                        className={
                            number === current ? 'font-medium' : 'text-ink-2'
                        }
                    >
                        <span aria-hidden="true">{number} </span>
                        {step}
                        <span className="sr-only">{state}</span>
                    </li>
                );
            })}
        </ol>
    );
}
