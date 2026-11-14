import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import ContentListController from '@/actions/App/Http/Admin/Contents/ContentListController';
import { DashboardPanel } from '@/components/dashboard/dashboard-panel';
import { GettingStarted } from '@/components/dashboard/getting-started';
import { QuickDraft } from '@/components/dashboard/quick-draft';
import { SiteFigures } from '@/components/dashboard/site-figures';
import { PageHeader } from '@/components/list/page-header';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { DashboardPage } from '@/types';

/**
 * Home (I-161): the person's own work first — what waits for their review,
 * what they have in review, what came back, their drafts, what is
 * scheduled, what they edited last — each panel only when it has something
 * in it, then the site's figures in one row.
 */
export default function Dashboard({
    panels,
    figures,
    mainType,
    gettingStarted,
    canQuickDraft,
    searchEngines,
}: DashboardPage) {
    // New {singular} opens a new draft in the editor, as the list's Add new
    // does (I-425). No type is named.
    const create = mainType.canCreate && (
        <Form {...ContentListController.store.form({ type: mainType.segment })}>
            {({ processing }) => (
                <Button disabled={processing}>New {mainType.singular}</Button>
            )}
        </Form>
    );

    return (
        <>
            <Head title="Home" />
            <div className="space-y-6 p-6">
                {/* Later (I-200): a labelled "From plugins" section of plugin panels goes here.
                    Later (I-210): an Arrange mode — add, remove, reorder, resize, with a site default.
                    Later (I-221): the one banner slot sits above the page header. */}
                <PageHeader
                    title="Home"
                    description="Your work first, then the site's figures"
                    action={create}
                />

                {searchEngines !== null && (
                    <div
                        role="note"
                        data-test="home-search-engines"
                        className="border-border flex gap-3 rounded-md border p-4 text-sm"
                    >
                        <AlertTriangle
                            aria-hidden="true"
                            className="text-warning mt-0.5 size-4 shrink-0"
                        />
                        <p>
                            <span className="font-medium">
                                {searchEngines.message}
                            </span>{' '}
                            {searchEngines.detail}
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            className="ml-auto shrink-0"
                            asChild
                        >
                            <Link href={searchEngines.actionUrl}>
                                {searchEngines.actionLabel}
                            </Link>
                        </Button>
                    </div>
                )}

                {gettingStarted !== null && (
                    <GettingStarted steps={gettingStarted.steps} />
                )}

                {panels.length === 0 ? (
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="text-muted-foreground text-[13px]">
                            Nothing needs you right now.
                        </p>
                        {create}
                    </div>
                ) : (
                    <div className="grid gap-x-10 gap-y-2 lg:grid-cols-2">
                        {panels.map((panel) => (
                            <DashboardPanel key={panel.key} panel={panel} />
                        ))}
                    </div>
                )}

                {canQuickDraft && <QuickDraft />}

                <SiteFigures figures={figures} />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Home',
            href: dashboard(),
        },
    ],
};
