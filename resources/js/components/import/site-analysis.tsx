import { Form } from '@inertiajs/react';
import { Info } from 'lucide-react';
import ImportSourceController from '@/actions/App/Http/Admin/Tools/Import/ImportSourceController';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatInZone } from '@/lib/zoned-time';
import type { ImportPlan } from '@/types/import';

/** What stands in for the analysis while the site report is read. */
export function AnalysisSkeleton() {
    return (
        <div className="space-y-4">
            <Skeleton className="h-40 w-full max-w-[640px]" />
            <Skeleton className="h-28 w-full max-w-[640px]" />
            <p role="status" className="sr-only">
                Reading the site report…
            </p>
        </div>
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
                    Analyze again
                </Button>
            )}
        </Form>
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
        <section
            data-test="import-analysis"
            aria-labelledby="import-comes"
            className="max-w-[640px] space-y-5"
        >
            {problem !== null && (
                <div className="space-y-3">
                    <h2 id="import-comes" className="text-base font-medium">
                        What comes across
                    </h2>
                    <p className="text-[13px]">{problem}</p>
                    <AnalyzeAgain siteId={siteId} />
                </div>
            )}

            {problem === null && analysis !== null && !analysis.reported && (
                <div className="space-y-3">
                    <h2 id="import-comes" className="text-base font-medium">
                        What comes across
                    </h2>
                    <p className="text-[13px]">
                        WordPress hasn’t made a site report yet. On the
                        WordPress site, open Tools › Teme site report and run
                        it, then choose Analyze again.
                    </p>
                    <AnalyzeAgain siteId={siteId} />
                </div>
            )}

            {problem === null && analysis !== null && analysis.reported && (
                <>
                    {analysis.warning !== null && (
                        <div
                            role="note"
                            data-test="import-warning"
                            className="border-line flex gap-3 rounded-md border p-4 text-sm"
                        >
                            <Info
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0"
                            />
                            <p>
                                Most of this site’s addresses belong to custom
                                types:{' '}
                                {analysis.warning.custom.toLocaleString()} of{' '}
                                {analysis.warning.total.toLocaleString()}{' '}
                                published items. Teme can’t import custom types
                                yet, so those addresses would answer “not found”
                                after the switch. Move this site once Teme can
                                import them.
                            </p>
                        </div>
                    )}

                    <div className="space-y-3">
                        <h2 id="import-comes" className="text-base font-medium">
                            What comes across
                        </h2>
                        <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1 text-[13px]">
                            {analysis.comes.map((row) => (
                                <div key={row.key} className="contents">
                                    <dt>{row.label}</dt>
                                    <dd className="tabular-nums">
                                        {row.count.toLocaleString()}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <p className="text-[13px]">
                            Every old address keeps working: at the same
                            address, or through a permanent redirect to its new
                            one.
                        </p>
                        {analysis.seo !== null && (
                            <p className="text-[13px]">
                                Each post’s and page’s own SEO fields come from{' '}
                                {analysis.seo}.
                            </p>
                        )}
                    </div>

                    <div className="space-y-3">
                        <h2 className="text-base font-medium">Not imported</h2>
                        {analysis.stays.length === 0 ? (
                            <p className="text-[13px]">
                                Nothing: everything on this site comes across.
                            </p>
                        ) : (
                            <ul className="space-y-2 text-[13px]">
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
                    </div>

                    <div className="space-y-3">
                        <p className="text-ink-2 text-[13px]">
                            Counted from the site report made in WordPress
                            {analysis.reportedAt !== null &&
                                ` on ${formatInZone(analysis.reportedAt, siteTimezone)}`}
                            . To count again, run Tools › Teme site report
                            there, then choose Analyze again.
                        </p>
                        <AnalyzeAgain siteId={siteId} />
                    </div>
                </>
            )}
        </section>
    );
}
