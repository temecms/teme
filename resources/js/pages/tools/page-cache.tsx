import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import type { CSSProperties } from 'react';
import PageCacheController from '@/actions/App/Http/Admin/Tools/PageCacheController';
import InputError from '@/components/input-error';
import { FormPage } from '@/components/patterns/form-page';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
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
    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Page cache'), href: show() },
        ],
    });

    return (
        <>
            <Head title={__('Page cache')} />

            <FormPage
                title={__('Page cache')}
                description={__(
                    'Saved copies of public pages. Readers who aren’t signed in get these, so each page is drawn once rather than on every visit.',
                )}
                actions={
                    on && (
                        <Form
                            {...PageCacheController.clear.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div className="grid gap-2">
                                    <Button
                                        type="submit"
                                        variant="outline"
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
                    )
                }
            >
                <div className="grid gap-4">
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
                        <PanelBody>
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
                                <dd>{on ? __('On') : __('Off')}</dd>

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

                    <Notice
                        tone="info"
                        data-test="page-cache-notice"
                        title={
                            on
                                ? __('You rarely need to clear it.')
                                : __('The page cache is off.')
                        }
                    >
                        <div className="grid gap-2">
                            {on ? (
                                <p>
                                    {__(
                                        'When something is published, changed or taken down, Teme clears the pages that show it straight away. Clear everything only if a page still looks out of date, for example after a theme’s files change.',
                                    )}
                                </p>
                            ) : (
                                <p>
                                    {__(
                                        'This server doesn’t save pages, so each is drawn on every visit, and there’s nothing to clear. The host switches it on with PAGE_CACHE_ENABLED.',
                                    )}
                                </p>
                            )}
                            <p>
                                {__(
                                    'While you’re signed in you always see pages drawn afresh, never the saved copies. To see what readers get, open the page in a private window.',
                                )}
                            </p>
                        </div>
                    </Notice>
                </div>
            </FormPage>
        </>
    );
}
