import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';

/**
 * A comment's state on its pill, over its text in every view: Pending in
 * amber with its triangle, Approved, Spam, In trash.
 */
export function CommentStatusPill({ status }: { status: string }) {
    if (status === 'pending') {
        return <StatusPill tone="warn">{__('Pending')}</StatusPill>;
    }

    if (status === 'approved') {
        return <StatusPill tone="ok">{__('Approved')}</StatusPill>;
    }

    if (status === 'spam') {
        return <StatusPill tone="danger">{__('Spam')}</StatusPill>;
    }

    return <StatusPill tone="neutral">{__('In trash')}</StatusPill>;
}
