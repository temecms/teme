import { Form } from '@inertiajs/react';
import RevisionController from '@/actions/App/Http/Admin/Contents/RevisionController';
import { ConfirmAction } from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import { formatDateTime } from '@/lib/dates';

/**
 * Restores one revision, after saying what will happen: a new revision is
 * added from it, and nothing is published or deleted. The editor opens on
 * the result.
 */
export function RestoreButton({
    contentId,
    revisionId,
    createdAt,
    label,
}: {
    contentId: string;
    revisionId: string;
    createdAt: string;
    label?: string;
}) {
    const when = formatDateTime(createdAt);

    return (
        <Form
            {...RevisionController.restore.form({
                content: contentId,
                revision: revisionId,
            })}
        >
            <ConfirmAction
                title={__('Restore this version?')}
                description={__(
                    'This adds a new revision with the text from :when. Nothing is published and nothing is deleted.',
                    { when },
                )}
                confirmLabel={__('Restore')}
                confirmVariant="default"
            >
                <Button type="submit" variant="outline" size="sm">
                    {label ?? __('Restore')}
                </Button>
            </ConfirmAction>
        </Form>
    );
}
