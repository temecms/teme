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
 * The one Status banner (I-221), under the top bar of every admin screen,
 * for something the person may fix: the highest-priority one, drawn as a
 * notice in the page's width. It sits outside `main`, so it is a labelled
 * region of its own, and it has no dismiss: it goes when the problem does
 * (docs/architecture/themes.md).
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
