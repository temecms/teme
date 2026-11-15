import type { Editor } from '@tiptap/core';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import { typedAddress } from './link-address';
import { isInternalLink } from './schema';
import { useLinkChoices } from './use-link-choices';
import type { LinkChoice } from './use-link-choices';

/** The event Ctrl K (⌘ K) sends from the text: whoever opens the link editor cancels it. */
export const EDIT_LINK = 'teme:edit-link';

/**
 * Whether what was typed reads as an address rather than words: no spaces,
 * and a dot, a colon, a slash or a #.
 */
function looksLikeAddress(typed: string): boolean {
    const trimmed = typed.trim();

    return trimmed !== '' && !/\s/.test(trimmed) && /[.:/#]/.test(trimmed);
}

/** What the panel will link to: the option shown on the Combobox, and the address it keeps. */
type Chosen = { option: ComboboxOption; href: string };

/**
 * The link editor (docs/architecture/design-system.md, 4.39): one Combobox
 * that finds something on this site by its title or name, stored by its ID
 * and never as a URL (rule 9), and offers an address typed or pasted as its
 * first option; this site's own address becomes the item's ID as
 * link-address.ts reads it. Then "Open in a new tab", Remove link and
 * Apply. Esc closes it and returns to the text.
 */
export function LinkPanel({
    editor,
    siteUrl,
    onDone,
}: {
    editor: Editor;
    /** The site's address, for links to the site itself. */
    siteUrl: string;
    onDone: () => void;
}) {
    // Mounted only while open.
    const open = true;
    const [query, setQuery] = useState('');
    const { groups, loading } = useLinkChoices(query, open);
    const attributes = editor.getAttributes('link');
    const current = (attributes.href as string | undefined) ?? '';
    const [newTab, setNewTab] = useState(attributes.target === '_blank');
    const [chosen, setChosen] = useState<Chosen | null>(() =>
        current !== '' && !isInternalLink(current)
            ? {
                  option: { value: `href:${current}`, label: current },
                  href: current,
              }
            : null,
    );
    // Every choice offered so far, so the one chosen is found after the search moves on.
    const seen = useRef(new Map<string, LinkChoice>());
    const panel = useRef<HTMLDivElement>(null);
    const fieldId = useId();
    const hintId = `${fieldId}-hint`;

    // The address typed or pasted, as the link would keep it, or null for words.
    const typedHref = useMemo(() => {
        const address = query;

        if (!looksLikeAddress(address)) {
            return null;
        }

        const typed = typedAddress(address, siteUrl);

        return 'href' in typed ? typed.href : null;
    }, [query, siteUrl]);

    const options = useMemo((): ComboboxOption[] => {
        const found = groups.flatMap((group) =>
            group.choices.map((choice): ComboboxOption => {
                seen.current.set(choice.href, choice);

                return {
                    value: choice.href,
                    label: shownTitle(choice.title),
                    description: choice.note ?? undefined,
                    group: group.label,
                };
            }),
        );

        return typedHref === null
            ? found
            : [
                  {
                      value: `href:${typedHref}`,
                      label: __('Link to “:address”', { address: typedHref }),
                      group: __('Address'),
                  },
                  ...found,
              ];
    }, [groups, typedHref]);

    // The panel opens on its Combobox, whose button carries the field's id;
    // the bubble shows its panel a frame or two after it is drawn, so focus
    // is tried until it lands.
    useEffect(() => {
        let tries = 0;
        let frame = 0;
        const focus = (): void => {
            const button = document.getElementById(fieldId);

            button?.focus();

            if (document.activeElement !== button && tries < 30) {
                tries += 1;
                frame = requestAnimationFrame(focus);
            }
        };

        focus();

        return () => cancelAnimationFrame(frame);
    }, [fieldId]);

    const close = () => {
        onDone();
        editor.commands.focus();
    };

    const apply = (href: string) => {
        editor
            .chain()
            .focus()
            .extendMarkRange('link')
            .setLink({ href, target: newTab ? '_blank' : null })
            .run();
        onDone();
    };

    const remove = () => {
        editor.chain().focus().extendMarkRange('link').unsetLink().run();
        onDone();
    };

    const confirm = () => {
        if (chosen === null) {
            return;
        }

        if (chosen.option.value.startsWith('href:')) {
            apply(chosen.href);

            return;
        }

        const choice = seen.current.get(chosen.option.value);

        if (choice !== undefined) {
            apply(choice.href);
        }
    };

    return (
        <div
            ref={panel}
            data-test="link-editor"
            role="group"
            aria-labelledby={`${fieldId}-label`}
            className="bg-surface grid w-90 max-w-full gap-3 rounded-lg p-3"
            onKeyDown={(event: KeyboardEvent<HTMLDivElement>) => {
                // Esc inside the Combobox's own list closes only the list.
                if (
                    event.key === 'Escape' &&
                    panel.current?.contains(event.target as Node)
                ) {
                    event.preventDefault();
                    event.stopPropagation();
                    close();
                }
            }}
        >
            <div className="grid gap-2">
                <Label id={`${fieldId}-label`} htmlFor={fieldId}>
                    {__('Link to')}
                </Label>
                <Combobox
                    id={fieldId}
                    options={options}
                    value={chosen?.option ?? null}
                    onValueChange={(option) => {
                        if (option === null) {
                            return;
                        }

                        setChosen({
                            option,
                            href: option.value.startsWith('href:')
                                ? option.value.slice(5)
                                : option.value,
                        });
                    }}
                    placeholder="Search by title or name"
                    searchPlaceholder={__(
                        'Search by title or name, or paste an address',
                    )}
                    search={{ value: query, onChange: setQuery, loading }}
                    aria-describedby={hintId}
                />
                <p id={hintId} className="text-small text-ink-3">
                    {__(
                        'Link to something on this site by its title or name: the link keeps working when its address changes. Or paste an address.',
                    )}
                </p>
            </div>
            <div className="flex items-center gap-2">
                <Checkbox
                    id={`${fieldId}-tab`}
                    checked={newTab}
                    onCheckedChange={(checked) => setNewTab(checked === true)}
                />
                <Label htmlFor={`${fieldId}-tab`}>
                    {__('Open in a new tab')}
                </Label>
            </div>
            <div className="flex items-center gap-2">
                {current !== '' && (
                    <TextButton type="button" onClick={remove}>
                        {__('Remove link')}
                    </TextButton>
                )}
                <Button
                    type="button"
                    size="sm"
                    className="ml-auto"
                    disabled={chosen === null}
                    onClick={confirm}
                >
                    {__('Apply')}
                </Button>
            </div>
        </div>
    );
}

/**
 * The toolbar's Link: the link editor in a popover under the button. Ctrl K
 * opens it when no text is selected (the selection's bubble takes Ctrl K
 * when some is).
 */
export function LinkPopover({
    editor,
    trigger,
    siteUrl,
}: {
    editor: Editor;
    trigger: ReactNode;
    /** The site's address, for links to the site itself. */
    siteUrl: string;
}) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const dom = editor.view.dom;
        const asked = (event: Event): void => {
            if (!event.defaultPrevented && editor.state.selection.empty) {
                event.preventDefault();
                setOpen(true);
            }
        };

        dom.addEventListener(EDIT_LINK, asked);

        return () => dom.removeEventListener(EDIT_LINK, asked);
    }, [editor]);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-auto p-0"
                onOpenAutoFocus={(event) => event.preventDefault()}
                onCloseAutoFocus={(event) => event.preventDefault()}
            >
                <LinkPanel
                    editor={editor}
                    siteUrl={siteUrl}
                    onDone={() => setOpen(false)}
                />
            </PopoverContent>
        </Popover>
    );
}
