import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { __, __n } from '@/lib/i18n';
import type { HiddenThemeCard } from '@/types';

/**
 * Asks before a switch hides the active theme's own cards
 * (docs/guides/developers/themes/regions.md): each card and how many of
 * its regions hold it. A notice with a confirm, never a block: the cards
 * are kept, and come back with the theme.
 */
export function SwitchWarning({
    hidden,
    leaving,
    target,
    onConfirm,
    onCancel,
}: {
    hidden: HiddenThemeCard[];
    /** The active theme's name. */
    leaving: string;
    /** The theme about to be activated; null while nothing is asked. */
    target: { code: string; name: string } | null;
    onConfirm: (code: string) => void;
    onCancel: () => void;
}) {
    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => !open && onCancel()}
        >
            <ResponsiveDialogContent phone="foot" data-test="switch-warning">
                <DialogHeader>
                    <DialogTitle>
                        {__('Activate :name?', { name: target?.name ?? '' })}
                    </DialogTitle>
                    <DialogDescription>
                        {__(
                            'Switching hides the cards :theme brings. They are kept under Regions › Other themes and come back with :theme.',
                            { theme: leaving },
                        )}
                    </DialogDescription>
                </DialogHeader>
                <ul className="text-small grid gap-1">
                    {hidden.map((card) => (
                        <li key={card.card}>
                            {__n(
                                ':card, in :count region|:card, in :count regions',
                                card.regions,
                                { card: card.label },
                            )}
                        </li>
                    ))}
                </ul>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">{__('Cancel')}</Button>
                    </DialogClose>
                    <Button
                        data-test="switch-warning-confirm"
                        onClick={() =>
                            target !== null && onConfirm(target.code)
                        }
                    >
                        {__('Activate :name', { name: target?.name ?? '' })}
                    </Button>
                </DialogFooter>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
