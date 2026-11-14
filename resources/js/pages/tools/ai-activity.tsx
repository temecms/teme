import { Head, Link, router } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { FormEvent } from 'react';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { PageHeader } from '@/components/list/page-header';
import { Pager } from '@/components/list/pager';
import {
    AiActivityDetails,
    AiSubject,
    OutcomeBadge,
} from '@/components/tools/ai-activity-details';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import {
    costWords,
    formatCost,
    tokensUsed,
    tokenWords,
    whenWords,
} from '@/lib/ai-cost';
import { show as showSettings } from '@/routes/settings';
import { index as toolsIndex } from '@/routes/tools';
import { index } from '@/routes/tools/ai-activity';
import type { AiActivityPage, AiActivityRow, AiMonthSummary } from '@/types';

/** The figures above the list: a month's spend and requests, and, for this month, the budget (I-1307). */
function Summary({
    summary,
    canChangeSettings,
}: {
    summary: AiMonthSummary;
    canChangeSettings: boolean;
}) {
    const budget = summary.budgetMicrounits;
    const left =
        budget === null ? 0 : Math.max(0, budget - summary.costMicrounits);

    return (
        <section
            aria-labelledby="ai-activity-month"
            data-test="ai-activity-summary"
            className="border-border rounded-md border p-4"
        >
            <h2 id="ai-activity-month" className="text-base font-medium">
                {summary.current
                    ? `This month: ${summary.label}`
                    : summary.label}
            </h2>
            <dl className="mt-3 grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-[13px]">
                <dt className="text-muted-foreground">Spent</dt>
                <dd>
                    {formatCost(summary.costMicrounits)}
                    {summary.unpricedRequests > 0 && (
                        <span className="text-muted-foreground block text-[12px]">
                            {summary.unpricedRequests === 1
                                ? '1 request used a model with no price, so its cost is left out.'
                                : `${summary.unpricedRequests.toLocaleString()} requests used a model with no price, so their cost is left out.`}
                        </span>
                    )}
                    <span className="text-muted-foreground block text-[12px]">
                        Cost is Teme’s own sum from each model’s price, in US
                        dollars, so it can differ a little from an AI company’s
                        invoice. Requests are removed after 12 months; the
                        monthly totals stay.
                    </span>
                </dd>

                <dt className="text-muted-foreground">Requests</dt>
                <dd>{summary.requests.toLocaleString()}</dd>

                {summary.current && (
                    <>
                        <dt className="text-muted-foreground">Budget</dt>
                        <dd>
                            {budget === null ? (
                                <>
                                    None set
                                    {canChangeSettings && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="ml-2"
                                            asChild
                                        >
                                            <Link
                                                href={showSettings({
                                                    group: 'ai',
                                                })}
                                            >
                                                Set a budget
                                            </Link>
                                        </Button>
                                    )}
                                </>
                            ) : (
                                `${formatCost(budget)} a month, ${summary.reached ? 'reached' : `${formatCost(left)} left`}`
                            )}
                        </dd>
                    </>
                )}
            </dl>
            {summary.reached && (
                <p
                    role="status"
                    data-test="ai-activity-budget-reached"
                    className="mt-3 text-[13px]"
                >
                    <strong className="font-medium">
                        The budget is reached.
                    </strong>{' '}
                    AI is unavailable to everyone until the new month starts.
                    {canChangeSettings && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="ml-2"
                            asChild
                        >
                            <Link href={showSettings({ group: 'ai' })}>
                                Change the budget
                            </Link>
                        </Button>
                    )}
                </p>
            )}
            <p className="text-muted-foreground mt-3 text-[12px]">
                Months follow UTC, as the AI companies bill.
            </p>
        </section>
    );
}

/**
 * Tools › AI activity (I-1307): every AI request in the record, newest first,
 * a page at a time, narrowed by person, task and month, with the month's
 * spend above it and a drawer that has its own address.
 */
