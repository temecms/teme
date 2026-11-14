import { Form } from '@inertiajs/react';
import RevisionController from '@/actions/App/Http/Admin/Contents/RevisionController';
import { ConfirmAction } from '@/components/confirm-action';
import { Button } from '@/components/ui/button';

/**
 * Restores one revision, after saying what will happen: a new revision is
 * added from it, and nothing is published or deleted. The editor opens on
 * the result.
 */
export function RestoreButton({
    contentId,
    revisionId,
    createdAt,
    label = 'Restore',
}: {
    contentId: string;
    revisionId: string;
    createdAt: string;
    label?: string;
}) {
    const when = new Date(createdAt).toLocaleString([], {
        dateStyle: 'long',
        timeStyle: 'short',
    });

    return (
        <Form
            {...RevisionController.restore.form({
                content: contentId,
                revision: revisionId,
            })}
        >
            <ConfirmAction
                title="Restore this version?"
                description={`This adds a new revision with the text from ${when}. Nothing is published and nothing is deleted.`}
                confirmLabel="Restore"
                confirmVariant="default"
            >
                <Button type="submit" variant="outline" size="sm">
                    {label}
                </Button>
            </ConfirmAction>
        </Form>
    );
}
