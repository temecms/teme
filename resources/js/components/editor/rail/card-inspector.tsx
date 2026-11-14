import type { Editor } from '@tiptap/core';
import { NodeSelection } from '@tiptap/pm/state';
import { useEditorState } from '@tiptap/react';
import type { CardManifestEntry } from '@/types';
import { cardSettings } from '../cards/card-views';

/**
 * The selected card's settings, in the rail's one pane (I-234): the same
 * settings its own panel shows, so both edit the one set of attributes.
 */
export function CardInspector({
    editor,
    cards,
}: {
    editor: Editor;
    cards: CardManifestEntry[];
}) {
    const selected = useEditorState({
        editor,
        selector: ({ editor: current }) => {
            const { selection } = current.state;

            return selection instanceof NodeSelection
                ? {
                      name: selection.node.type.name,
                      attrs: selection.node.attrs as Record<string, unknown>,
                  }
                : null;
        },
    });

    if (selected === null) {
        return null;
    }

    const entry = cards.find((card) => card.name === selected.name);
    const Settings = cardSettings[selected.name];

    return (
        <div className="grid gap-3 p-4">
            {entry && (
                <p className="text-muted-foreground text-[13px]">
                    {entry.description}
                </p>
            )}
            {Settings ? (
                <Settings
                    attrs={selected.attrs}
                    update={(attrs) =>
                        editor
                            .chain()
                            .updateAttributes(selected.name, attrs)
                            .run()
                    }
                />
            ) : (
                <p className="text-muted-foreground text-[13px]">
                    This card is edited in place.
                </p>
            )}
        </div>
    );
}
