import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { search as searchTerms } from '@/routes/terms';
import type { TermChip } from '@/types';

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
};

export function TermPicker({
    taxonomy,
    singular,
    label,
    labelHidden = false,
    value,
    onChange,
}: Props) {
    const { submit } = useHttp();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<
        { id: string; name: string; path: string }[]
    >([]);

    useEffect(() => {
        if (query.trim() === '') {
            setResults([]);

            return;
        }

        const timer = setTimeout(async () => {
            const found = (await submit(
                searchTerms({ query: { taxonomy, q: query } }),
            )) as {
                id: string;
                name: string;
                path: string;
            }[];
            setResults(
                found.filter(
                    (term) => !value.some((chosen) => chosen.id === term.id),
                ),
            );
        }, 200);

        return () => clearTimeout(timer);
    }, [query, taxonomy, value, submit]);

    return (
        <div className="grid gap-2">
            <span className={labelHidden ? 'sr-only' : 'text-sm font-medium'}>
                {label}
            </span>
            <div className="flex flex-wrap gap-1">
                {value.map((term) => (
                    <span
                        key={term.id}
                        className="bg-surface-2 flex items-center gap-1 rounded px-2 py-0.5 text-xs"
                    >
                        {term.name}
                        <button
                            type="button"
                            aria-label={`Remove ${term.name}`}
                            onClick={() =>
                                onChange(
                                    value.filter(
                                        (chosen) => chosen.id !== term.id,
                                    ),
                                )
                            }
                        >
                            ×
                        </button>
                        <input type="hidden" name="terms[]" value={term.id} />
                    </span>
                ))}
            </div>
            <Input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={`Find a ${singular.toLowerCase()}`}
                aria-label={label}
            />
            {results.length > 0 && (
                <ul className="rounded border text-sm">
                    {results.map((term) => (
                        <li key={term.id}>
                            <button
                                type="button"
                                className="hover:bg-surface-2 w-full px-2 py-1 text-left"
                                onClick={() => {
                                    onChange([
                                        ...value,
                                        { id: term.id, name: term.name },
                                    ]);
                                    setQuery('');
                                }}
                            >
                                {term.path}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
