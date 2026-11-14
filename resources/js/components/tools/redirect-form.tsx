import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import RedirectController from '@/actions/App/Http/Admin/Tools/RedirectController';
import { useLinkTargets } from '@/components/editor/use-link-targets';
import InputError from '@/components/input-error';
import { MediaModal } from '@/components/media/media-modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { RedirectRow } from '@/types';
import { shownTitle } from '@/lib/titles';

/** What the drawer is open for: a redirect to edit, or a new one from an address, perhaps empty. */
export type RedirectEditing = { redirect: RedirectRow | null; from: string };

type Kind = 'item' | 'file' | 'address';

const STATUSES: [number, string][] = [
    [301, 'Moved permanently'],
    [302, 'Found (temporary)'],
    [307, 'Temporary redirect'],
    [308, 'Permanent redirect'],
    [410, 'Gone'],
    [451, 'Unavailable for legal reasons'],
];

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const hintClass = 'text-muted-foreground text-xs';

/** The kind of target a redirect has, as the form's three choices name it. */
function kindOf(redirect: RedirectRow | null): Kind {
    switch (redirect?.to.kind) {
        case 'media':
            return 'file';
        case 'address':
        case 'outside':
            return 'address';
        default:
            return 'item';
    }
}

function Fields({
    editing,
    onClose,
}: {
    editing: RedirectEditing;
    onClose: () => void;
}) {
    const { contentTypes } = usePage().props;
    const redirect = editing.redirect;
    const [status, setStatus] = useState(redirect?.status ?? 301);
    const [kind, setKind] = useState<Kind>(kindOf(redirect));
    const [item, setItem] = useState<{ id: string; title: string } | null>(
        redirect?.to.contentId && redirect.to.title !== null
            ? { id: redirect.to.contentId, title: redirect.to.title }
            : null,
    );
    const [file, setFile] = useState<{ id: string; name: string } | null>(
        redirect?.to.mediaId && redirect.to.title !== null
            ? { id: redirect.to.mediaId, name: redirect.to.title }
            : null,
    );
    const [query, setQuery] = useState('');
    const [choosing, setChoosing] = useState(false);
    const found = useLinkTargets(query, query.trim() !== '').slice(0, 8);
    const singular = (type: string) =>
        contentTypes.find((known) => known.key === type)?.singular ?? type;
    const typed =
        redirect?.to.kind === 'address' || redirect?.to.kind === 'outside'
            ? (redirect.to.address ?? '')
            : '';
    const form =
        redirect === null
            ? RedirectController.store.form()
            : RedirectController.update.form({ redirect: redirect.id });

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="grid gap-4 overflow-y-auto px-4 pb-4"
        >
            {({ processing, errors }) => (
                <>
                    <InputError id="redirect-error" message={errors.redirect} />

                    <div className="grid gap-2">
                        <Label htmlFor="redirect-from">From</Label>
                        <Input
                            id="redirect-from"
                            name="from"
                            defaultValue={redirect?.from ?? editing.from}
                            placeholder="/old-address"
                            spellCheck={false}
                            autoComplete="off"
                            className="font-mono"
                            aria-describedby="redirect-from-hint redirect-from-error"
                            autoFocus={editing.from === '' && redirect === null}
                        />
                        <p id="redirect-from-hint" className={hintClass}>
                            An address on this site that no page answers. [name]
                            stands for one part of an address and [...rest] for
                            everything after it, as in /2019/05/[name], and To
                            can use them again.
                        </p>
                        <InputError
                            id="redirect-from-error"
                            message={errors.source}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="redirect-status">Status</Label>
                        <select
                            id="redirect-status"
                            name="status"
                            value={status}
                            onChange={(event) =>
                                setStatus(Number(event.target.value))
                            }
                            aria-describedby="redirect-status-hint redirect-status-error"
                            className={controlClass}
                        >
                            {STATUSES.map(([code, name]) => (
                                <option key={code} value={code}>
                                    {`${code} ${name}`}
                                </option>
                            ))}
                        </select>
                        <p id="redirect-status-hint" className={hintClass}>
                            301 and 308 are for moves that last, 302 and 307 for
                            moves that don’t. 410 and 451 send readers nowhere:
                            410 says the page is gone for good, and 451 that
                            it’s withheld for legal reasons.
                        </p>
                        <InputError
                            id="redirect-status-error"
                            message={errors.status}
                        />
                    </div>

                    <fieldset hidden={status >= 400} className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">To</legend>
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            {(
                                [
                                    ['item', 'A post or page on this site'],
                                    ['file', 'A file in the media library'],
                                    ['address', 'An address'],
                                ] as [Kind, string][]
                            ).map(([value, words]) => (
                                <label
                                    key={value}
                                    className="flex min-h-6 items-center gap-2"
                                >
                                    <input
                                        type="radio"
                                        id={`redirect-kind-${value}`}
                                        name="kind"
                                        value={value}
                                        checked={kind === value}
                                        onChange={() => setKind(value)}
                                    />
                                    {words}
                                </label>
                            ))}
                        </div>

                        {kind === 'item' && (
                            <div className="grid gap-2">
                                <input
                                    type="hidden"
                                    name="item"
                                    value={item?.id ?? ''}
                                />
                                {item !== null && (
                                    <p
                                        className="text-[13px]"
                                        data-test="redirect-item-chosen"
                                    >
                                        Chosen: {shownTitle(item.title)}
                                    </p>
                                )}
                                <Input
                                    id="redirect-item-search"
                                    aria-label="Find the post or page"
                                    placeholder="Search by title"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    autoComplete="off"
                                    autoFocus={
                                        editing.from !== '' && redirect === null
                                    }
                                    aria-describedby="redirect-item-hint redirect-item-error"
                                />
                                {query.trim() !== '' &&
                                    (found.length > 0 ? (
                                        <ul className="border-border rounded border">
                                            {found.map((target) => (
                                                <li key={target.id}>
                                                    <button
                                                        type="button"
                                                        data-test="redirect-item-option"
                                                        className="hover:bg-muted min-h-6 w-full px-3 py-1.5 text-left text-[13px]"
                                                        onClick={() => {
                                                            setItem({
                                                                id: target.id,
                                                                title: target.title,
                                                            });
                                                            setQuery('');
                                                        }}
                                                    >
                                                        {shownTitle(
                                                            target.title,
                                                        )}{' '}
                                                        <span className="text-muted-foreground">
                                                            {singular(
                                                                target.type,
                                                            )}
                                                            {target.status !==
                                                                'published' &&
                                                                ', not published'}
                                                        </span>
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-muted-foreground text-[13px]">
                                            Nothing matches “{query}”.
                                        </p>
                                    ))}
                                <p
                                    id="redirect-item-hint"
                                    className={hintClass}
                                >
                                    Linked to the post or page itself, so the
                                    redirect follows it if its address changes.
                                </p>
                                <InputError
                                    id="redirect-item-error"
                                    message={errors.item}
                                />
                            </div>
                        )}

                        {kind === 'file' && (
                            <div className="grid gap-2">
                                <input
                                    type="hidden"
                                    name="file"
                                    value={file?.id ?? ''}
                                />
                                {file !== null && (
                                    <p className="text-[13px]">
                                        Chosen: {file.name}
                                    </p>
                                )}
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        data-test="redirect-file-choose"
                                        aria-describedby="redirect-file-hint redirect-file-error"
                                        onClick={() => setChoosing(true)}
                                    >
                                        Choose a file
                                    </Button>
                                </div>
                                <MediaModal
                                    open={choosing}
                                    onOpenChange={setChoosing}
                                    kinds={[
                                        'image',
                                        'document',
                                        'video',
                                        'audio',
                                    ]}
                                    action="Choose file"
                                    onChoose={(ids, rows) => {
                                        const [row] = rows;

                                        if (row) {
                                            setFile({
                                                id: row.id,
                                                name: row.filename,
                                            });
                                        }
                                    }}
                                />
                                <p
                                    id="redirect-file-hint"
                                    className={hintClass}
                                >
                                    Linked to the file itself, so the redirect
                                    keeps working if the site moves its files.
                                </p>
                                <InputError
                                    id="redirect-file-error"
                                    message={errors.file}
                                />
                            </div>
                        )}

                        {kind === 'address' && (
                            <div className="grid gap-2">
                                <Input
                                    id="redirect-to"
                                    name="to"
                                    aria-label="The address"
                                    placeholder="/news/new-address"
                                    defaultValue={typed}
                                    spellCheck={false}
                                    autoComplete="off"
                                    className="font-mono"
                                    aria-describedby="redirect-to-hint redirect-to-error"
                                />
                                <p id="redirect-to-hint" className={hintClass}>
                                    A path on this site, starting /, or a full
                                    address on another site, starting https://.
                                </p>
                                <InputError
                                    id="redirect-to-error"
                                    message={errors.to}
                                />
                            </div>
                        )}
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor="redirect-note">Note</Label>
                        <Input
                            id="redirect-note"
                            name="note"
                            maxLength={200}
                            defaultValue={redirect?.note ?? ''}
                            autoComplete="off"
                            aria-describedby="redirect-note-hint redirect-note-error"
                        />
                        <p id="redirect-note-hint" className={hintClass}>
                            Why it’s here, for whoever looks next.
                        </p>
                        <InputError
                            id="redirect-note-error"
                            message={errors.note}
                        />
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="redirect-save"
                            aria-describedby="redirect-error"
                        >
                            {redirect === null
                                ? 'Add redirect'
                                : 'Save redirect'}
                        </Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * The redirect form, in a drawer (I-872): to add one, to edit one, or to make
 * one from an address the 404 log or the trash's toast gives (I-856, I-871).
 * To is a post or page found by its title, a file in the media library, or an
 * address (I-860).
 */
export function RedirectForm({
    editing,
    onClose,
}: {
    editing: RedirectEditing | null;
    onClose: () => void;
}) {
    return (
        <Sheet
            open={editing !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent side="right" className="w-full sm:max-w-[560px]">
                <SheetHeader>
                    <SheetTitle>
                        {editing?.redirect
                            ? `Edit the redirect from ${editing.redirect.from}`
                            : 'Add a redirect'}
                    </SheetTitle>
                    <SheetDescription>
                        An address on this site, and where readers who ask for
                        it should go.
                    </SheetDescription>
                </SheetHeader>
                {editing !== null && (
                    <Fields
                        key={editing.redirect?.id ?? `new:${editing.from}`}
                        editing={editing}
                        onClose={onClose}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}
