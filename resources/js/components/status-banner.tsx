import { Link } from '@inertiajs/react';
import { Notice } from '@/components/ui/notice';
import type { StatusBanner as StatusBannerData } from '@/types/status';

const TONES: Record<StatusBannerData['level'], 'danger' | 'warn' | 'info'> = {
    error: 'danger',
    warning: 'warn',
    info: 'info',
};

/**
 * A page-local status notice. The shell no longer draws a follow-you banner;
 * call sites pass the banner they want to show.
 */
export function StatusBanner({ banner }: { banner: StatusBannerData }) {
    return (
        <Notice
            data-test="site-notice"
            tone={TONES[banner.level]}
            action={
                banner.actionUrl !== null && banner.actionLabel !== null ? (
                    <Link href={banner.actionUrl}>{banner.actionLabel}</Link>
                ) : undefined
            }
        >
            <p>{banner.message}</p>
        </Notice>
    );
}
