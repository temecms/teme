import { http, useHttp } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import TermController from '@/actions/App/Http/Admin/Taxonomy/TermController';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { search as searchTerms } from '@/routes/terms';
import type { TermChip } from '@/types';

/** The value of the one option that creates a tag rather than choosing one. */
const CREATE = 'create-term';

type Props = {
    /** The taxonomy's key, such as category. */
    taxonomy: string;
    /** The taxonomy's singular name, as its registry gives it. */
    singular: string;
    label: string;
    /** Where a heading already names the taxonomy, as in the editor's rail. */
    labelHidden?: boolean;
    value: TermChip[];
    onChange: (value: TermChip[]) => void;
    /** Whether the server lets this person create terms in the taxonomy (docs/proposals/combobox.md, Settled 1). */
    canCreate?: boolean;
    /** The taxonomy's admin segment, where a new term is posted. */
    segment?: string;
};

/**
 * A flat taxonomy's terms, found by typing and chosen on the shared Combobox;
 * someone who may create terms can add a new one by its name.
 */
export function TermPicker({
    taxonomy,
    singular,
    label,
    labelHidden = false,
    value,
    onChange,
    canCreate = false,
    segment,
}: Props) {
    const { submit } = useHttp();
    const id = useId();
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [results, setResults] = useState<
        { id: string; name: string; path: string }[]
    >([]);
    const latest = useRef(0);

    useEffect(() => {
        const asked = ++latest.current;

        if (query.trim() === '') {
            setResults([]);
            setLoading(false);

            return;
        }

        setLoading(true);

        // The previous request is superseded: only the latest one's answer is kept.
        const timer = setTimeout(async () => {
            try {
                const found = (await submit(
                    searchTerms({ query: { taxonomy, q: query } }),
                )) as { id: string; name: string; path: string }[];

                if (asked === latest.current) {
                    setResults(found);
                }
            } finally {
                if (asked === latest.current) {
                    setLoading(false);
                }
            }
        }, 200);

        return () => clearTimeout(timer);
    }, [query, taxonomy, submit]);

    const typed = query.trim();
    const sameName = (name: string) =>
        name.trim().toLocaleLowerCase() === typed.toLocaleLowerCase();
    const offersCreate =
        canCreate &&
        segment !== undefined &&
        typed !== '' &&
        !loading &&
        !results.some((term) => sameName(term.name)) &&
        !value.some((term) => sameName(term.name));

    const options: ComboboxOption[] = [
        ...results.map((term) => ({
            value: term.id,
            label: term.name,
            description: term.path !== term.name ? term.path : undefined,
        })),
        ...(offersCreate
            ? [
                  {
                      value: CREATE,
                      label: __('Create “:name”', { name: typed }),
                      icon: PlusIcon,
                      closes: true,
                  },
              ]
            : []),
    ];

    const create = async () => {
        setProblem(null);

        try {
            const response = await http.getClient().request({
                method: 'post',
                url: TermController.store(segment as string).url,
                data: { name: typed },
                headers: { Accept: 'application/json' },
            });
            const term = JSON.parse(response.data) as {
                id: string;
                name: string;
            };

            onChange([...value, { id: term.id, name: term.name }]);
            setQuery('');
        } catch (error) {
            setProblem(
                serverMessage(error) ??
                    __(
                        'Couldn’t create it. Check that the name isn’t already used.',
                    ),
            );
        }
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor={id} className={cn(labelHidden && 'sr-only')}>
                {label}
            </Label>
            <Combobox
                id={id}
                multiple
                name="terms[]"
                options={options}
                value={value.map((term) => ({
                    value: term.id,
                    label: term.name,
                }))}
                onValueChange={(next) => {
                    if (next.some((option) => option.value === CREATE)) {
                        void create();

                        return;
                    }

                    setProblem(null);
                    onChange(
                        next.map((option) => ({
                            id: option.value,
                            name: option.label,
                        })),
                    );
                }}
                search={{
                    value: query,
                    onChange: (next) => {
                        // Loading from the keystroke on, so Enter never takes the last search's answer.
                        setQuery(next);
                        setLoading(next.trim() !== '');
                    },
                    loading,
                }}
                triggerLabel={__('Find a :name', {
                    name: singular.toLowerCase(),
                })}
                placeholder={__('Find a :name', {
                    name: singular.toLowerCase(),
                })}
                searchPlaceholder={__('Search :plural', {
                    plural: label.toLowerCase(),
                })}
                removeLabel={(option) =>
                    __('Remove :name', { name: option.label })
                }
                aria-describedby={problem ? `${id}-problem` : undefined}
                aria-invalid={problem ? true : undefined}
            />
            {problem && (
                <p
                    id={`${id}-problem`}
                    role="alert"
                    className="text-small text-danger-ink"
                >
                    {problem}
                </p>
            )}
        </div>
    );
}

/** The first message a refused request's answer carries, if it carries one. */
function serverMessage(error: unknown): string | null {
    const data = (error as { response?: { data?: unknown } }).response?.data;

    try {
        const body = (typeof data === 'string' ? JSON.parse(data) : data) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        return Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? null;
    } catch {
        return null;
    }
}
