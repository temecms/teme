import { Link, usePage } from '@inertiajs/react';
import { CircleCheck, OctagonAlert, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { HeaderPanel } from '@/components/header/header-panel';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import type { StatusBanner } from '@/types/status';

/** A banner's level as a pill's tone. */
const TONE = {
    error: 'danger',
    warning: 'warn',
    info: 'info',
} as const;

/** A banner's level in words, looked up while drawing. */
function levelWord(level: StatusBanner['level']): string {
    if (level === 'error') {
        return __('Problem');
    }

    return level === 'warning' ? __('Needs a look') : __('Note');
}

/**
 * The site status's content: how many things want a look, then one row per
 * status check that has something to say, its pill, its message and its
 * action. Also drawn in the small screens' status sheet.
 */
export function SiteStatusBody({ banners }: { banners: StatusBanner[] }) {
    return (
        <div className="grid gap-3 px-4 py-3">
            <p className="text-small text-ink-2">
                {banners.length === 0
                    ? __('All is well')
                    : __n(
                          ':count to look at|:count to look at',
                          banners.length,
                      )}
            </p>
            {banners.length > 0 && (
                <ul className="divide-line grid divide-y">
                    {banners.map((banner, index) => (
                        <li
                            key={index}
                            data-test="site-status-row"
                            className="grid justify-items-start gap-2 py-3 first:pt-0 last:pb-0"
                        >
                            <StatusPill tone={TONE[banner.level]}>
                                {levelWord(banner.level)}
                            </StatusPill>
                            <p className="text-small text-ink-2">
                                {banner.message}
                            </p>
                            {banner.actionUrl !== null &&
                                banner.actionLabel !== null && (
                                    <Button variant="neutral" size="sm" asChild>
                                        <Link href={banner.actionUrl}>
                                            {banner.actionLabel}
                                        </Link>
                                    </Button>
                                )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * The top bar's site status (I-3397): a tick when every status check is
 * content, else a warning with how many want a look; it opens a 400px panel
 * listing them, read from the shared `siteStatus`.
 */
export function SiteStatus() {
    const { siteStatus } = usePage().props;
    const [open, setOpen] = useState(false);
    const count = siteStatus.length;
    const Icon =
        count === 0
            ? CircleCheck
            : siteStatus.some((banner) => banner.level === 'error')
              ? OctagonAlert
              : TriangleAlert;

    return (
        <HeaderPanel
            open={open}
            onOpenChange={setOpen}
            title={__('Site status')}
            testId="site-status-panel"
            trigger={
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-test="site-status-button"
                    aria-label={
                        count === 0
                            ? __('Site status: all is well')
                            : __n(
                                  'Site status: :count needs a look|Site status: :count need a look',
                                  count,
                              )
                    }
                >
                    <Icon aria-hidden="true" />
                    {count > 0 && <span aria-hidden="true">{count}</span>}
                </Button>
            }
        >
            <SiteStatusBody banners={siteStatus} />
        </HeaderPanel>
    );
}
