import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { edit as editComment } from '@/routes/comments';
import type { CommentRow } from '@/types';

/** What Quick edit needs of a comment: a row on the page, or the open drawer's comment. */
export type QuickEditTarget = Pick<
    CommentRow,
    'id' | 'authorName' | 'authorEmail' | 'authorUrl' | 'text' | 'plain'
>;

const FIELD =
    'border-edge bg-page focus-visible:ring-ring rounded-md border px-3 py-2 text-[13px] outline-none focus-visible:ring-2';

/**
 * Quick edit (C3; 1.8c′'s D9; v9's openCommentQuickEdit): a comment's name,
 * email address, website and words in a sheet, saved by EditComment, which
 * keeps the comment's formatting unless its words change, and whose hint says
 * first when they would lose it.
 */
export function QuickEdit({
    comment,
    onClose,
}: {
    comment: QuickEditTarget | null;
    onClose: () => void;
}) {
    const form = useForm({
        id: comment?.id ?? '',
        name: comment?.authorName ?? '',
        email: comment?.authorEmail ?? '',
        url: comment?.authorUrl ?? '',
        text: comment?.text ?? '',
    });

    if (comment === null) {
        return null;
    }

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                // The sheet's own title says what it is.
                aria-describedby={undefined}
                className="w-full overflow-y-auto sm:max-w-[480px]"
            >
                <SheetHeader>
                    <SheetTitle>
                        {`Quick edit: comment by ${comment.authorName}`}
                    </SheetTitle>
                </SheetHeader>
                <form
                    noValidate
                    className="grid gap-4 px-4 pb-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(editComment().url, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="qe-name">Name</Label>
                        <Input
                            id="qe-name"
                            name="name"
                            maxLength={100}
                            autoFocus
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-invalid={form.errors.name ? true : undefined}
                            aria-describedby="qe-name-error"
                        />
                        <InputError
                            id="qe-name-error"
                            message={form.errors.name}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="qe-email">Email</Label>
                        <Input
                            id="qe-email"
                            name="email"
                            type="email"
                            maxLength={254}
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                            aria-invalid={form.errors.email ? true : undefined}
                            aria-describedby="qe-email-error"
                        />
                        <InputError
                            id="qe-email-error"
                            message={form.errors.email}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="qe-url">Website</Label>
                        <Input
                            id="qe-url"
                            name="url"
                            type="url"
                            maxLength={200}
                            placeholder="https://example.com"
                            value={form.data.url}
                            onChange={(event) =>
                                form.setData('url', event.target.value)
                            }
                            aria-invalid={form.errors.url ? true : undefined}
                            aria-describedby="qe-url-error"
                        />
                        <InputError
                            id="qe-url-error"
                            message={form.errors.url}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="qe-text">Comment</Label>
                        <textarea
                            id="qe-text"
                            name="text"
                            rows={8}
                            maxLength={5000}
                            className={FIELD}
                            value={form.data.text}
                            onChange={(event) =>
                                form.setData('text', event.target.value)
                            }
                            aria-invalid={form.errors.text ? true : undefined}
                            aria-describedby="qe-text-hint qe-text-error"
                        />
                        <p id="qe-text-hint" className="text-ink-2 text-[12px]">
                            Plain text, as readers write it: a blank line starts
                            a paragraph, and web addresses become links. Up to
                            5,000 characters.
                            {!comment.plain &&
                                ' This comment has formatting, such as bold or a quote. If you change its text, it’s saved as plain text.'}
                        </p>
                        <InputError
                            id="qe-text-error"
                            message={form.errors.text}
                        />
                    </div>
                    <SheetFooter className="flex-row justify-end gap-2 p-0">
                        <SheetClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </SheetClose>
                        <Button type="submit" disabled={form.processing}>
                            Save changes
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
