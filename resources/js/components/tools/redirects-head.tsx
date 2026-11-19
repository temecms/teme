import { InPageNav } from '@/components/patterns/in-page-nav';
import { __ } from '@/lib/i18n';
import { index } from '@/routes/tools/redirects';
import type { RedirectCounts } from '@/types';

/** Tools › Redirects' description, the same on both tabs, looked up as it is drawn. */
export function redirectsDescription(): string {
    return __(
        'Send readers on from an address this site no longer has. The Not found tab lists the addresses readers asked for that don’t exist.',
    );
}

/** Tools › Redirects' two tabs: the redirects, and Not found, the 404 log (I-871). */
export function RedirectsHead({
    tab,
    counts,
}: {
    tab: 'redirects' | 'not-found';
    counts: RedirectCounts;
}) {
    return (
        <InPageNav
            label={__('Redirects and the addresses not found')}
            items={[
                {
                    href: index().url,
                    label: __('Redirects'),
                    count: counts.redirects,
                    current: tab === 'redirects',
                },
                {
                    href: index({ query: { tab: 'not-found' } }).url,
                    label: __('Not found'),
                    count: counts.notFound,
                    current: tab === 'not-found',
                },
            ]}
        />
    );
}
