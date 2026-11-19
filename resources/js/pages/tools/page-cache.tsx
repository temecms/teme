import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import type { CSSProperties } from 'react';
import PageCacheController from '@/actions/App/Http/Admin/Tools/PageCacheController';
import InputError from '@/components/input-error';
import { DashboardPage } from '@/components/patterns/dashboard-page';
import { FigureTiles } from '@/components/tools/figure-tiles';
import { QuietNote } from '@/components/tools/quiet-note';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { SoonTag } from '@/components/ui/soon-tag';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { show } from '@/routes/tools/page-cache';
import type { PageCachePage } from '@/types';

/** The setting the host turns the page cache on with. */
const ENABLED_VARIABLE = 'PAGE_CACHE_ENABLED';

/** An address, as an example of what is typed in. */
const EXAMPLE_ADDRESS = '/news/ferry-fares-to-bonny-island';

/** Tools › Page cache (I-467): how the page cache is set up, and clearing it by hand. */
export default function PageCache({
    on,
    keptIn,
    lives,
    cdn,
    cleared,
    siteTimezone,
}: PageCachePage) {
    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Page cache'), href: show() },
        ],
    });

    return (
        <>
            <Head title={__('Page cache')} />

            <DashboardPage
                title={__('Page cache')}
                description={__(
                    'Saved copies of public pages. Readers who aren’t signed in get these, so each page is drawn once rather than on every visit.',
                )}
                actions={
                    on && (
                        <div className="flex flex-wrap items-start gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled
                                data-test="page-cache-warm-head"
                            >
                                {__('Warm the cache')}
                                <SoonTag />
                            </Button>
                            <Form
                                {...PageCacheController.clear.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <div className="grid gap-2">
                                        <Button
                                            type="submit"
                                            data-test="page-cache-clear"
                                            aria-describedby="page-cache-error"
                                            disabled={processing}
                                        >
                                            <RefreshCw aria-hidden="true" />
                                            {__('Clear the page cache')}
                                        </Button>
                                        <InputError
                                            id="page-cache-error"
                                            message={errors.cache}
                                        />
                                    </div>
                                )}
                            </Form>
                        </div>
                    )
                }
            >
                <div className="grid gap-4">
                    {!on && (
                        <Notice
                            tone="info"
                            data-test="page-cache-notice"
                            title={__('The page cache is off.')}
                        >
                            {__(
                                'This server doesn’t save pages, so each is drawn on every visit, and there’s nothing to clear. The host switches it on with PAGE_CACHE_ENABLED.',
                            )}
                        </Notice>
                    )}

                    <div className="grid items-start gap-4 lg:grid-cols-2">
                        <Panel aria-labelledby="page-cache-now">
                            <PanelHeader>
                                <h2
                                    id="page-cache-now"
                                    className="text-lead font-semibold"
                                >
                                    {__('Now')}
                                </h2>
                                <StatusPill
                                    tone={on ? 'ok' : 'neutral'}
                                    className="ms-auto"
                                >
                                    {on ? __('On') : __('Off')}
                                </StatusPill>
                            </PanelHeader>
                            <PanelBody className="gap-5">
                                <FigureTiles
                                    label={__('Pages stored')}
                                    twoAcross
                                    testId="page-cache-figures"
                                    figures={[
                                        {
                                            key: 'stored',
                                            label: __('Pages stored'),
                                            value: null,
                                        },
                                        {
                                            key: 'pages',
                                            label: __('Articles and pages'),
                                            value: null,
                                        },
                                        {
                                            key: 'search',
                                            label: __('Search pages'),
                                            value: null,
                                        },
                                        {
                                            key: 'not-found',
                                            label: __('“Not found” pages'),
                                            value: null,
                                        },
                                    ]}
                                />
                                <dl
                                    className="text-body grid gap-x-6 gap-y-2 sm:grid-cols-(--facts-cols)"
                                    style={
                                        {
                                            '--facts-cols': 'max-content 1fr',
                                        } as CSSProperties
                                    }
                                >
                                    <dt className="text-small text-ink-2">
                                        {__('Status')}
                                    </dt>
                                    <dd>
                                        {on ? __('On') : __('Off')}{' '}
                                        <span className="text-small text-ink-3">
                                            {__('Set by the host with')}{' '}
                                            <code className="font-mono">
                                                {ENABLED_VARIABLE}
                                            </code>
                                        </span>
                                    </dd>

                                    <dt className="text-small text-ink-2">
                                        {__('Kept in')}
                                    </dt>
                                    <dd>{keptIn}</dd>

                                    <dt className="text-small text-ink-2">
                                        {__('How long pages live')}
                                    </dt>
                                    <dd>
                                        {__(
                                            'A page: :any. A search’s page: :search. A “not found” page: :missing.',
                                            {
                                                any: lives.any,
                                                search: lives.search,
                                                missing: lives.notFound,
                                            },
                                        )}
                                    </dd>

                                    {cdn !== null && (
                                        <>
                                            <dt className="text-small text-ink-2">
                                                {__('In front of it')}
                                            </dt>
                                            <dd>
                                                {__(':cdn, cleared with it', {
                                                    cdn,
                                                })}
                                            </dd>
                                        </>
                                    )}

                                    <dt className="text-small text-ink-2">
                                        {__('Last cleared here')}
                                    </dt>
                                    <dd>
                                        {cleared === null
                                            ? __('Never')
                                            : __(':date, by :name', {
                                                  date: formatInZone(
                                                      cleared.at,
                                                      siteTimezone,
                                                  ),
                                                  name: cleared.by,
                                              })}
                                    </dd>
                                </dl>
                            </PanelBody>
                        </Panel>

                        <div className="grid gap-4">
                            <Panel aria-labelledby="page-cache-one">
                                <PanelHeader>
                                    <h2
                                        id="page-cache-one"
                                        className="text-lead font-semibold"
                                    >
                                        {__('Clear one address')}
                                    </h2>
                                    <SoonTag />
                                </PanelHeader>
                                <PanelBody className="text-body">
                                    <p className="text-ink-2">
                                        {__(
                                            'For one page that still looks out of date. The rest stay saved.',
                                        )}
                                    </p>
                                    <Field
                                        htmlFor="page-cache-address"
                                        label={__('Address on this site')}
                                        help={__(
                                            'A path starting /, as readers see it. It is drawn again on the next visit.',
                                        )}
                                    >
                                        <div className="flex flex-wrap gap-2">
                                            <Input
                                                id="page-cache-address"
                                                disabled
                                                placeholder={EXAMPLE_ADDRESS}
                                                className="min-w-0 flex-1 font-mono"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled
                                            >
                                                {__('Clear this address')}
                                            </Button>
                                        </div>
                                    </Field>
                                </PanelBody>
                            </Panel>

                            <Panel aria-labelledby="page-cache-warm">
                                <PanelHeader>
                                    <h2
                                        id="page-cache-warm"
                                        className="text-lead font-semibold"
                                    >
                                        {__('Warm the cache')}
                                    </h2>
                                    <SoonTag />
                                </PanelHeader>
                                <PanelBody className="text-body justify-items-start">
                                    <p className="text-ink-2">
                                        {__(
                                            'Draw the home page, the newest 400 pages and the menus’ addresses now, so the first readers after a clear don’t wait. Teme does this after every deploy, unless the site is in maintenance mode.',
                                        )}
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled
                                    >
                                        {__('Warm now')}
                                    </Button>
                                </PanelBody>
                            </Panel>
                        </div>
                    </div>

                    <QuietNote testId="page-cache-note">
                        {on && (
                            <p>
                                <strong className="text-ink font-semibold">
                                    {__('You rarely need to clear it.')}
                                </strong>{' '}
                                {__(
                                    'When something is published, changed or taken down, Teme clears the pages that show it straight away. Clear everything only if a page still looks out of date, for example after a theme’s files change.',
                                )}
                            </p>
                        )}
                        <p>
                            {__(
                                'While you’re signed in you always see pages drawn afresh, never the saved copies. To see what readers get, open the page in a private window.',
                            )}
                        </p>
                    </QuietNote>
                </div>
            </DashboardPage>
        </>
    );
}
