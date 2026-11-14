import { Form, Head } from '@inertiajs/react';
import { Info, RefreshCw } from 'lucide-react';
import PageCacheController from '@/actions/App/Http/Admin/Tools/PageCacheController';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/list/page-header';
import { Button } from '@/components/ui/button';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { show } from '@/routes/tools/page-cache';
import type { PageCachePage } from '@/types';

/** Tools › Page cache (I-467): how the page cache is set up, and clearing it by hand. */
export default function PageCache({
    on,
    keptIn,
    lives,
    cdn,
    cleared,
    siteTimezone,
}: PageCachePage) {
    return (
        <>
            <Head title="Page cache" />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader
                    title="Page cache"
                    description="Saved copies of public pages. Readers who aren’t signed in get these, so each page is drawn once rather than on every visit."
                    action={
                        on && (
                            <Form
                                {...PageCacheController.clear.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <div className="space-y-2">
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            data-test="page-cache-clear"
                                            aria-describedby="page-cache-error"
                                            disabled={processing}
                                        >
                                            <RefreshCw aria-hidden="true" />
                                            Clear the page cache
                                        </Button>
                                        <InputError
                                            id="page-cache-error"
                                            message={errors.cache}
                                        />
                                    </div>
                                )}
                            </Form>
                        )
                    }
                />

                <section
                    aria-labelledby="page-cache-now"
                    className="border-border rounded-md border p-4"
                >
                    <h2 id="page-cache-now" className="text-base font-medium">
                        Now
                    </h2>
                    <dl className="mt-3 grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-[13px]">
                        <dt className="text-muted-foreground">Status</dt>
                        <dd>{on ? 'On' : 'Off'}</dd>

                        <dt className="text-muted-foreground">Kept in</dt>
                        <dd>{keptIn}</dd>

                        <dt className="text-muted-foreground">
                            How long pages live
                        </dt>
                        <dd>
                            A page: {lives.any}. A search’s page: {lives.search}
                            . A “not found” page: {lives.notFound}.
                        </dd>

                        {cdn !== null && (
                            <>
                                <dt className="text-muted-foreground">
                                    In front of it
                                </dt>
                                <dd>{cdn}, cleared with it</dd>
                            </>
                        )}

                        <dt className="text-muted-foreground">
                            Last cleared here
                        </dt>
                        <dd>
                            {cleared === null
                                ? 'Never'
                                : `${formatInZone(cleared.at, siteTimezone)}, by ${cleared.by}`}
                        </dd>
                    </dl>
                </section>

                <div
                    role="note"
                    data-test="page-cache-notice"
                    className="border-border flex gap-3 rounded-md border p-4 text-sm"
                >
                    <Info
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0"
                    />
                    <div className="space-y-2">
                        {on ? (
                            <p>
                                <strong className="font-medium">
                                    You rarely need to clear it.
                                </strong>{' '}
                                When something is published, changed or taken
                                down, Teme clears the pages that show it
                                straight away. Clear everything only if a page
                                still looks out of date, for example after a
                                theme’s files change.
                            </p>
                        ) : (
                            <p>
                                <strong className="font-medium">
                                    The page cache is off.
                                </strong>{' '}
                                This server doesn’t save pages, so each is drawn
                                on every visit, and there’s nothing to clear.
                                The host switches it on with PAGE_CACHE_ENABLED.
                            </p>
                        )}
                        <p>
                            While you’re signed in you always see pages drawn
                            afresh, never the saved copies. To see what readers
                            get, open the page in a private window.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}

PageCache.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Page cache', href: show() },
    ],
};
