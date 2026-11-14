import type { ReactNode } from 'react';
import { PageHeader } from '@/components/list/page-header';
import { TabStrip } from '@/components/list/tab-strip';
import { index, notFound } from '@/routes/tools/redirects';
import type { RedirectCounts } from '@/types';

const DESCRIPTIONS = {
    redirects:
        'Where readers go when they ask for an address this site no longer has.',
    'not-found':
        'Addresses readers asked for that don’t exist, the most asked for first.',
} as const;

/**
 * The heading both of Tools › Redirects' pages share: its description for the
 * page shown, the actions beside it, and the two tabs (I-871).
 */
export function RedirectsHead({
    tab,
    counts,
    actions,
}: {
    tab: 'redirects' | 'not-found';
    counts: RedirectCounts;
    actions?: ReactNode;
}) {
    return (
        <>
            <PageHeader
                title="Redirects"
                description={DESCRIPTIONS[tab]}
                action={actions}
            />

            <TabStrip
                label="Redirects and the 404 log"
                current={tab}
                tabs={[
                    {
                        key: 'redirects',
                        label: 'Redirects',
                        count: counts.redirects,
                        href: index(),
                    },
                    {
                        key: 'not-found',
                        label: '404 log',
                        count: counts.notFound,
                        href: notFound(),
                    },
                ]}
            />
        </>
    );
}
