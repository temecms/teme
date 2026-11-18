import type { ReactNodeViewProps } from '@tiptap/react';
import { ArrowDown, ArrowUp, Plus, X } from 'lucide-react';
import { useEffect, useId } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import { ulid } from '@/lib/ulid';
import { CardFrame } from './card-frame';
import { blankProse, CardProse, proseText } from './card-prose';
import type { CardSettingsProps } from './card-environment';

/** The most questions one card holds, as FaqCard::MOST_ITEMS says. */
const MOST_ITEMS = 50;

/** The longest question, as FaqCard::MOST_QUESTION says. */
const MOST_QUESTION = 300;

type FaqItem = { id: string; question: string; answer: unknown };

/** The card's questions as far as they can be read. */
function itemsOf(value: unknown): FaqItem[] {
    return Array.isArray(value)
        ? value.flatMap((item: unknown) =>
              item !== null && typeof item === 'object'
                  ? [
                        {
                            id: String((item as FaqItem).id ?? ''),
                            question:
                                typeof (item as FaqItem).question === 'string'
                                    ? (item as FaqItem).question
                                    : '',
                            answer: (item as FaqItem).answer ?? blankProse(),
                        },
                    ]
                  : [],
          )
        : [];
}

/** A new, empty question. */
function newItem(): FaqItem {
    return { id: ulid(), question: '', answer: blankProse() };
}

/** Moves the entry at `from` to `to` in a copy of the list. */
export function moved<T>(list: T[], from: number, to: number): T[] {
    const next = [...list];
    const [entry] = next.splice(from, 1);

    next.splice(to, 0, entry);

    return next;
}

/** One entry's tools: up, down and remove, each naming the entry. */
export function EntryTools({
    name,
    index,
    count,
    onMove,
    onRemove,
}: {
    name: string;
    index: number;
    count: number;
    onMove: (to: number) => void;
    onRemove: () => void;
}) {
    return (
        <div className="flex shrink-0 items-center gap-0.5">
            <Button
                type="button"
                variant="ghost"
                size="icon-xs"
                aria-label={__('Move :name up', { name })}
                disabled={index === 0}
                onClick={() => onMove(index - 1)}
            >
                <ArrowUp />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon-xs"
                aria-label={__('Move :name down', { name })}
                disabled={index === count - 1}
                onClick={() => onMove(index + 1)}
            >
                <ArrowDown />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon-xs"
                aria-label={__('Remove :name', { name })}
                disabled={count === 1}
                onClick={onRemove}
            >
                <X />
            </Button>
        </div>
    );
}

/** The button under a card's list that adds one more entry. */
export function AddEntry({
    label,
    disabled,
    onAdd,
}: {
    label: string;
    disabled: boolean;
    onAdd: () => void;
}): ReactNode {
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className="justify-self-start"
            disabled={disabled}
            onClick={onAdd}
        >
            <Plus />
            {label}
        </Button>
    );
}

/**
 * An FAQ card: its questions and answers written in place, each answer
 * short rich text. A new card starts with one empty question.
 */
export function FaqCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const items = itemsOf(node.attrs.items);
    const baseId = useId();
    const unfinished = items.some(
        (item) => item.question.trim() === '' || proseText(item.answer) === '',
    );

    // A new card, with no questions yet, starts with one to write.
    useEffect(() => {
        if (itemsOf(node.attrs.items).length === 0) {
            updateAttributes({ items: [newItem()] });
        }
    }, [node.attrs.items, updateAttributes]);

    const write = (next: FaqItem[]) => updateAttributes({ items: next });
    const change = (index: number, patch: Partial<FaqItem>) =>
        write(
            items.map((item, at) =>
                at === index ? { ...item, ...patch } : item,
            ),
        );

    return (
        <CardFrame
            label={__('FAQ')}
            selected={selected}
            warning={
                unfinished
                    ? { label: __('Needs a question and its answer') }
                    : null
            }
            onRemove={deleteNode}
            preview={items[0]?.question ?? ''}
            settings={
                <FaqSettings attrs={node.attrs} update={updateAttributes} />
            }
        >
            <div className="grid gap-4 px-3 pb-3">
                <ol className="grid gap-4">
                    {items.map((item, index) => {
                        const questionId = `${baseId}-${item.id}`;
                        const name =
                            item.question.trim() === ''
                                ? __('question :number', { number: index + 1 })
                                : item.question;

                        return (
                            <li
                                key={item.id}
                                className="border-line grid gap-2 border-b pb-4 last:border-b-0 last:pb-0"
                            >
                                <div className="flex items-center gap-2">
                                    <label
                                        htmlFor={questionId}
                                        className="sr-only"
                                    >
                                        {__('Question :number', {
                                            number: index + 1,
                                        })}
                                    </label>
                                    <Input
                                        id={questionId}
                                        value={item.question}
                                        maxLength={MOST_QUESTION}
                                        placeholder={__('The question')}
                                        className="font-semibold"
                                        onChange={(event) =>
                                            change(index, {
                                                question: event.target.value,
                                            })
                                        }
                                    />
                                    <EntryTools
                                        name={name}
                                        index={index}
                                        count={items.length}
                                        onMove={(to) =>
                                            write(moved(items, index, to))
                                        }
                                        onRemove={() =>
                                            write(
                                                items.filter(
                                                    (_, at) => at !== index,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                                <CardProse
                                    value={item.answer}
                                    label={__('Answer :number', {
                                        number: index + 1,
                                    })}
                                    placeholder={__('Its answer')}
                                    onChange={(answer) =>
                                        change(index, { answer })
                                    }
                                />
                            </li>
                        );
                    })}
                </ol>
                <AddEntry
                    label={__('Add a question')}
                    disabled={items.length >= MOST_ITEMS}
                    onAdd={() => write([...items, newItem()])}
                />
            </div>
        </CardFrame>
    );
}

/** An FAQ card's panel: what decides how its questions are drawn. */
export function FaqSettings(_props: CardSettingsProps) {
    return (
        <p className="text-small text-ink-2">
            {__(
                'On the page each question opens its answer. Questions take the heading level below the heading before this card.',
            )}
        </p>
    );
}
