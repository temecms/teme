import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import type { StatusBanner as StatusBannerData } from '@/types/status';

/** The notice's tone for each banner level. */
const TONES: Record<StatusBannerData['level'], 'danger' | 'warn' | 'info'> = {
    error: 'danger',
    warning: 'warn',
    info: 'info',
};

/**
 * A page-local status notice drawn as a labelled region. Site-wide problems
 * do not follow every screen (design system 4.33); they live in the top-bar
 * site status and on Home. A page may still place this notice in its own
 * flow. It has no dismiss: it goes when the problem does.
 */
export function StatusBanner() {
    const { statusBanner } = usePage().props;

    if (statusBanner === null || statusBanner === undefined) {
        return null;
    }

    // Later (I-1392): a dismissal kept per person and version, and a line counting the notices waiting behind this one.
    return (
        <div
            role="region"
            aria-label="Site notice"
            data-test="site-notice"
            className="mx-auto w-full max-w-(--page-max) px-8 pt-2 max-md:px-4"
        >
            <Notice
                tone={TONES[statusBanner.level]}
                action={
                    statusBanner.actionUrl !== null &&
                    statusBanner.actionLabel !== null ? (
                        <Button variant="neutral" size="sm" asChild>
                            <Link href={statusBanner.actionUrl}>
                                {statusBanner.actionLabel}
                            </Link>
                        </Button>
                    ) : undefined
                }
            >
                <p>{statusBanner.message}</p>
            </Notice>
        </div>
    );
}
