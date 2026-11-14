import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { __ } from '@/lib/i18n';
import type { DesignDraft } from '@/types';

function when(iso: string): string {
    return new Date(iso).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/**
 * The design's foot bar while changes wait to go live: who made them and
 * when, then Discard, which asks first, and Publish. It stands where a
 * form's save bar would (docs/architecture/design-system.md, section 4.12).
 */
export function DesignDraftBar({
    draft,
    themeError,
    onPublish,
    onDiscard,
}: {
    draft: DesignDraft;
    /** Publish refused for the theme itself. */
    themeError?: string;
    onPublish: () => void;
    onDiscard: () => void;
}) {
    const [asking, setAsking] = useState(false);

    return (
        <>
            <div
                role="region"
                aria-label={__('Unpublished design changes')}
                className="bg-surface text-body shadow-float sticky bottom-4 z-(--z-sticky) mt-6 flex flex-wrap items-center gap-3 rounded-lg py-3 pr-3 pl-5 max-sm:fixed max-sm:inset-x-2 max-sm:bottom-2"
            >
                <p className="min-w-0 flex-1 font-medium">
                    {draft.by === null
                        ? __('Unpublished design changes, :when', {
                              when: when(draft.at),
                          })
                        : __('Unpublished design changes · by :name, :when', {
                              name: draft.by,
                              when: when(draft.at),
                          })}
                </p>
                <InputError role="alert" message={themeError} />
                <span className="flex gap-2">
                    <Button
                        type="button"
                        variant="neutral"
                        data-test="design-discard"
                        onClick={() => setAsking(true)}
                    >
                        {__('Discard')}
                    </Button>
                    <Button
                        type="button"
                        data-test="design-publish"
                        onClick={onPublish}
                    >
                        {__('Publish')}
                    </Button>
                </span>
            </div>
            <Dialog open={asking} onOpenChange={setAsking}>
                <DialogContent size="sm" data-test="design-discard-dialog">
                    <DialogHeader>
                        <DialogTitle>
                            {__('Discard the unpublished design changes?')}
                        </DialogTitle>
                        <DialogDescription>
                            {__(
                                'Every change waiting to go live is discarded, and the site stays as it is.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="neutral"
                            onClick={() => setAsking(false)}
                        >
                            {__('Keep them')}
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            data-test="design-discard-confirm"
                            onClick={() => {
                                setAsking(false);
                                onDiscard();
                            }}
                        >
                            {__('Discard changes')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
