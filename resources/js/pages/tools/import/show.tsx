import { Deferred, Head, setLayoutProps, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ChoicesForm } from '@/components/import/choices-form';
import { ConnectForm } from '@/components/import/connect-form';
import { FilePlan } from '@/components/import/file-plan';
import { ImportSteps } from '@/components/import/import-steps';
import { OtherHostsForm } from '@/components/import/other-hosts-form';
import { RunProgress } from '@/components/import/run-progress';
import { RunReport } from '@/components/import/run-report';
import { SiteRuns } from '@/components/import/site-runs';
import {
    AnalysisSkeleton,
    SiteAnalysis,
} from '@/components/import/site-analysis';
import { UploadForm } from '@/components/import/upload-form';
import { ListPage } from '@/components/patterns/list-page';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { __ } from '@/lib/i18n';
import { dayInZone, formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { index as importerIndex } from '@/routes/tools/importer';
import type { ImportPlan, ImportShowPage, ImportSite } from '@/types/import';

/** The plan step, once its deferred data has arrived: the analysis, then the choices. */
function Plan({
    siteId,
    plan,
    siteTimezone,
    listedHosts,
}: {
    siteId: string;
    plan: ImportPlan | null | undefined;
    siteTimezone: string;
    listedHosts: string[];
}) {
    if (plan == null) {
        return <AnalysisSkeleton />;
    }

    return (
        <>
            <OtherHostsForm
                siteId={siteId}
                listed={listedHosts}
                suggested={plan.suggestedHosts}
                remembered={plan.rememberedHosts}
            />

            <SiteAnalysis
                siteId={siteId}
                plan={plan}
                siteTimezone={siteTimezone}
            />

            {plan.form !== null && (
                <Panel aria-labelledby="import-choices">
                    <PanelHeader>
                        <h2
                            id="import-choices"
                            className="text-lead font-semibold"
                        >
                            {__('Your choices')}
                        </h2>
                    </PanelHeader>
                    <PanelBody>
                        <div className="grid max-w-(--form-max) gap-6">
                            <p className="text-body text-ink-2">
                                {__(
                                    'Teme uses these for this import and for each catch-up after it.',
                                )}
                            </p>
                            <ChoicesForm
                                siteId={siteId}
                                form={plan.form}
                                remembered={plan.remembered}
                            />
                        </div>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}

/** A size in megabytes, to one place. */
function megabytes(bytes: number): string {
    return (bytes / (1024 * 1024)).toLocaleString(undefined, {
        maximumFractionDigits: 1,
    });
}

/** The plan of a site read from its export file, with another file's upload when asked for. */
function FileSitePlan({
    site,
    plan,
    maxFileMb,
}: {
    site: ImportSite;
    plan: ImportPlan | null | undefined;
    maxFileMb: number;
}) {
    const [another, setAnother] = useState(false);

    if (plan == null) {
        return <AnalysisSkeleton />;
    }

    return (
        <>
            <FilePlan
                site={site}
                plan={plan}
                onAnotherFile={() => setAnother((open) => !open)}
            />
            {another && <UploadForm maxFileMb={maxFileMb} />}
        </>
    );
}

/** The line under a site's name: its export file, or its live connection. */
function describe(
    site: ImportSite,
    step: ImportShowPage['step'],
    run: ImportShowPage['run'],
    siteTimezone: string,
): string {
    if (step === 'plan' && site.file !== null) {
        const file = site.file;

        return file.madeAt === null
            ? __('Export file :name, :size MB, uploaded :date.', {
                  name: file.name,
                  size: megabytes(file.sizeBytes),
                  date: dayInZone(file.uploadedAt, siteTimezone),
              })
            : __(
                  'Export file :name, :size MB, made in WordPress on :made and uploaded :uploaded.',
                  {
                      name: file.name,
                      size: megabytes(file.sizeBytes),
                      made: dayInZone(file.madeAt, siteTimezone),
                      uploaded: dayInZone(file.uploadedAt, siteTimezone),
                  },
              );
    }

    if (run?.file != null) {
        return __('Export file :name, :size MB, uploaded :date.', {
            name: run.file.name,
            size: megabytes(run.file.sizeBytes),
            date: dayInZone(run.file.uploadedAt, siteTimezone),
        });
    }

    if (site.forgotten) {
        return __('Teme has forgotten this site’s application password.');
    }

    return site.connectedAt === null
        ? ''
        : __('Connected as :name on :date. Teme Companion :version.', {
              name: site.userName ?? '',
              date: formatInZone(site.connectedAt, siteTimezone),
              version: site.pluginVersion ?? '',
          });
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
    rememberedHosts,
    siteTimezone,
    maxFileMb,
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

    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Import'), href: importerIndex() },
        ],
    });

    const description = describe(site, step, run, siteTimezone);
    // A site read from its export file, or an import of one: Upload comes first.
    const fromFile =
        step === 'plan'
            ? site.file !== null
            : run?.file != null || !site.connected;

    return (
        <>
            <Head title={site.host} />

            <ListPage title={site.host} description={description}>
                <ImportSteps
                    step={step}
                    choosing={
                        plan != null &&
                        (plan.form !== null || plan.fileForm !== null)
                    }
                    file={fromFile}
                />

                {step === 'connect' && (
                    <ConnectForm
                        address={site.address}
                        userName={site.userName}
                    />
                )}

                {!site.forgotten && step === 'report' && (
                    <OtherHostsForm
                        siteId={site.id}
                        listed={site.otherHosts}
                        suggested={site.suggestedHosts}
                        remembered={rememberedHosts}
                    />
                )}

                {step === 'plan' && site.file !== null && (
                    <Deferred data="plan" fallback={<AnalysisSkeleton />}>
                        <FileSitePlan
                            site={site}
                            plan={plan}
                            maxFileMb={maxFileMb}
                        />
                    </Deferred>
                )}

                {step === 'plan' && site.file === null && (
                    <Deferred data="plan" fallback={<AnalysisSkeleton />}>
                        <Plan
                            siteId={site.id}
                            plan={plan}
                            siteTimezone={siteTimezone}
                            listedHosts={site.otherHosts}
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
                        maxFileMb={maxFileMb}
                    />
                )}

                {step !== 'plan' && runs.length > 0 && (
                    <SiteRuns
                        runs={runs}
                        shownId={run?.id ?? null}
                        siteTimezone={siteTimezone}
                    />
                )}
            </ListPage>
        </>
    );
}
