import { useHttp } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useId, useState } from 'react';
import { inTreeOrder } from '@/lib/term-tree';
import BulkContentController from '@/actions/App/Http/Admin/Contents/BulkContentController';
import { TermPicker } from '@/components/term-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { DISCUSSION_CHOICES } from '@/lib/discussion';
import type { ContentIndexPage, ContentRow, TermChip } from '@/types';
import { shownTitle } from '@/lib/titles';

/**
 * Items per request. Each costs about one quick-edit save (150 ms, I-148),
 * so a batch stays within a few seconds; the server takes up to 100.
 */
const BULK_EDIT_BATCH = 25;

/** Every selected item has the term, none has it, or some have. */
type TriState = 'on' | 'off' | 'mixed';

type Answer = {
    done: number;
    changed: { id: string; title: string; summary: string }[];
    skipped: { id: string; title: string; reason: string }[];
};

/** One batch as the server takes it; a null author leaves each one's. */
type Batch = {
    action: 'edit';
    ids: string[];
    titles: Record<string, string>;
    add_terms: string[];
    remove_terms: string[];
    status: string;
    discussion: string;
    author_id: number | null;
};

const NO_BATCH: Batch = {
    action: 'edit',
    ids: [],
    titles: {},
    add_terms: [],
    remove_terms: [],
    status: 'keep',
    discussion: 'keep',
    author_id: null,
};

/** One report for the whole edit, added up from its batches. */
export type BulkEditReport = Answer & {
    /** Items never sent, because a request before them failed. */
    notSent: { id: string; title: string }[];
};

/**
 * Bulk Edit (I-204): one control per taxonomy of the list's type, the author,
 * the status and the Discussion, applied to every selected item, each through
 * its own Operations on the server. It sends the selection in batches, one after
 * another, and reports what changed and what was skipped item by item.
 */
