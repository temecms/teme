import type { ReactNode } from 'react';
import { TermPicker } from '@/components/term-picker';
import { TermTreePicker } from '@/components/term-tree-picker';
import type { EditorSession } from '@/types';
import { RailSection } from './rail-section';

export type ChosenTerm = { id: string; name: string; taxonomy: string };

/**
 * One picker per taxonomy of the article's type, in the type's order: the
 * tree for a hierarchical taxonomy, the tag input for a flat one. It never
 * names a taxonomy, and a type with none draws nothing. The primary category
 * sits under the first hierarchical taxonomy's tree, the one it is chosen from.
 */
export function TaxonomyPanel({
    taxonomies,
    chosen,
    onChange,
    canAdd,
    primaryCategory,
}: {
    taxonomies: EditorSession['taxonomies'];
    chosen: ChosenTerm[];
    onChange: (next: ChosenTerm[]) => void;
    canAdd: boolean;
    primaryCategory?: ReactNode;
}) {
    if (taxonomies.length === 0) {
        return null;
    }

    const first = taxonomies.find((taxonomy) => taxonomy.hierarchical)?.key;

    // Replaces one taxonomy's choices and keeps the rest in their order.
    const replace = (taxonomy: string, next: ChosenTerm[]) =>
        onChange([
            ...chosen.filter((term) => term.taxonomy !== taxonomy),
            ...next,
        ]);

    return (
        <>
            {taxonomies.map((taxonomy) => {
                const mine = chosen.filter(
                    (term) => term.taxonomy === taxonomy.key,
                );

                return (
                    <RailSection key={taxonomy.key} title={taxonomy.plural}>
                        {taxonomy.hierarchical && taxonomy.terms !== null ? (
                            <TermTreePicker
                                label={taxonomy.plural}
                                segment={taxonomy.segment}
                                terms={taxonomy.terms}
                                value={mine.map((term) => term.id)}
                                canAdd={canAdd}
                                onChange={(ids) =>
                                    replace(
                                        taxonomy.key,
                                        ids.map((id) => ({
                                            id,
                                            name:
                                                taxonomy.terms?.find(
                                                    (term) => term.id === id,
                                                )?.name ??
                                                mine.find(
                                                    (term) => term.id === id,
                                                )?.name ??
                                                '',
                                            taxonomy: taxonomy.key,
                                        })),
                                    )
                                }
                            />
                        ) : (
                            <TermPicker
                                taxonomy={taxonomy.key}
                                singular={taxonomy.singular}
                                label={taxonomy.plural}
                                labelHidden
                                canCreate={taxonomy.canCreate}
                                segment={taxonomy.segment}
                                value={mine}
                                onChange={(next) =>
                                    replace(
                                        taxonomy.key,
                                        next.map((term) => ({
                                            id: term.id,
                                            name: term.name,
                                            taxonomy: taxonomy.key,
                                        })),
                                    )
                                }
                            />
                        )}
                        {taxonomy.key === first && primaryCategory}
                    </RailSection>
                );
            })}
        </>
    );
}
