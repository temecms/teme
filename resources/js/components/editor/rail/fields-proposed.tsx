import { usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';

/**
 * A ready request whose answer is several fields' proposals (I-1313): each
 * is drawn under its own field, so the card says so, takes the person to
 * the first, and puts them all aside.
 */
export function FieldsProposed({
    handles,
    onDiscard,
}: {
    handles: string[];
    onDiscard: () => void;
}) {
    const fields =
        usePage<{ fields?: { handle: string }[] }>().props.fields ?? [];
    const here = handles.some((handle) =>
        fields.some((field) => field.handle === handle),
    );

    // The first proposal, scrolled to, with its Use focused.
    const show = () => {
        const first = document.querySelector<HTMLElement>('[data-ai-proposal]');
        first?.scrollIntoView({ block: 'center' });
        first?.querySelector<HTMLButtonElement>('button')?.focus();
    };

    return (
        <div className="grid gap-2">
            <p>
                {here
                    ? __('The proposals are under their fields.')
                    : __('There’s no field here for these proposals.')}
            </p>
            <div className="flex gap-2">
                {here && (
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="ai-show"
                        onClick={show}
                    >
                        {__('Show them')}
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="outline"
                    data-test="ai-discard"
                    onClick={onDiscard}
                >
                    {__('Discard')}
                </Button>
            </div>
        </div>
    );
}
