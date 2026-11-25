import type { JSONContent } from '@tiptap/core';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { __ } from '@/lib/i18n';
import { lucideIcon } from '@/lib/lucide-icons';
import type { CardManifest } from '@/types';
import { newCard, newDeclaredCard, newText } from './region-rows';

type Choice = {
    id: string;
    label: string;
    description: string;
    icon: string;
    make: () => JSONContent;
};

/**
 * What the region takes, grouped by where each card comes from: Teme, the
 * theme, then each plugin. Text is always offered; Custom HTML only to
 * someone who may write its code.
 */
export function regionChoices(
    manifest: CardManifest,
    allowed: string[],
    canWriteCode: boolean,
    themeName: string | null,
): { source: string; choices: Choice[] }[] {
    const teme: Choice[] = [
        {
            id: 'paragraph',
            label: __('Text'),
            description: __('A paragraph of your own words.'),
            icon: 'pilcrow',
            make: newText,
        },
        ...manifest.cards
            .filter(
                (card) =>
                    card.inMenus &&
                    allowed.includes(card.name) &&
                    (card.name !== 'site-code' || canWriteCode),
            )
            .map((card) => ({
                id: card.name,
                label: card.menuLabel,
                description: card.description,
                icon: card.icon,
                make: () => newCard(card),
            })),
    ];
    const groups: { source: string; choices: Choice[] }[] = [
        { source: __('Teme'), choices: teme },
    ];
    const themeCards = manifest.themeCards.filter((card) =>
        allowed.includes(card.key),
    );

    if (themeCards.length > 0) {
        groups.push({
            source: themeName ?? themeCards[0].themeName,
            choices: themeCards.map((card) => ({
                id: card.key,
                label: card.label,
                description: card.description,
                icon: card.icon,
                make: () => newDeclaredCard('theme-card', card.key),
            })),
        });
    }

    const byPlugin = new Map<string, Choice[]>();

    for (const card of manifest.pluginCards.filter((entry) =>
        allowed.includes(entry.key),
    )) {
        byPlugin.set(card.plugin, [
            ...(byPlugin.get(card.plugin) ?? []),
            {
                id: card.key,
                label: card.label,
                description: card.description,
                icon: card.icon,
                make: () => newDeclaredCard('plugin-card', card.key),
            },
        ]);
    }

    for (const [plugin, choices] of byPlugin) {
        groups.push({ source: plugin, choices });
    }

    return groups;
}

/** Add card, on Content › Regions: the region's cards by source, one click each. */
export function AddCardDialog({
    open,
    groups,
    onChoose,
    onClose,
}: {
    open: boolean;
    groups: { source: string; choices: Choice[] }[];
    onChoose: (block: JSONContent, label: string) => void;
    onClose: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <ResponsiveDialogContent size="md" data-test="add-card-dialog">
                <DialogHeader>
                    <DialogTitle>{__('Add a card')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'It goes at the end of the region; move it where you want it after.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    {groups.map((group) => (
                        <section
                            key={group.source}
                            className="grid gap-2"
                            aria-label={group.source}
                        >
                            <h3 className="text-caption text-ink-3 font-semibold uppercase">
                                {group.source}
                            </h3>
                            <ul className="grid gap-1">
                                {group.choices.map((choice) => {
                                    const Icon = lucideIcon(choice.icon);

                                    return (
                                        <li key={choice.id}>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                data-card={choice.id}
                                                onClick={() =>
                                                    onChoose(
                                                        choice.make(),
                                                        choice.label,
                                                    )
                                                }
                                                className="h-auto w-full min-w-0 items-start justify-start gap-3 px-2 py-2 text-left whitespace-normal"
                                            >
                                                {Icon !== undefined && (
                                                    <Icon
                                                        aria-hidden
                                                        className="text-ink-2 mt-1 size-4 shrink-0"
                                                    />
                                                )}
                                                <span className="grid min-w-0 gap-0.5">
                                                    <span className="text-ink font-semibold">
                                                        {choice.label}
                                                    </span>
                                                    <span className="text-small text-ink-2">
                                                        {choice.description}
                                                    </span>
                                                </span>
                                            </Button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))}
                </div>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
