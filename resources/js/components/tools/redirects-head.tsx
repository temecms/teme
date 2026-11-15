import { InPageNav } from '@/components/patterns/in-page-nav';
import { __ } from '@/lib/i18n';
import { index, notFound } from '@/routes/tools/redirects';
import type { RedirectCounts } from '@/types';

/** Each of Tools › Redirects' two pages' description, looked up as it is drawn. */
export function redirectsDescription(tab: 'redirects' | 'not-found'): string {
    return tab === 'redirects'
        ? __(
              'Where readers go when they ask for an address this site no longer has.',
          )
        : __(
              'Addresses readers asked for that don’t exist, the most asked for first.',
          );
}

/** The two views both of Tools › Redirects' pages share: the redirects and the 404 log (I-871). */
export function RedirectsHead({
    tab,
    counts,
}: {
    tab: 'redirects' | 'not-found';
    counts: RedirectCounts;
}) {
    return (
        <InPageNav
            label={__('Redirects and the 404 log')}
            items={[
                {
                    href: index().url,
                    label: __('Redirects'),
                    count: counts.redirects,
                    current: tab === 'redirects',
                },
                {
                    href: notFound().url,
                    label: __('404 log'),
                    count: counts.notFound,
                    current: tab === 'not-found',
                },
            ]}
        />
    );
}
