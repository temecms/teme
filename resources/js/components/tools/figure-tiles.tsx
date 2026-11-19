import { SoonTag } from '@/components/ui/soon-tag';
import { cn } from '@/lib/utils';

export type FigureTile = {
    key: string;
    label: string;
    /** A count, words such as a sum of money, or null while it isn't counted yet: drawn as a dash, its label tagged Soon. */
    value: number | string | null;
    /** A coloured dot before the label: what the figure counts is fine, worth a look, or wrong. */
    tone?: 'ok' | 'warn' | 'danger' | 'neutral';
};

const DOTS = {
    ok: 'bg-ok',
    warn: 'bg-warn',
    danger: 'bg-danger',
    neutral: 'bg-neutral-dot',
} as const;

/**
 * A row of figures inside a Tools panel: each value large over its label,
 * on the second surface, four across where there is room and two on a
 * phone.
 */
export function FigureTiles({
    figures,
    label,
    testId,
    twoAcross = false,
}: {
    figures: FigureTile[];
    /** Two across at every width, for a tile row in half the page. */
    twoAcross?: boolean;
    /** What the figures are, for a screen reader, such as "Counts so far". */
    label: string;
    testId?: string;
}) {
    return (
        <dl
            aria-label={label}
            data-test={testId}
            className={cn(
                'grid grid-cols-2 gap-3',
                !twoAcross && 'lg:grid-cols-4',
            )}
        >
            {figures.map((figure) => (
                <div
                    key={figure.key}
                    data-test={`figure-${figure.key}`}
                    className="bg-surface-2 flex flex-col-reverse gap-1 rounded-lg px-5 py-4"
                >
                    <dt className="text-small text-ink-2 flex items-center gap-2">
                        {figure.tone !== undefined && (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2 shrink-0 rounded-full',
                                    DOTS[figure.tone],
                                )}
                            />
                        )}
                        {figure.label}
                        {figure.value === null && <SoonTag className="ms-0" />}
                    </dt>
                    <dd className="text-title font-semibold tabular-nums">
                        {figure.value === null
                            ? '—'
                            : typeof figure.value === 'string'
                              ? figure.value
                              : figure.value.toLocaleString()}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
