import { http, useHttp } from '@inertiajs/react';
import { useId, useState } from 'react';
import TermController from '@/actions/App/Http/Admin/Taxonomy/TermController';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { search as searchTerms } from '@/routes/terms';
import type { ChosenTerm } from './taxonomy-panel';

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

/**
 * A flat taxonomy in the rail (docs/architecture/design-system.md, 4.39):
 * its chosen terms as removable flat pills over "Add a tag and press Enter".
 * Enter takes the term of that name, or makes it for someone who may.
 */
export function TagInput({
    taxonomy,
    label,
    singular,
    segment,
    canCreate,
    value,
    onChange,
}: {
    taxonomy: string;
    /** The taxonomy's plural name: "Tags". */
    label: string;
    singular: string;
    segment: string;
    canCreate: boolean;
    value: ChosenTerm[];
    onChange: (next: ChosenTerm[]) => void;
}) {
    const id = useId();
    const { submit } = useHttp();
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const add = async () => {
        const name = typed.trim();
        const same = (other: string) =>
            other.trim().toLocaleLowerCase() === name.toLocaleLowerCase();

        if (name === '' || busy) {
            return;
        }

        if (value.some((term) => same(term.name))) {
            setTyped('');

            return;
        }

        setBusy(true);
        setProblem(null);

        try {
            const found = (await submit(
                searchTerms({ query: { taxonomy, q: name } }),
            )) as { id: string; name: string }[];
            const match = found.find((term) => same(term.name));

            if (match !== undefined) {
                onChange([...value, { ...match, taxonomy }]);
                setTyped('');

                return;
            }

            if (!canCreate) {
                setProblem(
                    __('There is no :name “:typed”.', {
                        name: singular.toLowerCase(),
                        typed: name,
                    }),
                );

                return;
            }

            const response = await http.getClient().request({
                method: 'post',
                url: TermController.store(segment).url,
                data: { name },
                headers: { Accept: 'application/json' },
            });
            const term = JSON.parse(response.data) as {
                id: string;
                name: string;
            };

            onChange([...value, { id: term.id, name: term.name, taxonomy }]);
            setTyped('');
        } catch (error) {
            setProblem(
                serverMessage(error) ??
                    __(
                        'Couldn’t add it. Check that the name isn’t already used.',
                    ),
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="grid gap-2" data-test={`rail-terms-${taxonomy}`}>
            <Label htmlFor={id}>{label}</Label>
            {value.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {value.map((term) => (
                        <StatusPill
                            key={term.id}
                            tone="neutral"
                            flat
                            onRemove={() =>
                                onChange(
                                    value.filter(
                                        (other) => other.id !== term.id,
                                    ),
                                )
                            }
                            removeLabel={__('Remove the :name :term', {
                                name: singular.toLowerCase(),
                                term: term.name,
                            })}
                        >
                            {term.name}
                        </StatusPill>
                    ))}
                </div>
            )}
            <Input
                id={id}
                value={typed}
                autoComplete="off"
                disabled={busy}
                placeholder={__('Add a :name and press Enter', {
                    name: singular.toLowerCase(),
                })}
                aria-invalid={problem !== null ? true : undefined}
                aria-describedby={
                    problem !== null ? `${id}-problem` : undefined
                }
                onChange={(event) => {
                    setTyped(event.target.value);
                    setProblem(null);
                }}
                onKeyDown={(event) => {
                    event.stopPropagation();

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        void add();
                    }
                }}
                className="h-8"
            />
            {problem !== null && (
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
