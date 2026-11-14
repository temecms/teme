import type { Editor } from '@tiptap/core';
import { useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { typedAddress } from './link-address';
import { isInternalLink } from './schema';
import { useLinkChoices } from './use-link-choices';
import { shownTitle } from '@/lib/titles';

// Typing in a menu must not move the menu's own selection.
const keepTyping = (event: KeyboardEvent) => event.stopPropagation();

/**
 * Adds or changes a link on the selection. It offers both kinds: something
 * on this site found by its title or name (an article, a category or tag,
 * an author or a file), stored by its ID and never as a URL (rule 9), or an
 * address typed in.
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
    const [query, setQuery] = useState('');
    const [address, setAddress] = useState('');
    const [error, setError] = useState<string | null>(null);
    const groups = useLinkChoices(query, open);
    const current =
        (editor.getAttributes('link').href as string | undefined) ?? '';

    const apply = (href: string) => {
        editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
        setOpen(false);
    };

    const remove = () => {
        editor.chain().focus().extendMarkRange('link').unsetLink().run();
        setOpen(false);
    };

    return (
        <DropdownMenu
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setQuery('');
                    setError(null);
                    setAddress(isInternalLink(current) ? '' : current);
                }
            }}
        >
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="w-80 max-w-(--radix-dropdown-menu-content-available-width) p-3"
            >
                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="link-search">
                            Link to something on this site
                        </Label>
                        <Input
                            id="link-search"
                            aria-describedby="link-search-hint"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={keepTyping}
                            placeholder="Search by title or name"
                        />
                        <p id="link-search-hint" className="text-ink-2 text-xs">
                            A post, page or other item, a category or tag, an
                            author or a file. The link keeps working when its
                            address changes.
                        </p>
                        <div className="max-h-56 overflow-y-auto">
                            {groups.map((group, index) => (
                                <section
                                    key={group.key}
                                    aria-labelledby={`link-group-${index}`}
                                >
                                    <h3
                                        id={`link-group-${index}`}
                                        className="text-ink-2 px-2 pt-2 pb-1 text-[12px] font-semibold"
                                    >
                                        {group.label}
                                    </h3>
                                    <ul>
                                        {group.choices.map((choice) => (
                                            <li key={choice.href}>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        apply(choice.href)
                                                    }
                                                    className="hover:bg-surface-3 focus-visible:ring-ring w-full rounded-sm px-2 py-1.5 text-left text-[13px] outline-none focus-visible:ring-2"
                                                >
                                                    {shownTitle(choice.title)}
                                                    {choice.note !== null && (
                                                        <span className="text-ink-2">
                                                            {' '}
                                                            · {choice.note}
                                                        </span>
                                                    )}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    </div>
                    <form
                        className="grid gap-1.5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const typed = typedAddress(address, siteUrl);

                            if ('error' in typed) {
                                setError(typed.error);

                                return;
                            }

                            apply(typed.href);
                        }}
                    >
                        <Label htmlFor="link-address">Or an address</Label>
                        <div className="flex gap-2">
                            <Input
                                id="link-address"
                                aria-invalid={error !== null}
                                aria-describedby={
                                    error === null
                                        ? 'link-address-hint'
                                        : 'link-address-hint link-address-error'
                                }
                                value={address}
                                onChange={(event) => {
                                    setAddress(event.target.value);
                                    setError(null);
                                }}
                                onKeyDown={keepTyping}
                                placeholder="https://"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                className="h-[34px]"
                            >
                                Apply
                            </Button>
                        </div>
                        <p
                            id="link-address-hint"
                            className="text-ink-2 text-xs"
                        >
                            An address on another site, mailto: or tel:, a page
                            on this site (/…) or a place on this page (#…).
                        </p>
                        <InputError
                            id="link-address-error"
                            role="alert"
                            className="text-xs"
                            message={error ?? undefined}
                        />
                    </form>
                    {current !== '' && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={remove}
                        >
                            Remove link
                        </Button>
                    )}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
