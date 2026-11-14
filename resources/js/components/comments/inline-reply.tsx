import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { reply } from '@/routes/comments';
import type { CommentRow } from '@/types';

/**
 * A reply written under a comment in the list (C6; 1.8c′'s D11; v9's row
 * form): approved at once, and approving the comment it answers when that one
 * waits.
 */
export function InlineReply({
    row,
    onClose,
}: {
    row: CommentRow;
    /** Whether the reply was posted: where focus goes next depends on it. */
    onClose: (posted: boolean) => void;
}) {
    const form = useForm({ id: row.id, text: '' });
    const waiting = row.status === 'pending';

    return (
        <form
            noValidate
            className="mt-2 grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(reply().url, {
                    preserveScroll: true,
                    onSuccess: () => onClose(true),
                });
            }}
        >
            <Label htmlFor={`reply-${row.id}`}>
                {`Reply to ${row.authorName}`}
            </Label>
            <textarea
                id={`reply-${row.id}`}
                name="text"
                rows={3}
                maxLength={5000}
                autoFocus
                className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-[13px] outline-none focus-visible:ring-2"
                value={form.data.text}
                onChange={(event) => form.setData('text', event.target.value)}
                aria-invalid={form.errors.text ? true : undefined}
                aria-describedby={
                    waiting
                        ? `reply-${row.id}-hint reply-${row.id}-error`
                        : `reply-${row.id}-error`
                }
            />
            {waiting && (
                <p
                    id={`reply-${row.id}-hint`}
                    className="text-muted-foreground text-[12px]"
                >
                    Replying also approves this comment.
                </p>
            )}
            <InputError
                id={`reply-${row.id}-error`}
                message={form.errors.text}
            />
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    {waiting ? 'Reply and approve' : 'Reply'}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onClose(false)}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
