import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { useLinkTargets } from '@/components/editor/use-link-targets';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { canonicalAddressProblem } from '@/lib/search-preview';
import type { EditorSeo, LinkedContent } from '@/types/editor';
import { shownTitle, quotedTitle } from '@/lib/titles';

/** The item the canonical names, as the section holds it. */
export type ChosenItem = {
    id: string;
    title: string | null;
    state: LinkedContent['state'];
};

type Kind = 'self' | 'item' | 'address';

/** The kind of canonical a stored value is. */
function kindOf(value: unknown): Kind {
    if (value !== null && typeof value === 'object') {
        if ('item' in value) {
            return 'item';
        }

        if ('address' in value) {
            return 'address';
        }
    }

    return typeof value === 'string' && value.trim() !== ''
        ? 'address'
        : 'self';
}

/** The stored outside address, or ''. */
function addressOf(value: unknown): string {
    if (typeof value === 'string') {
        return value;
    }

    const address = (value as { address?: unknown } | null)?.address;

    return typeof address === 'string' ? address : '';
}

function Choice({
    id,
    checked,
    onSelect,
    children,
}: {
    id: string;
    checked: boolean;
    onSelect: () => void;
    children: ReactNode;
}) {
    return (
        <label
            htmlFor={id}
            className="flex min-h-6 items-center gap-2 text-[13px]"
        >
            <input
                id={id}
                type="radio"
                name="editor-canonical"
                checked={checked}
                onChange={onSelect}
                className="accent-primary size-4"
            />
            {children}
        </label>
    );
}

/**
 * Where search engines should send readers for this item (I-292): its own
 * address, another item found by its title, or an address on another site,
 * checked as the server checks it and sent only when it passes (rule 9).
 */
export function CanonicalChoice({
    value,
    onChange,
    chosen,
    onChoose,
    seo,
    listed,
    noun,
    contentId,
}: {
    value: unknown;
    onChange: (value: { item: string } | { address: string } | null) => void;
    chosen: ChosenItem | null;
    onChoose: (item: ChosenItem) => void;
    seo: EditorSeo;
    listed: boolean;
    noun: string;
    contentId: string;
}) {
    const { contentTypes } = usePage().props;
    const [kind, setKind] = useState<Kind>(() => kindOf(value));
    const [text, setText] = useState(() => addressOf(value));
    const [query, setQuery] = useState('');
    const problem = canonicalAddressProblem(text, seo.siteHost);
    const targets = useLinkTargets(
        query,
        kind === 'item' && query.trim() !== '',
    )
        .filter((target) => target.id !== contentId)
        .slice(0, 8);
    const namesItem = kindOf(value) === 'item';
    const gone =
        !namesItem &&
        seo.canonicalItem !== null &&
        (seo.canonicalItem.state === 'trashed' ||
            seo.canonicalItem.state === 'missing');

    const sendAddress = (next: string) =>
        onChange(
            canonicalAddressProblem(next, seo.siteHost) === null
                ? { address: next.trim() }
                : null,
        );

    const choose = (next: Kind) => {
        setKind(next);

        if (next === 'self') {
            onChange(null);
        } else if (next === 'item') {
            onChange(chosen === null ? null : { item: chosen.id });
        } else {
            sendAddress(text);
        }
    };

    return (
        <fieldset
            aria-describedby="editor-canonical-hint"
            className="grid gap-1"
        >
            <legend className="mb-1 text-sm leading-none font-medium">
                Canonical address
            </legend>
            <p
                id="editor-canonical-hint"
                className="text-muted-foreground mb-1 text-xs"
            >
                Where the original of this {noun} lives, if it was first
                published elsewhere. Search engines are then asked to list the
                original instead.
                {!listed &&
                    ' While it’s hidden from search engines, it has no canonical link.'}
            </p>
            <Choice
                id="editor-canonical-self"
                checked={kind === 'self'}
                onSelect={() => choose('self')}
            >
                This {noun}’s own address
            </Choice>
            <Choice
                id="editor-canonical-item"
                checked={kind === 'item'}
                onSelect={() => choose('item')}
            >
                Another item on this site
            </Choice>
            {kind === 'item' && (
                <div className="grid gap-1.5 pb-1 pl-6">
                    <Label
                        htmlFor="editor-canonical-search"
                        className="sr-only"
                    >
                        Find the item that is the original
                    </Label>
                    <Input
                        id="editor-canonical-search"
                        placeholder="Search by title"
                        autoComplete="off"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    {query.trim() !== '' && targets.length === 0 && (
                        <p className="text-muted-foreground text-xs">
                            Nothing matches “{query.trim()}”.
                        </p>
                    )}
                    {targets.length > 0 && query.trim() !== '' && (
                        <ul className="grid gap-0.5">
                            {targets.map((target) => {
                                const singular =
                                    contentTypes.find(
                                        (type) => type.key === target.type,
                                    )?.singular ?? target.type;

                                return (
                                    <li key={target.id}>
                                        <button
                                            type="button"
                                            data-test="canonical-option"
                                            onClick={() => {
                                                onChoose({
                                                    id: target.id,
                                                    title: target.title,
                                                    state:
                                                        target.status ===
                                                        'published'
                                                            ? 'published'
                                                            : 'unpublished',
                                                });
                                                onChange({ item: target.id });
                                                setQuery('');
                                            }}
                                            className="hover:bg-accent focus-visible:ring-ring flex min-h-6 w-full items-baseline gap-2 rounded-sm px-1.5 text-left text-[13px] outline-none focus-visible:ring-2"
                                        >
                                            <span>
                                                {shownTitle(target.title)}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {target.status === 'published'
                                                    ? singular
                                                    : `${singular}, not published`}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {chosen !== null && namesItem && (
                        <>
                            <p
                                data-test="canonical-chosen"
                                className="text-[13px]"
                            >
                                Chosen: {quotedTitle(chosen.title)}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                The link follows it if its address changes.
                            </p>
                            {chosen.state === 'unpublished' && (
                                <p
                                    data-test="canonical-note"
                                    className="text-muted-foreground text-xs"
                                >
                                    “{quotedTitle(chosen.title)}” isn’t
                                    published, so search engines are sent to
                                    this {noun} until it is.
                                </p>
                            )}
                        </>
                    )}
                </div>
            )}
            <Choice
                id="editor-canonical-address"
                checked={kind === 'address'}
                onSelect={() => choose('address')}
            >
                An address on another site
            </Choice>
            {kind === 'address' && (
                <div className="grid gap-1.5 pb-1 pl-6">
                    <Label htmlFor="editor-canonical-url" className="sr-only">
                        The address of the original
                    </Label>
                    <Input
                        id="editor-canonical-url"
                        type="url"
                        placeholder="https://example.com/original-story"
                        spellCheck={false}
                        autoComplete="off"
                        aria-describedby="editor-canonical-url-error"
                        value={text}
                        onChange={(event) => {
                            setText(event.target.value);
                            sendAddress(event.target.value);
                        }}
                    />
                    <p
                        id="editor-canonical-url-error"
                        role="alert"
                        className="text-destructive text-xs"
                    >
                        {text.trim() !== '' ? problem : null}
                    </p>
                </div>
            )}
            {gone && (
                <p
                    data-test="canonical-note"
                    className="text-muted-foreground text-xs"
                >
                    The item chosen before is in the trash or was deleted, so
                    this {noun}’s own address is back in use. Choose another if
                    you need one.
                </p>
            )}
        </fieldset>
    );
}
