import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Download, WandSparkles } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { Pager } from '@/components/list/pager';
import { ListPage } from '@/components/patterns/list-page';
import { FigureTiles } from '@/components/tools/figure-tiles';
import type { FigureTile } from '@/components/tools/figure-tiles';
import {
    AiActivityDetails,
    AiSubject,
    OutcomeBadge,
} from '@/components/tools/ai-activity-details';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { PluginMark } from '@/components/ui/plugin-mark';
import { Progress } from '@/components/ui/progress';
import { SelectField } from '@/components/ui/select-field';
import { SoonTag } from '@/components/ui/soon-tag';
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
import type {
    AiActivityPage,
    AiActivityRow,
    AiMonthSummary,
    AiPluginMonth,
} from '@/types';

/** The month's figures above the list: its spend and requests, and, for this month, the budget and its bar (I-1307). */
function Summary({
    summary,
    pluginMonths,
    canChangeSettings,
}: {
    summary: AiMonthSummary;
    pluginMonths: AiPluginMonth[];
    canChangeSettings: boolean;
}) {
    const budget = summary.current ? summary.budgetMicrounits : null;
    const left =
        budget === null ? 0 : Math.max(0, budget - summary.costMicrounits);
    const figures: FigureTile[] = [
        {
            key: 'spent',
            label: __('Spent'),
            value: formatCost(summary.costMicrounits),
            tone: summary.costMicrounits > 0 ? 'accent' : 'neutral',
        },
        {
            key: 'requests',
            label: __('Requests'),
            value: summary.requests,
            tone: 'neutral',
        },
        ...(budget === null
            ? []
            : [
                  {
                      key: 'left',
                      label: __('Left of the budget'),
                      value: formatCost(left),
                      tone: summary.reached
                          ? ('danger' as const)
                          : ('ok' as const),
                  } satisfies FigureTile,
              ]),
        ...(summary.current
            ? [
                  {
                      key: 'budget',
                      label: __('Monthly budget'),
                      value:
                          budget === null ? __('None set') : formatCost(budget),
                      tone: 'neutral' as const,
                  },
              ]
            : []),
    ];

    return (
        <Panel
            aria-labelledby="ai-activity-month"
            data-test="ai-activity-summary"
        >
            <PanelHeader>
                <h2 id="ai-activity-month" className="text-body font-semibold">
                    {summary.current
                        ? __('This month: :month', { month: summary.label })
                        : summary.label}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body grid gap-4">
                <FigureTiles
                    figures={figures}
                    label={__('The month’s figures')}
                />
                {budget !== null && (
                    <div className="grid gap-2">
                        <p className="text-small flex justify-between gap-3 tabular-nums">
                            <span>
                                {__(':spent of :budget', {
                                    spent: formatCost(summary.costMicrounits),
                                    budget: formatCost(budget),
                                })}
                            </span>
                            <span>
                                {summary.reached
                                    ? __('Reached')
                                    : (
                                          summary.costMicrounits /
                                          Math.max(budget, 1)
                                      ).toLocaleString(undefined, {
                                          style: 'percent',
                                      })}
                            </span>
                        </p>
                        <Progress
                            value={Math.min(summary.costMicrounits, budget)}
                            max={budget}
                            tone={summary.reached ? 'danger' : 'accent'}
                            label={__('Budget used')}
                        />
                    </div>
                )}
                <p className="text-ink-2 text-small">
                    {summary.unpricedRequests > 0 &&
                        `${__n(
                            ':count request used a model with no price, so its cost is left out.|:count requests used a model with no price, so their cost is left out.',
                            summary.unpricedRequests,
                            {
                                count: summary.unpricedRequests.toLocaleString(),
                            },
                        )} `}
                    {__(
                        'Cost is Teme’s own sum from each model’s price, in US dollars, so it can differ a little from an AI company’s invoice. Requests are removed after 12 months; the monthly totals stay.',
                    )}{' '}
                    {__('Months follow UTC, as the AI companies bill.')}
                </p>
                {summary.current && canChangeSettings && (
                    <div>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={showSettings({ group: 'ai' })}>
                                {budget === null
                                    ? __('Set a budget')
                                    : __('Change the budget')}
                            </Link>
                        </Button>
                    </div>
                )}
                {pluginMonths.length > 0 && (
                    <ByPlugin
                        rows={pluginMonths}
                        current={summary.current}
                        canChangeSettings={canChangeSettings}
                    />
                )}
            </PanelBody>
        </Panel>
    );
}

