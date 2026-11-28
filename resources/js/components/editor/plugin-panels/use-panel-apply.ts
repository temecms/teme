import { http } from '@inertiajs/react';
import type { Editor } from '@tiptap/core';
import { useMemo, useRef } from 'react';
import type { Dispatch, SetStateAction } from 'react';
import TermController from '@/actions/App/Http/Admin/Taxonomy/TermController';
import type { ChosenTerm } from '@/components/editor/rail/taxonomy-panel';
import type { EditorSession } from '@/types';
import type { PanelApply } from './panel-suggestions';

type FieldValue = string | number | boolean | null;

/**
 * What the plugins' panels may change in the open draft, as the person
 * would: the title, a field, the terms, and the document through the
 * editor. Read through refs, so a pane opened earlier still changes what
 * is there now, and each change hands back its undo.
 */
export function usePanelApply({
    editor,
    title,
    setTitle,
    values,
    setValues,
    chosenTerms,
    setChosenTerms,
    taxonomies,
    checkpoint,
    markStale,
    unsaved,
}: {
    unsaved: boolean;
    editor: Editor | null;
    title: string;
    setTitle: Dispatch<SetStateAction<string>>;
    values: Record<string, unknown>;
    setValues: Dispatch<SetStateAction<Record<string, unknown>>>;
    chosenTerms: ChosenTerm[];
    setChosenTerms: Dispatch<SetStateAction<ChosenTerm[]>>;
    taxonomies: EditorSession['taxonomies'];
    checkpoint: () => Promise<string | null>;
    markStale: (answer: Record<string, unknown>) => void;
}): PanelApply {
    const latest = useRef({
        editor,
        title,
        values,
        chosenTerms,
        taxonomies,
        checkpoint,
        markStale,
    });
    latest.current = {
        editor,
        title,
        values,
        chosenTerms,
        taxonomies,
        checkpoint,
        markStale,
    };

    return useMemo<PanelApply>(
        () => ({
            unsaved,
            get editor() {
                return latest.current.editor;
            },
            checkpoint: () => latest.current.checkpoint(),
            markStale: (answer) => latest.current.markStale(answer),
            title: (next) => {
                const before = latest.current.title;
                setTitle(next);

                return () => setTitle(before);
            },
            field: (key, value: FieldValue) => {
                const before = latest.current.values[key];
                setValues((was) => ({ ...was, [key]: value }));

                return () => setValues((was) => ({ ...was, [key]: before }));
            },
            terms: async (change) => {
                const taxonomy = latest.current.taxonomies.find(
                    (each) => each.key === change.taxonomy,
                );

                if (
                    taxonomy === undefined ||
                    (change.create.length > 0 && !taxonomy.canCreate)
                ) {
                    return null;
                }

                const added: ChosenTerm[] = [...change.terms];

                // A term that doesn't exist yet is made as the term picker makes it, by the person's own rights.
                for (const name of change.create) {
                    try {
                        const response = await http.getClient().request({
                            method: 'post',
                            url: TermController.store(taxonomy.segment).url,
                            data: { name },
                            headers: { Accept: 'application/json' },
                        });
                        const term = JSON.parse(response.data) as {
                            id: string;
                            name: string;
                        };

                        added.push({
                            id: term.id,
                            name: term.name,
                            taxonomy: taxonomy.key,
                        });
                    } catch {
                        return null;
                    }
                }

                const before = latest.current.chosenTerms;
                setChosenTerms((was) => [
                    ...was,
                    ...added.filter(
                        (term) => !was.some((chosen) => chosen.id === term.id),
                    ),
                ]);

                return () => setChosenTerms(before);
            },
        }),
        [setTitle, setValues, setChosenTerms, unsaved],
    );
}