export default function AiActivity(page: AiActivityPage) {
    const {
        rows,
        newer,
        older,
        filters,
        people,
        tasks,
        months,
        summary,
        details,
        missing,
        siteTimezone,
        aiOn,
        canChangeSettings,
    } = page;
    const filtered =
        filters.person !== null ||
        filters.task !== null ||
        filters.month !== null;
    const keep: Record<string, string> = {
        ...(filters.person === null ? {} : { person: String(filters.person) }),
        ...(filters.task === null ? {} : { task: filters.task }),
        ...(filters.month === null ? {} : { month: filters.month }),
    };

    // A filter starts a list afresh: no cursor, no drawer.
    const filter = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const query: Record<string, string> = {};

        for (const name of ['person', 'task', 'month'] as const) {
            const value = data.get(name);

            if (typeof value === 'string' && value !== '') {
                query[name] = value;
            }
        }

        router.get(index({ query }).url);
    };

    // The drawer is its own address, read again alone.
    const openDetails = (id: string | null) => {
        const url = new URL(window.location.href);

        if (id === null) {
            url.searchParams.delete('row');
        } else {
            url.searchParams.set('row', id);
        }

        router.get(
            `${url.pathname}${url.search}`,
            {},
            {
                only: ['details', 'missing'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    const when = (row: AiActivityRow) => whenWords(row.at, siteTimezone);

    const columns: Column<AiActivityRow>[] = [
        {
            id: 'when',
            owner: 'core',
            header: 'When',
            className: 'whitespace-nowrap',
            cell: (row) => <time dateTime={row.at}>{when(row)}</time>,
        },
        {
            id: 'person',
            owner: 'core',
            header: 'Person',
            cell: (row) =>
                row.person.name ?? (
                    <span className="text-muted-foreground">
                        A removed person
                    </span>
                ),
        },
        {
            id: 'task',
            owner: 'core',
            header: 'Task',
            cell: (row) => (
                <>
                    {row.task.label}
                    {row.variant !== null && (
                        <span className="text-muted-foreground block text-[12px]">
                            {row.variant.label}
                        </span>
                    )}
                </>
            ),
        },
        {
            id: 'on',
            owner: 'core',
            header: 'On',
            cell: (row) => <AiSubject subject={row.subject} />,
        },
        {
            id: 'result',
            owner: 'core',
            header: 'Result',
            cell: (row) => (
                <>
                    <OutcomeBadge outcome={row.outcome} />
                    {row.why !== null && (
                        <span className="text-muted-foreground mt-1 block max-w-64 text-[12px] whitespace-normal">
                            {row.why}
                        </span>
                    )}
                </>
            ),
        },
        {
            id: 'model',
            owner: 'core',
            header: 'Model',
            cell: (row) =>
                row.model === null ? (
                    <span className="text-muted-foreground">None</span>
                ) : (
                    <>
                        <span className="font-mono text-[12px]">
                            {row.model}
                        </span>
                        {row.provider !== null && (
                            <span className="text-muted-foreground block text-[12px]">
                                {row.provider}
                            </span>
                        )}
                    </>
                ),
        },
        {
            id: 'cost',
            owner: 'core',
            header: 'Cost',
            className: 'text-right',
            cell: (row) => {
                const cost = costWords(row.costMicrounits, row.tokens);

                return (
                    <>
                        <span
                            className={
                                cost.known ? undefined : 'text-muted-foreground'
                            }
                        >
                            {cost.text}
                        </span>
                        <span className="text-muted-foreground block text-[12px]">
                            {tokenWords(tokensUsed(row.tokens))}
                        </span>
                    </>
                );
            },
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">Actions</span>,
            className: 'w-px text-right',
            cell: (row) => (
                <Button
                    variant="outline"
                    size="sm"
                    data-test={`ai-row-details-${row.id}`}
                    aria-label={`Details of the ${row.task.label} request on ${when(row)}`}
                    onClick={() => openDetails(row.id)}
                >
                    Details
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="AI activity" />

            <div className="space-y-4 p-6">
                <PageHeader
                    title="AI activity"
                    description="Every AI request, newest first. Teme keeps the figures, never the text sent or the answer; when AI’s alt text is used, it also keeps the image’s alt text from before and after. Requests are removed after 12 months."
                />

                {!aiOn && (
                    <div
                        role="note"
                        data-test="ai-activity-off"
                        className="border-border flex gap-3 rounded-md border p-4 text-sm"
                    >
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        <p>
                            <strong className="font-medium">
                                AI is switched off on this server.
                            </strong>{' '}
                            Nobody can ask for a suggestion until the host
                            switches it on again. The requests below stay.
                        </p>
                    </div>
                )}

                {missing && (
                    <div
                        role="status"
                        data-test="ai-activity-missing"
                        className="border-border flex gap-3 rounded-md border p-4 text-sm"
                    >
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        <p>
                            <strong className="font-medium">
                                That request is gone.
                            </strong>{' '}
                            Requests are removed after 12 months.
                        </p>
                    </div>
                )}

                <Summary
                    summary={summary}
                    canChangeSettings={canChangeSettings}
                />

                <form
                    role="search"
                    aria-label="Filter AI requests"
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={filter}
                >
                    <div
                        key={`person-${filters.person ?? ''}`}
                        className="grid gap-1.5"
                    >
                        <Label htmlFor="ai-person">Person</Label>
                        <SelectField
                            id="ai-person"
                            name="person"
                            defaultValue={
                                filters.person === null
                                    ? ''
                                    : String(filters.person)
                            }
                            emptyLabel="Everyone"
                            options={people}
                            className="w-52"
                        />
                    </div>
                    <div
                        key={`task-${filters.task ?? ''}`}
                        className="grid gap-1.5"
                    >
                        <Label htmlFor="ai-task">Task</Label>
                        <SelectField
                            id="ai-task"
                            name="task"
                            defaultValue={filters.task ?? ''}
                            emptyLabel="All tasks"
                            options={tasks}
                            className="w-52"
                        />
                    </div>
                    <div
                        key={`month-${filters.month ?? ''}`}
                        className="grid gap-1.5"
                    >
                        <Label htmlFor="ai-month">Month</Label>
                        <SelectField
                            id="ai-month"
                            name="month"
                            defaultValue={filters.month ?? ''}
                            emptyLabel="Any month"
                            options={months}
                            className="w-44"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        data-test="ai-filter"
                    >
                        Filter
                    </Button>
                    {filtered && (
                        <Button variant="ghost" asChild>
                            <Link href={index().url}>Clear filters</Link>
                        </Button>
                    )}
                </form>

                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    caption="AI requests"
                    empty={
                        filtered ? (
                            <EmptyState
                                kind="nothing-matches"
                                title="No requests match these filters"
                                clearHref={index().url}
                            />
                        ) : (
                            <EmptyState
                                kind="nothing-yet"
                                title="No AI requests yet"
                                description="When someone asks AI for a suggestion, the request appears here with what it cost."
                            />
                        )
                    }
                />

                <Pager
                    newer={
                        newer
                            ? index({ query: { ...keep, before: newer } }).url
                            : null
                    }
                    older={
                        older
                            ? index({ query: { ...keep, after: older } }).url
                            : null
                    }
                />
            </div>

            <AiActivityDetails
                details={details}
                siteTimezone={siteTimezone}
                onClose={() => openDetails(null)}
            />
        </>
    );
}

AiActivity.layout = {
    breadcrumbs: [
        { title: 'Tools', href: toolsIndex() },
        { title: 'AI activity', href: index() },
    ],
};
