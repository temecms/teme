import { useHttp } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
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

/**
 * A flat taxonomy's terms, found by typing and chosen on the shared Combobox.
 */
export function TermPicker({
    taxonomy,
    singular,
    label,
    labelHidden = false,
    value,
    onChange,
}: Props) {
    const { submit } = useHttp();
    const id = useId();
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
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

    const options: ComboboxOption[] = results.map((term) => ({
        value: term.id,
        label: term.name,
        description: term.path !== term.name ? term.path : undefined,
    }));

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
                onValueChange={(next) =>
                    onChange(
                        next.map((option) => ({
                            id: option.value,
                            name: option.label,
                        })),
                    )
                }
                search={{ value: query, onChange: setQuery, loading }}
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
            />
        </div>
    );
}
