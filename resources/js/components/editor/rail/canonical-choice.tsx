import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { useLinkTargets } from '@/components/editor/use-link-targets';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
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
            className="text-small flex min-h-6 items-center gap-2"
        >
            <input
                id={id}
                type="radio"
                name="editor-canonical"
                checked={checked}
                onChange={onSelect}
                className="accent-accent size-4"
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
            <legend className="text-small mb-1 leading-none font-medium">
                Canonical address
            </legend>
            <p
                id="editor-canonical-hint"
                className="text-ink-2 text-caption mb-1"
            >
                Where the original of this {noun} lives, if it was first
                published elsewhere. Search engines are then asked to list the
                original instead.
                {!listed &&
                    ` ${__('While it’s hidden from search engines, it has no canonical link.')}`}
            </p>
            <Choice
                id="editor-canonical-self"
                checked={kind === 'self'}
                onSelect={() => choose('self')}
            >
                {__('This :noun’s own address', { noun })}
            </Choice>
            <Choice
                id="editor-canonical-item"
                checked={kind === 'item'}
                onSelect={() => choose('item')}
            >
                {__('Another item on this site')}
            </Choice>
            {kind === 'item' && (
                <div className="grid gap-2 pb-1 pl-6">
                    <Label
                        htmlFor="editor-canonical-search"
                        className="sr-only"
                    >
                        {__('Find the item that is the original')}
                    </Label>
                    <Input
                        id="editor-canonical-search"
                        placeholder={__('Search by title')}
                        autoComplete="off"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    {query.trim() !== '' && targets.length === 0 && (
                        <p className="text-ink-2 text-caption">
                            {__('Nothing matches “:query”.', {
                                query: query.trim(),
                            })}
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
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="xs"
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
                                            className="text-ink h-auto min-h-6 w-full items-baseline justify-start rounded-sm text-left font-normal whitespace-normal"
                                        >
                                            <span>
                                                {shownTitle(target.title)}
                                            </span>
                                            <span className="text-ink-2 text-caption">
                                                {target.status === 'published'
                                                    ? singular
                                                    : __(
                                                          ':type, not published',
                                                          {
                                                              type: singular,
                                                          },
                                                      )}
                                            </span>
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {chosen !== null && namesItem && (
                        <>
                            <p
                                data-test="canonical-chosen"
                                className="text-small"
                            >
                                {__('Chosen: :title', {
                                    title: quotedTitle(chosen.title),
                                })}
                            </p>
                            <p className="text-ink-2 text-caption">
                                {__(
                                    'The link follows it if its address changes.',
                                )}
                            </p>
                            {chosen.state === 'unpublished' && (
                                <p
                                    data-test="canonical-note"
                                    className="text-ink-2 text-caption"
                                >
                                    {__(
                                        '“:title” isn’t published, so search engines are sent to this :noun until it is.',
                                        {
                                            title: quotedTitle(chosen.title),
                                            noun,
                                        },
                                    )}
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
                {__('An address on another site')}
            </Choice>
            {kind === 'address' && (
                <div className="grid gap-2 pb-1 pl-6">
                    <Label htmlFor="editor-canonical-url" className="sr-only">
                        {__('The address of the original')}
                    </Label>
                    <Input
                        id="editor-canonical-url"
                        type="url"
                        placeholder={__('https://example.com/original-story')}
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
                        className="text-danger-ink text-caption"
                    >
                        {text.trim() !== '' ? problem : null}
                    </p>
                </div>
            )}
            {gone && (
                <p
                    data-test="canonical-note"
                    className="text-ink-2 text-caption"
                >
                    {__(
                        'The item chosen before is in the trash or was deleted, so this :noun’s own address is back in use. Choose another if you need one.',
                        { noun },
                    )}
                </p>
            )}
        </fieldset>
    );
}
