import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import type { CSSProperties, FormEvent } from 'react';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { Pager } from '@/components/list/pager';
import { ListPage } from '@/components/patterns/list-page';
import {
    AiActivityDetails,
    AiSubject,
    OutcomeBadge,
} from '@/components/tools/ai-activity-details';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { SelectField } from '@/components/ui/select-field';
import {
    costWords,
    formatCost,
    tokensUsed,
    tokenWords,
    whenWords,
} from '@/lib/ai-cost';
import { __, __n } from '@/lib/i18n';
import { show as showSettings } from '@/routes/settings';
import { index as toolsIndex } from '@/routes/tools';
import { index } from '@/routes/tools/ai-activity';
import type { AiActivityPage, AiActivityRow, AiMonthSummary } from '@/types';

/** The summary's two columns from the small breakpoint up. */
const FACTS = { '--facts-cols': 'max-content 1fr' } as CSSProperties;

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
        <Panel
            aria-labelledby="ai-activity-month"
            data-test="ai-activity-summary"
        >
            <PanelHeader>
                <h2 id="ai-activity-month" className="text-lead font-semibold">
                    {summary.current
                        ? __('This month: :month', { month: summary.label })
                        : summary.label}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body">
                <dl
                    className="grid gap-x-6 gap-y-2 sm:grid-cols-(--facts-cols)"
                    style={FACTS}
                >
                    <dt className="text-small text-ink-2">{__('Spent')}</dt>
                    <dd>
                        {formatCost(summary.costMicrounits)}
                        {summary.unpricedRequests > 0 && (
                            <span className="text-ink-2 text-caption block">
                                {__n(
                                    ':count request used a model with no price, so its cost is left out.|:count requests used a model with no price, so their cost is left out.',
                                    summary.unpricedRequests,
                                    {
                                        count: summary.unpricedRequests.toLocaleString(),
                                    },
                                )}
                            </span>
                        )}
                        <span className="text-ink-2 text-caption block">
                            {__(
                                'Cost is Teme’s own sum from each model’s price, in US dollars, so it can differ a little from an AI company’s invoice. Requests are removed after 12 months; the monthly totals stay.',
                            )}
                        </span>
                    </dd>

                    <dt className="text-small text-ink-2">{__('Requests')}</dt>
                    <dd>{summary.requests.toLocaleString()}</dd>

                    {summary.current && (
                        <>
                            <dt className="text-small text-ink-2">
                                {__('Budget')}
                            </dt>
                            <dd>
                                {budget === null ? (
                                    <span className="flex flex-wrap items-center gap-2">
                                        {__('None set')}
                                        {canChangeSettings && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={showSettings({
                                                        group: 'ai',
                                                    })}
                                                >
                                                    {__('Set a budget')}
                                                </Link>
                                            </Button>
                                        )}
                                    </span>
                                ) : summary.reached ? (
                                    __(':budget a month, reached', {
                                        budget: formatCost(budget),
                                    })
                                ) : (
                                    __(':budget a month, :left left', {
                                        budget: formatCost(budget),
                                        left: formatCost(left),
                                    })
                                )}
                            </dd>
                        </>
                    )}
                </dl>
                {summary.reached && (
                    <Notice
                        tone="warn"
                        role="status"
                        data-test="ai-activity-budget-reached"
                        title={__('The budget is reached.')}
                    >
                        <span className="flex flex-wrap items-center gap-2">
                            {__(
                                'AI is unavailable to everyone until the new month starts.',
                            )}
                            {canChangeSettings && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={showSettings({ group: 'ai' })}>
                                        {__('Change the budget')}
                                    </Link>
                                </Button>
                            )}
                        </span>
                    </Notice>
                )}
                <p className="text-ink-2 text-caption">
                    {__('Months follow UTC, as the AI companies bill.')}
                </p>
            </PanelBody>
        </Panel>
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

    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: toolsIndex() },
            { title: __('AI activity'), href: index() },
        ],
    });
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
            header: __('When'),
            className: 'whitespace-nowrap',
            cell: (row) => <time dateTime={row.at}>{when(row)}</time>,
        },
        {
            id: 'person',
            owner: 'core',
            header: __('Person'),
            cell: (row) =>
                row.person.name ?? (
                    <span className="text-ink-2">{__('A removed person')}</span>
                ),
        },
        {
            id: 'task',
            owner: 'core',
            header: __('Task'),
            cell: (row) => (
                <>
                    {row.task.label}
                    {row.variant !== null && (
                        <span className="text-ink-2 text-caption block">
                            {row.variant.label}
                        </span>
                    )}
                </>
            ),
        },
        {
            id: 'on',
            owner: 'core',
            header: __('On'),
            cell: (row) => <AiSubject subject={row.subject} />,
        },
        {
            id: 'result',
            owner: 'core',
            header: __('Result'),
            cell: (row) => (
                <>
                    <OutcomeBadge outcome={row.outcome} />
                    {row.why !== null && (
                        <span className="text-ink-2 text-caption mt-1 block max-w-64 whitespace-normal">
                            {row.why}
                        </span>
                    )}
                </>
            ),
        },
        {
            id: 'model',
            owner: 'core',
            header: __('Model'),
            cell: (row) =>
                row.model === null ? (
                    <span className="text-ink-2">{__('None')}</span>
                ) : (
                    <>
                        <span className="text-small font-mono">
                            {row.model}
                        </span>
                        {row.provider !== null && (
                            <span className="text-ink-2 text-caption block">
                                {row.provider}
                            </span>
                        )}
                    </>
                ),
        },
        {
            id: 'cost',
            owner: 'core',
            header: __('Cost'),
            className: 'text-right',
            cell: (row) => {
                const cost = costWords(row.costMicrounits, row.tokens);

                return (
                    <>
                        <span className={cost.known ? undefined : 'text-ink-2'}>
                            {cost.text}
                        </span>
                        <span className="text-ink-2 text-caption block">
                            {tokenWords(tokensUsed(row.tokens))}
                        </span>
                    </>
                );
            },
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">{__('Actions')}</span>,
            className: 'w-px text-right',
            cell: (row) => (
                <Button
                    variant="outline"
                    size="sm"
                    data-test={`ai-row-details-${row.id}`}
                    aria-label={__('Details of the :task request on :date', {
                        task: row.task.label,
                        date: when(row),
                    })}
                    onClick={() => openDetails(row.id)}
                >
                    {__('Details')}
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={__('AI activity')} />

            <ListPage
                title={__('AI activity')}
                description={__(
                    'Every AI request, newest first. Teme keeps the figures, never the text sent or the answer; when AI’s alt text is used, it also keeps the image’s alt text from before and after. Requests are removed after 12 months.',
                )}
            >
                {!aiOn && (
                    <Notice
                        tone="info"
                        data-test="ai-activity-off"
                        title={__('AI is switched off on this server.')}
                    >
                        {__(
                            'Nobody can ask for a suggestion until the host switches it on again. The requests below stay.',
                        )}
                    </Notice>
                )}

                {missing && (
                    <Notice
                        tone="info"
                        role="status"
                        data-test="ai-activity-missing"
                        title={__('That request is gone.')}
                    >
                        {__('Requests are removed after 12 months.')}
                    </Notice>
                )}

                <Summary
                    summary={summary}
                    canChangeSettings={canChangeSettings}
                />

                <form
                    role="search"
                    aria-label={__('Filter AI requests')}
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={filter}
                >
                    <Field
                        key={`person-${filters.person ?? ''}`}
                        htmlFor="ai-person"
                        label={__('Person')}
                    >
                        <SelectField
                            id="ai-person"
                            name="person"
                            defaultValue={
                                filters.person === null
                                    ? ''
                                    : String(filters.person)
                            }
                            emptyLabel={__('Everyone')}
                            options={people}
                            className="w-52"
                        />
                    </Field>
                    <Field
                        key={`task-${filters.task ?? ''}`}
                        htmlFor="ai-task"
                        label={__('Task')}
                    >
                        <SelectField
                            id="ai-task"
                            name="task"
                            defaultValue={filters.task ?? ''}
                            emptyLabel={__('All tasks')}
                            options={tasks}
                            className="w-52"
                        />
                    </Field>
                    <Field
                        key={`month-${filters.month ?? ''}`}
                        htmlFor="ai-month"
                        label={__('Month')}
                    >
                        <SelectField
                            id="ai-month"
                            name="month"
                            defaultValue={filters.month ?? ''}
                            emptyLabel={__('Any month')}
                            options={months}
                            className="w-44"
                        />
                    </Field>
                    <Button
                        type="submit"
                        variant="outline"
                        data-test="ai-filter"
                    >
                        {__('Filter')}
                    </Button>
                    {filtered && (
                        <Button variant="ghost" asChild>
                            <Link href={index().url}>
                                {__('Clear filters')}
                            </Link>
                        </Button>
                    )}
                </form>

                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    caption={__('AI requests')}
                    empty={
                        filtered ? (
                            <EmptyState
                                kind="nothing-matches"
                                title={__('No requests match these filters')}
                                clearHref={index().url}
                            />
                        ) : (
                            <EmptyState
                                kind="nothing-yet"
                                title={__('No AI requests yet')}
                                description={__(
                                    'When someone asks AI for a suggestion, the request appears here with what it cost.',
                                )}
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

                <AiActivityDetails
                    details={details}
                    siteTimezone={siteTimezone}
                    onClose={() => openDetails(null)}
                />
            </ListPage>
        </>
    );
}
