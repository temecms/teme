import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ImportSourceController from '@/actions/App/Http/Admin/Tools/Import/ImportSourceController';
import { Button } from '@/components/ui/button';
import { LoadingState } from '@/components/ui/loading-state';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import type { ImportPlan } from '@/types/import';

/** What stands in for the analysis while the site report is read. */
export function AnalysisSkeleton() {
    return (
        <LoadingState
            shape="panel"
            label={__('Reading the site report')}
            rows={5}
        />
    );
}

function AnalyzeAgain({ siteId }: { siteId: string }) {
    return (
        <Form
            {...ImportSourceController.analyze.form({ source: siteId })}
            options={{ preserveScroll: true, only: ['plan'] }}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="outline"
                    data-test="import-analyze"
                    disabled={processing}
                >
                    {__('Analyze again')}
                </Button>
            )}
        </Form>
    );
}

/** A section of the analysis: a panel with its heading. */
function Section({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <Panel aria-labelledby={id}>
            <PanelHeader>
                <h2 id={id} className="text-lead font-semibold">
                    {title}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body justify-items-start">
                {children}
            </PanelBody>
        </Panel>
    );
}

/**
 * What comes across and what stays behind, from the site's own report
 * (I-227), with the warning when custom types hold most of its addresses.
 */
export function SiteAnalysis({
    siteId,
    plan,
    siteTimezone,
}: {
    siteId: string;
    plan: ImportPlan;
    siteTimezone: string;
}) {
    const { analysis, problem } = plan;

    return (
        <div data-test="import-analysis" className="grid gap-4">
            {problem !== null && (
                <Section id="import-comes" title={__('What comes across')}>
                    <p>{problem}</p>
                    <AnalyzeAgain siteId={siteId} />
                </Section>
            )}

            {problem === null && analysis !== null && !analysis.reported && (
                <Section id="import-comes" title={__('What comes across')}>
                    <p>
                        {__(
                            'WordPress hasn’t made a site report yet. On the WordPress site, open Tools › Teme site report and run it, then choose Analyze again.',
                        )}
                    </p>
                    <AnalyzeAgain siteId={siteId} />
                </Section>
            )}

            {problem === null && analysis !== null && analysis.reported && (
                <>
                    {analysis.warning !== null && (
                        <Notice tone="warn" data-test="import-warning">
                            <p>
                                {__(
                                    'Most of this site’s addresses belong to custom types: :custom of :total published items. Teme can’t import custom types yet, so those addresses would answer “not found” after the switch. Move this site once Teme can import them.',
                                    {
                                        custom: analysis.warning.custom.toLocaleString(),
                                        total: analysis.warning.total.toLocaleString(),
                                    },
                                )}
                            </p>
                        </Notice>
                    )}

                    <Section id="import-comes" title={__('What comes across')}>
                        <dl className="grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-1 sm:max-w-(--form-max)">
                            {analysis.comes.map((row) => (
                                <div key={row.key} className="contents">
                                    <dt className="text-ink-2">{row.label}</dt>
                                    <dd className="text-end tabular-nums">
                                        {row.count === null
                                            ? __('Not counted in the report')
                                            : row.count.toLocaleString()}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <p>
                            {__(
                                'Every old address keeps working: at the same address, or through a permanent redirect to its new one.',
                            )}
                        </p>
                        {analysis.seo !== null && (
                            <p>
                                {__(
                                    'Each post’s and page’s own SEO fields come from :plugin.',
                                    { plugin: analysis.seo },
                                )}
                            </p>
                        )}
                    </Section>

                    <Section id="import-stays" title={__('Not imported')}>
                        {analysis.stays.length === 0 ? (
                            <p>
                                {__(
                                    'Nothing: everything on this site comes across.',
                                )}
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {analysis.stays.map((row) => (
                                    <li key={row.key}>
                                        <span className="font-medium">
                                            {row.label}
                                        </span>
                                        {row.wordpressKey !== null && (
                                            <>
                                                {' ('}
                                                <span className="font-mono">
                                                    {row.wordpressKey}
                                                </span>
                                                {')'}
                                            </>
                                        )}
                                        , {row.count.toLocaleString()}
                                        <span className="text-ink-2 block">
                                            {row.why}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-small text-ink-2">
                            {analysis.reportedAt === null
                                ? __(
                                      'Counted from the site report made in WordPress. To count again, run Tools › Teme site report there, then choose Analyze again.',
                                  )
                                : __(
                                      'Counted from the site report made in WordPress on :date. To count again, run Tools › Teme site report there, then choose Analyze again.',
                                      {
                                          date: formatInZone(
                                              analysis.reportedAt,
                                              siteTimezone,
                                          ),
                                      },
                                  )}
                        </p>
                        <AnalyzeAgain siteId={siteId} />
                    </Section>
                </>
            )}
        </div>
    );
}
