import { Link } from '@inertiajs/react';
import { formatCost } from '@/lib/ai-cost';
import type { AiMonthSummary } from '@/types';

/**
 * This month's spend beside Settings › AI's limits (I-1308): what was spent,
 * how many requests, the budget and what is left, in the words Tools › AI
 * activity uses, and a way to every request when the viewer may see them.
 */
export function MonthFigures({
    month,
    activityUrl,
}: {
    month: AiMonthSummary;
    activityUrl: string | null;
}) {
    const budget = month.budgetMicrounits;
    const left =
        budget === null ? 0 : Math.max(0, budget - month.costMicrounits);

    return (
        <section
            aria-labelledby="ai-settings-month-heading"
            data-test="ai-settings-month"
            className="border-line rounded-md border p-4"
        >
            <h3
                id="ai-settings-month-heading"
                className="text-base font-medium"
            >
                This month: {month.label}
            </h3>
            <dl className="text-small mt-3 grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2">
                <dt className="text-ink-2">Spent</dt>
                <dd>
                    {formatCost(month.costMicrounits)}
                    {month.unpricedRequests > 0 && (
                        <span className="text-ink-2 text-caption block">
                            {month.unpricedRequests === 1
                                ? '1 request used a model with no price, so its cost is left out.'
                                : `${month.unpricedRequests.toLocaleString()} requests used a model with no price, so their cost is left out.`}
                        </span>
                    )}
                </dd>

                <dt className="text-ink-2">Requests</dt>
                <dd>{month.requests.toLocaleString()}</dd>

                <dt className="text-ink-2">Budget</dt>
                <dd>
                    {budget === null
                        ? 'None set'
                        : `${formatCost(budget)} a month, ${month.reached ? 'reached' : `${formatCost(left)} left`}`}
                </dd>
            </dl>
            {month.reached && (
                <p role="status" className="text-small mt-3">
                    <strong className="font-medium">
                        The budget is reached.
                    </strong>{' '}
                    AI is unavailable to everyone until the new month starts.
                </p>
            )}
            <p className="text-ink-2 text-caption mt-3">
                Months follow UTC, as the AI companies bill.
            </p>
            {activityUrl !== null && (
                <p className="text-small mt-3">
                    <Link href={activityUrl} className="underline">
                        See every request in Tools › AI activity
                    </Link>
                </p>
            )}
        </section>
    );
}
