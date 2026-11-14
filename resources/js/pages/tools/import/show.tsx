import { Deferred, Head, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { ChoicesForm } from '@/components/import/choices-form';
import { ConnectForm } from '@/components/import/connect-form';
import { ImportSteps } from '@/components/import/import-steps';
import { RunProgress } from '@/components/import/run-progress';
import { RunReport } from '@/components/import/run-report';
import { SiteRuns } from '@/components/import/site-runs';
import {
    AnalysisSkeleton,
    SiteAnalysis,
} from '@/components/import/site-analysis';
import { PageHeader } from '@/components/list/page-header';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { index as importerIndex } from '@/routes/tools/importer';
import type { ImportPlan, ImportShowPage } from '@/types/import';

/** The plan step, once its deferred data has arrived: the analysis, then the choices. */
function Plan({
    siteId,
    plan,
    siteTimezone,
}: {
    siteId: string;
    plan: ImportPlan | null | undefined;
    siteTimezone: string;
}) {
    if (plan == null) {
        return <AnalysisSkeleton />;
    }

    return (
        <div className="space-y-8">
            <SiteAnalysis
                siteId={siteId}
                plan={plan}
                siteTimezone={siteTimezone}
            />

            {plan.form !== null && (
                <section
                    aria-labelledby="import-choices"
                    className="max-w-[640px] space-y-4"
                >
                    <div className="space-y-1">
                        <h2
                            id="import-choices"
                            className="text-base font-medium"
                        >
                            Your choices
                        </h2>
                        <p className="text-ink-2 text-[13px]">
                            Teme uses these for this import and for each
                            catch-up after it.
                        </p>
                    </div>
                    <ChoicesForm
                        siteId={siteId}
                        form={plan.form}
                        remembered={plan.remembered}
                    />
                </section>
            )}
        </div>
    );
}

/** A site's page (I-227): its five steps, and the section for the one it stands at. */
export default function ImportShow({
    site,
    step,
    run,
    job,
    plan,
    latest,
    runs,
    report,
    downloads,
    siteTimezone,
}: ImportShowPage) {
    const { start, stop } = usePoll(
        3000,
        {
            only: [
                'step',
                'run',
                'job',
                'latest',
                'runs',
                'report',
                'downloads',
            ],
        },
        { autoStart: false },
    );
    const running = run?.state === 'running';

    useEffect(() => {
        if (running) {
            start();
        } else {
            stop();
        }
    }, [running, start, stop]);

    const description = site.forgotten
        ? 'Teme has forgotten this site’s application password.'
        : `Connected as ${site.userName ?? ''} on ${formatInZone(site.connectedAt, siteTimezone)}. Teme Companion ${site.pluginVersion}.`;

    return (
        <>
            <Head title={site.host} />

            <div className="space-y-8 p-6">
                <PageHeader title={site.host} description={description} />

                <ImportSteps step={step} />

                {step === 'connect' && (
                    <ConnectForm
                        address={site.address}
                        userName={site.userName}
                    />
                )}

                {step === 'plan' && (
                    <Deferred data="plan" fallback={<AnalysisSkeleton />}>
                        <Plan
                            siteId={site.id}
                            plan={plan}
                            siteTimezone={siteTimezone}
                        />
                    </Deferred>
                )}

                {step === 'import' && run !== null && (
                    <RunProgress run={run} job={job} />
                )}

                {step !== 'plan' && run !== null && report !== null && (
                    <RunReport
                        site={site}
                        run={run}
                        report={report}
                        downloads={downloads}
                        latest={latest}
                        siteTimezone={siteTimezone}
                    />
                )}

                {step !== 'plan' && runs.length > 0 && (
                    <SiteRuns
                        runs={runs}
                        shownId={run?.id ?? null}
                        siteTimezone={siteTimezone}
                    />
                )}
            </div>
        </>
    );
}

ImportShow.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Import', href: importerIndex() },
    ],
};
