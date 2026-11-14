import { CheckList } from '@/components/editor/rail/checks-panel';
import type { CoreCheck } from '@/components/editor/rail/checks';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatInZone } from '@/lib/zoned-time';

/** A first publication waiting for its confirmation: what it does, to which saved revision, and when. */
export type Confirming = {
    action: 'publish' | 'schedule' | 'submit';
    revisionId: string;
    runAt: string | null;
};

/**
 * The last look before an item first goes live (I-880): core's checks on the
 * revision just saved, worded for publishing. Warnings never stop it; its
 * button publishes, schedules or submits exactly the revision checked.
 */
export function PublishConfirmation({
    confirming,
    title,
    noun,
    siteTimezone,
    checks,
    busy,
    onConfirm,
    onCancel,
}: {
    confirming: Confirming | null;
    title: string;
    noun: string;
    siteTimezone: string;
    checks: CoreCheck[];
    busy: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    const action = confirming?.action ?? 'publish';
    const heading = {
        publish: `Publish this ${noun}?`,
        schedule: `Schedule this ${noun}?`,
        submit: `Submit this ${noun} for review?`,
    }[action];
    const button = {
        publish: 'Publish',
        schedule: 'Schedule',
        submit: 'Submit for review',
    }[action];

    return (
        <Dialog
            open={confirming !== null}
            onOpenChange={(open) => !open && onCancel()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{heading}</DialogTitle>
                    <DialogDescription>
                        The checks on this {noun}, as it was just saved.
                        Warnings don’t stop you.
                    </DialogDescription>
                </DialogHeader>
                <p className="font-medium">{title}</p>
                {action === 'schedule' && confirming?.runAt && (
                    <p className="text-[13px]">
                        It publishes{' '}
                        {formatInZone(confirming.runAt, siteTimezone)} (
                        {siteTimezone}).
                    </p>
                )}
                <CheckList checks={checks} testId="confirmation-checks" />
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        autoFocus
                        disabled={busy}
                        onClick={onConfirm}
                    >
                        {button}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
