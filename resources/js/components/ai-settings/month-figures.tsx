import { Notice } from '@/components/ui/notice';
import { formatCost } from '@/lib/ai-cost';
import { __, __n } from '@/lib/i18n';
import type { AiMonthSummary } from '@/types';

/** One figure: its number or name large, what it is quiet beneath. */
function Figure({ value, name }: { value: string; name: string }) {
    return (
        <div className="grid gap-1">
            <span className="text-lead font-semibold">{value}</span>
            <span className="text-small text-ink-3">{name}</span>
        </div>
    );
}

/**
 * This month's figures at the end of Settings › AI's limits (I-1308): what
 * was spent, how many requests, and the task asked most, in a row; and,
 * when the budget is reached, saying so.
 */
export function MonthFigures({
    month,
    mostUsedTask,
}: {
    month: AiMonthSummary;
    mostUsedTask: string | null;
}) {
    return (
        <div className="grid gap-3" data-test="ai-settings-month">
            {month.reached && (
                <Notice tone="warn">
                    <p>
                        <strong className="font-medium">
                            {__('The budget is reached.')}
                        </strong>{' '}
                        {__(
                            'AI is unavailable to everyone until the new month starts.',
                        )}
                    </p>
                </Notice>
            )}
            <div
                role="group"
                aria-label={__('This month so far')}
                className="grid gap-4 sm:grid-cols-3"
            >
                <Figure
                    value={formatCost(month.costMicrounits)}
                    name={__('Spent this month')}
                />
                <Figure
                    value={month.requests.toLocaleString()}
                    name={__('Requests this month')}
                />
                <Figure
                    value={mostUsedTask ?? '—'}
                    name={__('The most-used task')}
                />
            </div>
            {month.unpricedRequests > 0 && (
                <p className="text-caption text-ink-3">
                    {__n(
                        ':count request used a model with no price, so its cost is left out.|:count requests used a model with no price, so their cost is left out.',
                        month.unpricedRequests,
                        { count: month.unpricedRequests.toLocaleString() },
                    )}
                </p>
            )}
        </div>
    );
}