/**
 * The month's spend by who asked, inside the month's panel: Teme's own
 * requests, then each plugin by its own name and mark, with its requests
 * and cost, this month's budget under the cost, and a row for them all.
 */
function ByPlugin({
    rows,
    current,
    canChangeSettings,
}: {
    rows: AiPluginMonth[];
    current: boolean;
    canChangeSettings: boolean;
}) {
    const all: AiPluginMonth = {
        code: 'all',
        label: __('All'),
        costMicrounits: rows.reduce((sum, row) => sum + row.costMicrounits, 0),
        requests: rows.reduce((sum, row) => sum + row.requests, 0),
        unpricedRequests: rows.reduce(
            (sum, row) => sum + row.unpricedRequests,
            0,
        ),
        budget: null,
    };
    const budgetWords = (row: AiPluginMonth): string | null =>
        !current || row.code === 'all'
            ? null
            : row.budget === null
              ? __('No limit')
              : row.budget.reached
                ? __(':spent of :limit, used up', {
                      spent: formatCost(row.budget.countedMicrounits),
                      limit: formatCost(row.budget.limitMicrounits),
                  })
                : __(':spent of :limit', {
                      spent: formatCost(row.budget.countedMicrounits),
                      limit: formatCost(row.budget.limitMicrounits),
                  });
    const columns: Column<AiPluginMonth>[] = [
        {
            id: 'who',
            owner: 'core',
            header: __('From'),
            cell: (row) =>
                row.code === 'all' ? (
                    <span className="font-semibold">{row.label}</span>
                ) : row.code === 'teme' ? (
                    <>
                        {row.label}
                        <span className="text-ink-2 text-caption block">
                            {__('People’s own requests')}
                        </span>
                    </>
                ) : (
                    <PluginMark
                        name={row.label}
                        className="text-small text-ink"
                    />
                ),
        },
        {
            id: 'requests',
            owner: 'core',
            header: __('Requests'),
            className: 'text-right',
            cell: (row) => (
                <span className={row.code === 'all' ? 'font-semibold' : ''}>
                    {row.requests.toLocaleString()}
                </span>
            ),
        },
        {
            id: 'cost',
            owner: 'core',
            header: __('Cost'),
            className: 'text-right',
            cell: (row) => (
                <>
                    <span className={row.code === 'all' ? 'font-semibold' : ''}>
                        {formatCost(row.costMicrounits)}
                    </span>
                    {budgetWords(row) !== null && (
                        <span className="text-ink-2 text-caption block">
                            {budgetWords(row)}
                        </span>
                    )}
                </>
            ),
        },
    ];

    return (
        <section
            aria-labelledby="ai-activity-by-plugin"
            data-test="ai-activity-by-plugin"
            className="grid gap-2"
        >
            <h3
                id="ai-activity-by-plugin"
                className="text-caption text-ink-3 font-semibold uppercase"
            >
                {__('By plugin')}
            </h3>
            <DataTable
                columns={columns}
                rows={[...rows, all]}
                rowKey={(row) => row.code}
                caption={__('AI spend by who asked')}
                rowProps={(row) =>
                    ({
                        'data-test': `ai-activity-plugin-month-${row.code}`,
                    }) as HTMLAttributes<HTMLTableRowElement>
                }
            />
            <p className="text-ink-2 text-small">
                {__(
                    'A plugin’s requests count against the site’s budget, and against its own budget when it has one.',
                )}
            </p>
            {current && canChangeSettings && (
                <div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={showSettings({ group: 'ai' })}>
                            {__('Change the budgets')}
                        </Link>
                    </Button>
                </div>
            )}
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
        from,
        to,
        filters,
        people,
        tasks,
        months,
        plugins,
        summary,
        pluginMonths,
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
        filters.month !== null ||
        filters.plugin !== null;
    const keep: Record<string, string> = {
        ...(filters.person === null ? {} : { person: String(filters.person) }),
        ...(filters.task === null ? {} : { task: filters.task }),
        ...(filters.month === null ? {} : { month: filters.month }),
        ...(filters.plugin === null ? {} : { plugin: filters.plugin }),
    };
    // Teme only is no choice until a plugin has asked.
    const askedBy = plugins.length > 1 || filters.plugin !== null;

    // A filter starts a list afresh: no cursor, no drawer.
    const filter = (
        name: 'person' | 'task' | 'month' | 'plugin',
        value: string,
    ) => {
        const query: Record<string, string> = { ...keep };

        if (value === '') {
            delete query[name];
        } else {
            query[name] = value;
        }

        router.get(index({ query }).url, {}, { preserveScroll: true });
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
                    {row.plugin !== null && (
                        <span
                            className="block"
                            data-test={`ai-row-plugin-${row.id}`}
                        >
                            <PluginMark name={row.plugin.label} />
                        </span>
                    )}
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
                    'Every AI request, newest first. Teme keeps the figures, never the text sent or the answer; when AI’s alt text is used, it also keeps the image’s alt text from before.',
                )}
            >
                {!aiOn && (
                    <Notice
                        tone="warn"
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

                {summary.reached && (
                    <Notice
                        tone="danger"
                        role="status"
                        data-test="ai-activity-budget-reached"
                        title={__('The budget is reached.')}
                    >
                        <span className="flex flex-wrap items-center gap-2">
                            {__(
                                'AI is unavailable to everyone until the new month starts, or until the budget is raised.',
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

                <Summary
                    summary={summary}
                    pluginMonths={pluginMonths}
                    canChangeSettings={canChangeSettings}
                />

                <div
                    role="search"
                    aria-label={__('Filter AI requests')}
                    data-test="list-filters"
                    className="flex flex-wrap items-center gap-2"
                >
                    <SelectField
                        id="ai-person"
                        aria-label={__('Person')}
                        value={
                            filters.person === null
                                ? ''
                                : String(filters.person)
                        }
                        emptyLabel={__('Everyone')}
                        options={people}
                        className="w-52"
                        onValueChange={(next) => filter('person', next)}
                    />
                    {askedBy && (
                        <SelectField
                            id="ai-plugin"
                            aria-label={__('Plugin')}
                            value={filters.plugin ?? ''}
                            emptyLabel={__('Teme and plugins')}
                            options={plugins}
                            className="w-52"
                            onValueChange={(next) => filter('plugin', next)}
                        />
                    )}
                    <SelectField
                        id="ai-task"
                        aria-label={__('Task')}
                        value={filters.task ?? ''}
                        emptyLabel={__('All tasks')}
                        options={tasks}
                        className="w-52"
                        onValueChange={(next) => filter('task', next)}
                    />
                    <SelectField
                        id="ai-month"
                        aria-label={__('Month')}
                        value={filters.month ?? ''}
                        emptyLabel={__('Any month')}
                        options={months}
                        className="w-44"
                        onValueChange={(next) => filter('month', next)}
                    />
                    {filtered && (
                        <Button variant="ghost" asChild>
                            <Link href={index().url}>
                                {__('Clear filters')}
                            </Link>
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        className="ms-auto"
                        disabled
                        data-test="ai-export"
                    >
                        <Download aria-hidden="true" />
                        {__('Export CSV')}
                        <SoonTag />
                    </Button>
                </div>

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
                                icon={WandSparkles}
                                title={__('No AI requests yet')}
                                description={__(
                                    'When someone asks AI for a suggestion, the request appears here with what it cost.',
                                )}
                            />
                        )
                    }
                />

                {from !== null && to !== null && (
                    <div
                        className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2"
                        data-test="ai-activity-pager"
                    >
                        <p
                            className="text-small text-ink-3 tabular-nums"
                            data-test="ai-activity-range"
                        >
                            {__(':from to :to, requests, newest first.', {
                                from: from.toLocaleString(),
                                to: to.toLocaleString(),
                            })}
                        </p>
                        {(newer !== null || older !== null) && (
                            <Pager
                                newer={
                                    newer
                                        ? index({
                                              query: {
                                                  ...keep,
                                                  before: newer,
                                              },
                                          }).url
                                        : null
                                }
                                older={
                                    older
                                        ? index({
                                              query: { ...keep, after: older },
                                          }).url
                                        : null
                                }
                            />
                        )}
                    </div>
                )}

                <AiActivityDetails
                    details={details}
                    siteTimezone={siteTimezone}
                    onClose={() => openDetails(null)}
                />
            </ListPage>
        </>
    );
}