export function BulkEditDialog({
    page,
    rows,
    onClose,
    onFinished,
}: {
    page: ContentIndexPage;
    rows: ContentRow[];
    onClose: () => void;
    onFinished: (report: BulkEditReport) => void;
}) {
    const id = useId();
    const http = useHttp<Batch, Answer>(NO_BATCH);
    const [items, setItems] = useState(rows);
    const hierarchical = page.typeTaxonomies.filter(
        (taxonomy) => taxonomy.hierarchical,
    );
    const flat = page.typeTaxonomies.filter(
        (taxonomy) => !taxonomy.hierarchical,
    );
    // What the selection has now, per term: the state an untouched box keeps.
    const initialOf = (taxonomy: string, termId: string): TriState => {
        const having = rows.filter((row) =>
            (row.terms[taxonomy] ?? []).some((term) => term.id === termId),
        ).length;

        return having === 0 ? 'off' : having === rows.length ? 'on' : 'mixed';
    };
    const [changes, setChanges] = useState<Record<string, TriState>>({});
    const [added, setAdded] = useState<Record<string, TermChip[]>>({});
    const [author, setAuthor] = useState('');
    const [status, setStatus] = useState('');
    const [discussion, setDiscussion] = useState('');
    const [sent, setSent] = useState<number | null>(null);

    const stateOf = (taxonomy: string, termId: string): TriState =>
        changes[`${taxonomy}:${termId}`] ?? initialOf(taxonomy, termId);

    // Checked adds the term to all, unchecked removes it from all, and a
    // box that started mixed may go back to leaving each item as it was.
    const cycle = (taxonomy: string, termId: string) => {
        const order: TriState[] =
            initialOf(taxonomy, termId) === 'mixed'
                ? ['mixed', 'on', 'off']
                : ['off', 'on'];
        const next =
            order[
                (order.indexOf(stateOf(taxonomy, termId)) + 1) % order.length
            ];
        setChanges((current) => ({
            ...current,
            [`${taxonomy}:${termId}`]: next,
        }));
    };

    const apply = async () => {
        const addTerms = [
            ...Object.entries(changes)
                .filter(
                    ([key, state]) =>
                        state === 'on' &&
                        initialOf(key.split(':')[0], key.split(':')[1]) !==
                            'on',
                )
                .map(([key]) => key.split(':')[1]),
            ...Object.values(added)
                .flat()
                .map((term) => term.id),
        ];
        const removeTerms = Object.entries(changes)
            .filter(
                ([key, state]) =>
                    state === 'off' &&
                    initialOf(key.split(':')[0], key.split(':')[1]) !== 'off',
            )
            .map(([key]) => key.split(':')[1]);
        const report: BulkEditReport = {
            done: 0,
            changed: [],
            skipped: [],
            notSent: [],
        };

        setSent(0);

        for (let start = 0; start < items.length; start += BULK_EDIT_BATCH) {
            const batch = items.slice(start, start + BULK_EDIT_BATCH);
            http.transform(() => ({
                action: 'edit',
                ids: batch.map((item) => item.id),
                titles: Object.fromEntries(
                    batch.map((item) => [item.id, item.title]),
                ),
                add_terms: addTerms,
                remove_terms: removeTerms,
                status: status === '' ? 'keep' : status,
                discussion: discussion === '' ? 'keep' : discussion,
                author_id: author === '' ? null : Number(author),
            }));

            try {
                const answer = await http.post(
                    BulkContentController.url({ type: page.type }),
                );

                if (typeof answer?.done !== 'number') {
                    throw new Error('Not a Bulk Edit report.');
                }

                report.done += answer.done;
                report.changed.push(...answer.changed);
                report.skipped.push(...answer.skipped);
                setSent(start + batch.length);
            } catch {
                // Stop here: what was sent is reported, and what wasn't is named.
                report.notSent = items
                    .slice(start)
                    .map((item) => ({ id: item.id, title: item.title }));
                break;
            }
        }

        onFinished(report);
    };

    const working = sent !== null;

    return (
        <Dialog open onOpenChange={(open) => !open && !working && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Edit {items.length}{' '}
                        {items.length === 1
                            ? page.labels.singular.toLowerCase()
                            : page.labels.plural.toLowerCase()}
                    </DialogTitle>
                    <DialogDescription>
                        Each change applies to every item listed. Anything left
                        alone stays as it is on each item.
                    </DialogDescription>
                </DialogHeader>

                <ul aria-label="Items to edit" className="flex flex-wrap gap-1">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="border-border bg-muted inline-flex items-center gap-1 rounded-full border py-0.5 pr-1 pl-3 text-[13px]"
                        >
                            {shownTitle(item.title)}
                            <button
                                type="button"
                                disabled={working || items.length === 1}
                                onClick={() =>
                                    setItems((current) =>
                                        current.filter(
                                            (kept) => kept.id !== item.id,
                                        ),
                                    )
                                }
                                aria-label={`Leave “${shownTitle(item.title)}” out of this edit`}
                                className="hover:bg-accent rounded-full p-0.5 disabled:opacity-50"
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>

                <div className="grid gap-4 sm:grid-cols-2">
                    {hierarchical.map((taxonomy) => (
                        <fieldset key={taxonomy.key} className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                {taxonomy.plural}
                            </legend>
                            <ul className="border-border max-h-60 overflow-y-auto rounded border p-2">
                                {inTreeOrder(
                                    page.termTrees[taxonomy.key] ?? [],
                                ).map(({ term, depth }) => {
                                    const state = stateOf(
                                        taxonomy.key,
                                        term.id,
                                    );

                                    return (
                                        <li
                                            key={term.id}
                                            className="flex items-center gap-2 py-1"
                                            style={{
                                                paddingLeft: depth * 16,
                                            }}
                                        >
                                            <Checkbox
                                                id={`${id}-${term.id}`}
                                                disabled={working}
                                                checked={
                                                    state === 'mixed'
                                                        ? 'indeterminate'
                                                        : state === 'on'
                                                }
                                                onCheckedChange={() =>
                                                    cycle(taxonomy.key, term.id)
                                                }
                                            />
                                            <Label
                                                htmlFor={`${id}-${term.id}`}
                                                className="text-[13px] font-normal"
                                            >
                                                {term.name}
                                            </Label>
                                        </li>
                                    );
                                })}
                            </ul>
                            <p className="text-muted-foreground text-xs">
                                A dash means some of them have it. Click to add
                                it to all, again to remove it from all, and
                                again to leave each as it was.
                            </p>
                        </fieldset>
                    ))}

                    <div className="grid content-start gap-4">
                        {flat.map((taxonomy) => (
                            <div key={taxonomy.key} className="grid gap-1">
                                <TermPicker
                                    taxonomy={taxonomy.key}
                                    singular={taxonomy.singular}
                                    label={taxonomy.plural}
                                    value={added[taxonomy.key] ?? []}
                                    onChange={(value) =>
                                        setAdded((current) => ({
                                            ...current,
                                            [taxonomy.key]: value,
                                        }))
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    Added to every item. The ones they have
                                    stay.
                                </p>
                            </div>
                        ))}

                        {page.can.changeAuthor && (
                            <div className="grid gap-2">
                                <Label htmlFor={`${id}-author`}>Author</Label>
                                <SelectField
                                    id={`${id}-author`}
                                    value={author}
                                    onValueChange={setAuthor}
                                    emptyLabel="— No change —"
                                    options={page.authors}
                                />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-status`}>Status</Label>
                            <SelectField
                                id={`${id}-status`}
                                value={status}
                                onValueChange={setStatus}
                                emptyLabel="— No change —"
                                options={[
                                    { value: 'draft', label: 'Draft' },
                                    ...(page.can.publish
                                        ? [
                                              {
                                                  value: 'published',
                                                  label: 'Published',
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </div>

                        {page.takesComments && page.can.publish && (
                            <div className="grid gap-2">
                                <Label htmlFor={`${id}-discussion`}>
                                    Discussion
                                </Label>
                                <SelectField
                                    id={`${id}-discussion`}
                                    value={discussion}
                                    onValueChange={setDiscussion}
                                    emptyLabel="— No change —"
                                    options={DISCUSSION_CHOICES.map(
                                        (choice) => ({
                                            value: choice.value,
                                            label: choice.label,
                                        }),
                                    )}
                                />
                            </div>
                        )}
                    </div>
                </div>

                <DialogFooter className="items-center">
                    <p role="status" className="text-muted-foreground text-xs">
                        {working && `Updating ${sent} of ${items.length}…`}
                    </p>
                    <span className="flex-1" />
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={working}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={working}
                        onClick={() => void apply()}
                    >
                        Apply to {items.length}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
