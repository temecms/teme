import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { AlertTriangle, Info, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { StatusBanner as StatusBannerData } from '@/types/status';

const LEVELS: Record<
    StatusBannerData['level'],
    { Icon: LucideIcon; tint: string; icon: string }
> = {
    error: {
        Icon: XCircle,
        tint: 'bg-destructive-muted',
        icon: 'text-destructive',
    },
    warning: {
        Icon: AlertTriangle,
        tint: 'bg-warning-muted',
        icon: 'text-warning',
    },
    info: { Icon: Info, tint: 'bg-muted', icon: 'text-muted-foreground' },
};

/**
 * The one Status banner (I-221), under the header of every admin screen, for
 * something the person may fix: the highest-priority one. It sits outside
 * `main`, so it is a labelled region of its own, and it has no dismiss: it
 * goes when the problem does (docs/architecture/themes.md).
 */
export function StatusBanner() {
    const { statusBanner } = usePage().props;

    if (statusBanner === null || statusBanner === undefined) {
        return null;
    }

    const level = LEVELS[statusBanner.level];

    // Later (I-1392): a dismissal kept per person and version, and a line counting the notices waiting behind this one.
    return (
        <div
            role="region"
            aria-label="Site notice"
            data-test="site-notice"
            className={cn(
                'border-border flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-6 py-2.5 text-[13px] max-md:px-4',
                level.tint,
            )}
        >
            <level.Icon
                className={cn('size-4 shrink-0', level.icon)}
                aria-hidden="true"
            />
            <p className="min-w-0 flex-1">{statusBanner.message}</p>
            {statusBanner.actionUrl !== null &&
                statusBanner.actionLabel !== null && (
                    <Link
                        href={statusBanner.actionUrl}
                        className="font-medium underline underline-offset-2"
                    >
                        {statusBanner.actionLabel}
                    </Link>
                )}
        </div>
    );
}
