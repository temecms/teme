import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import RedirectController from '@/actions/App/Http/Admin/Tools/RedirectController';
import { useLinkTargets } from '@/components/editor/use-link-targets';
import InputError from '@/components/input-error';
import { MediaModal } from '@/components/media/media-modal';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupChoice } from '@/components/ui/radio-group';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SelectField } from '@/components/ui/select-field';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { __ } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import type { RedirectRow } from '@/types';

/** What the drawer is open for: a redirect to edit, or a new one from an address, perhaps empty. */
export type RedirectEditing = { redirect: RedirectRow | null; from: string };

type Kind = 'item' | 'file' | 'address';

/** The statuses a redirect can answer with, named as they are drawn. */
function statuses(): { value: number; label: string }[] {
    return [
        [301, __('Moved permanently')],
        [302, __('Found (temporary)')],
        [307, __('Temporary redirect')],
        [308, __('Permanent redirect')],
        [410, __('Gone')],
        [451, __('Unavailable for legal reasons')],
    ].map(([code, name]) => ({
        value: code as number,
        label: `${code} ${name}`,
    }));
}

/** The three kinds of target, named as they are drawn. */
function kinds(): [Kind, string][] {
    return [
        ['item', __('A post or page on this site')],
        ['file', __('A file in the media library')],
        ['address', __('An address')],
    ];
}

const hintClass = 'text-caption text-ink-3';

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
        <ScrollArea className="px-6 pb-4">
            <Form
                {...form}
                options={{ preserveScroll: true }}
                onSuccess={onClose}
                className="grid gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError
                            id="redirect-error"
                            message={errors.redirect}
                        />

                        <Field
                            htmlFor="redirect-from"
                            label={__('From')}
                            help={__(
                                'An address on this site that no page answers. [name] stands for one part of an address and [...rest] for everything after it, as in /2019/05/[name], and To can use them again.',
                            )}
                            error={errors.source}
                        >
                            <Input
                                id="redirect-from"
                                name="from"
                                defaultValue={redirect?.from ?? editing.from}
                                placeholder={__('/old-address')}
                                spellCheck={false}
                                autoComplete="off"
                                className="font-mono"
                                autoFocus={
                                    editing.from === '' && redirect === null
                                }
                            />
                        </Field>

                        <Field
                            htmlFor="redirect-status"
                            label={__('Status')}
                            help={__(
                                '301 and 308 are for moves that last, 302 and 307 for moves that don’t. 410 and 451 send readers nowhere: 410 says the page is gone for good, and 451 that it’s withheld for legal reasons.',
                            )}
                            error={errors.status}
                        >
                            <SelectField
                                id="redirect-status"
                                name="status"
                                value={String(status)}
                                onValueChange={(next) =>
                                    setStatus(Number(next))
                                }
                                options={statuses()}
                            />
                        </Field>

                        <fieldset hidden={status >= 400} className="grid gap-2">
                            <legend className="text-body mb-2 font-medium">
                                {__('To')}
                            </legend>
                            <RadioGroup
                                name="kind"
                                value={kind}
                                onValueChange={(next) => setKind(next as Kind)}
                                className="flex flex-wrap gap-x-4 gap-y-2"
                            >
                                {kinds().map(([value, words]) => (
                                    <RadioGroupChoice
                                        key={value}
                                        id={`redirect-kind-${value}`}
                                        value={value}
                                        label={words}
                                    />
                                ))}
                            </RadioGroup>

                            {kind === 'item' && (
                                <div className="grid gap-2">
                                    <input
                                        type="hidden"
                                        name="item"
                                        value={item?.id ?? ''}
                                    />
                                    {item !== null && (
                                        <p
                                            className="text-small"
                                            data-test="redirect-item-chosen"
                                        >
                                            {__('Chosen: :title', {
                                                title: shownTitle(item.title),
                                            })}
                                        </p>
                                    )}
                                    <Input
                                        id="redirect-item-search"
                                        aria-label={__('Find the post or page')}
                                        placeholder={__('Search by title')}
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        autoComplete="off"
                                        autoFocus={
                                            editing.from !== '' &&
                                            redirect === null
                                        }
                                        aria-describedby="redirect-item-hint redirect-item-error"
                                    />
                                    {query.trim() !== '' &&
                                        (found.length > 0 ? (
                                            <ul className="border-line grid rounded-md border p-1">
                                                {found.map((target) => (
                                                    <li key={target.id}>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            data-test="redirect-item-option"
                                                            className="h-auto min-h-8 w-full justify-start py-1 text-start font-normal whitespace-normal"
                                                            onClick={() => {
                                                                setItem({
                                                                    id: target.id,
                                                                    title: target.title,
                                                                });
                                                                setQuery('');
                                                            }}
                                                        >
                                                            <span>
                                                                {shownTitle(
                                                                    target.title,
                                                                )}{' '}
                                                                <span className="text-ink-2">
                                                                    {target.status !==
                                                                    'published'
                                                                        ? __(
                                                                              ':type, not published',
                                                                              {
                                                                                  type: singular(
                                                                                      target.type,
                                                                                  ),
                                                                              },
                                                                          )
                                                                        : singular(
                                                                              target.type,
                                                                          )}
                                                                </span>
                                                            </span>
                                                        </Button>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <p className="text-ink-2 text-small">
                                                {__(
                                                    'Nothing matches “:query”.',
                                                    {
                                                        query,
                                                    },
                                                )}
                                            </p>
                                        ))}
                                    <p
                                        id="redirect-item-hint"
                                        className={hintClass}
                                    >
                                        {__(
                                            'Linked to the post or page itself, so the redirect follows it if its address changes.',
                                        )}
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
                                        <p className="text-small">
                                            {__('Chosen: :title', {
                                                title: file.name,
                                            })}
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
                                            {__('Choose a file')}
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
                                        action={__('Choose file')}
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
                                        {__(
                                            'Linked to the file itself, so the redirect keeps working if the site moves its files.',
                                        )}
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
                                        aria-label={__('The address')}
                                        placeholder={__('/news/new-address')}
                                        defaultValue={typed}
                                        spellCheck={false}
                                        autoComplete="off"
                                        className="font-mono"
                                        aria-describedby="redirect-to-hint redirect-to-error"
                                    />
                                    <p
                                        id="redirect-to-hint"
                                        className={hintClass}
                                    >
                                        {__(
                                            'A path on this site, starting /, or a full address on another site, starting https://.',
                                        )}
                                    </p>
                                    <InputError
                                        id="redirect-to-error"
                                        message={errors.to}
                                    />
                                </div>
                            )}
                        </fieldset>

                        <Field
                            htmlFor="redirect-note"
                            label={__('Note')}
                            help={__('Why it’s here, for whoever looks next.')}
                            error={errors.note}
                        >
                            <Input
                                id="redirect-note"
                                name="note"
                                maxLength={200}
                                defaultValue={redirect?.note ?? ''}
                                autoComplete="off"
                            />
                        </Field>

                        <div className="flex items-center gap-2">
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="redirect-save"
                                aria-describedby="redirect-error"
                            >
                                {redirect === null
                                    ? __('Add redirect')
                                    : __('Save redirect')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onClose}
                            >
                                {__('Cancel')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </ScrollArea>
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
            <SheetContent side="right">
                <SheetHeader>
                    <SheetTitle>
                        {editing?.redirect
                            ? __('Edit the redirect from :from', {
                                  from: editing.redirect.from,
                              })
                            : __('Add a redirect')}
                    </SheetTitle>
                    <SheetDescription>
                        {__(
                            'An address on this site, and where readers who ask for it should go.',
                        )}
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
